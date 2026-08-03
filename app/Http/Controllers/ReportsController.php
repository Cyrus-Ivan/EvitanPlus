<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\NormalizesDateFilter;
use App\Http\Controllers\Concerns\OffersBranchScope;
use App\Models\Instance;
use App\Support\Reports\SalesFigures;
use App\Support\Settings\BusinessSettings;
use App\Support\Sync\BranchCycle;
use App\Support\Sync\BranchRoster;
use App\Support\Sync\OnlineRead;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Business reporting (Phase 12; consolidated cross-branch in M11). Read-only.
 * Daily / Weekly / Monthly / Yearly sales reports that live on one page,
 * switched by an in-page `period` toggle.
 *
 * Each period reports on a SINGLE selected period (one day / week / month /
 * year), resolved from an anchor `date` into a `[from, to]` window. Within that
 * window the chart bars and table rows drill DOWN one level — a day breaks into
 * hourly buckets, a week/month into daily buckets, a year into monthly buckets.
 * The user steps between periods with the prev/next anchors returned in
 * `filters`.
 *
 * Everything is RECOMPUTED from the append-only source tables by
 * {@see SalesFigures} — never a stored total. Net sales = sales − returns −
 * defects, per `CLAUDE.md` → "Reporting".
 *
 * ## The `scope` parameter (M11)
 *
 * One screen, two databases:
 *
 *  - **`local`** (the default, and the only value an Admin may use) — the local
 *    SQLite mirror, which holds only THIS branch's sales. Works offline, which
 *    is the whole point: the till reports on itself with no connection at all.
 *  - **`all`** or a **branch id** — the business database, read live through
 *    {@see OnlineRead} and mirrored nowhere. Owner-only and online-only.
 *
 * Consolidated figures cannot come from the mirror because they are not in it:
 * `Puller::TABLES` deliberately does not carry `transactions` or `sale_returns`,
 * so a branch holds no other branch's sales and never will. Cross-branch totals
 * exist only upstream, which is why this is a read and not a pull.
 *
 * When the online read fails, the screen falls back to the branch-local report
 * and SAYS SO (`consolidatedOnline: false`, scope snapped back to `local`).
 * Rendering one branch's numbers under an "All branches" heading is the one
 * outcome that would be worse than an error.
 *
 * There is no version gate on the way in. {@see OnlineRead} omits it
 * deliberately: the gate stops a stale build WRITING, and refusing to render a
 * read would take away the display of the very problem.
 *
 * Access is Admin+ (route-level `role:admin`); the consolidated scope is
 * Owner-only, authorized here rather than by route placement. Cost / COGS /
 * profit are Owner-only everywhere including the consolidated view: the keys are
 * omitted entirely from the payload for non-Owners, mirroring SalesController::show.
 */
class ReportsController extends Controller
{
    use NormalizesDateFilter, OffersBranchScope;

    /** The branch-local report: SQLite, this branch, works offline. */
    private const SCOPE_LOCAL = 'local';

    /** The consolidated report: Postgres, every branch. */
    private const SCOPE_ALL = 'all';

    /**
     * The set of valid period keys, and the sub-bucket's granularity noun used
     * as the breakdown table's first column header. The bucket FORMATS live in
     * {@see SalesFigures}, which is the only thing that has to know how a given
     * database spells a date.
     */
    private const SUB_NOUNS = [
        'daily' => 'Hour',
        'weekly' => 'Day',
        'monthly' => 'Day',
        'yearly' => 'Month',
    ];

    public function __construct(private readonly OnlineRead $read) {}

    public function index(Request $request): Response
    {
        $period = $this->normalizePeriod($request->query('period'));
        [$from, $to] = $this->resolveWindow($period, $this->normalizeDate($request->query('date')));
        $canViewCost = Gate::allows('view-item-cost');
        $scope = $this->normalizeScope($request->query('scope'));
        $chartBy = $this->normalizeChartBy($request->query('by'));

        $report = $scope === self::SCOPE_LOCAL
            ? $this->branchLocal($period, $from, $to, $canViewCost)
            : $this->consolidated($scope, $chartBy, $period, $from, $to, $canViewCost);

        // Null means the local path ran and no roster came back with it, so the
        // picker's list is read on its own. Anything else — including the empty
        // list a failed consolidated read leaves — already cost one online read
        // this request and is reused rather than attempted again.
        $roster = $report['consolidatedOnline'] === null ? null : $report['branches'];

        return Inertia::render('reports', [
            'period' => $period,
            'rows' => $report['rows'],
            'totals' => $report['totals'],
            'chartItems' => $report['chartItems'],
            'chart' => $report['chart'],
            'canViewCost' => $canViewCost,
            'filters' => $this->filters($period, $from),
            // M11. `scope` is echoed back AFTER normalisation and after any
            // fallback, so the control always shows what was actually reported
            // on rather than what was asked for.
            'scope' => $report['scope'],
            'chartBy' => $report['chartBy'],
            // Every branch but this one: the picker's first entry is already
            // this branch, as `local` (see OffersBranchScope).
            'branches' => $this->pickerBranches(
                $this->read,
                $roster,
                Gate::allows('view-consolidated-reports') && Instance::isHydrated(),
            ),
            'branchRows' => $report['branchRows'],
            'consolidatedOnline' => $report['consolidatedOnline'],
        ]);
    }

    /**
     * Render the selected period's report as a downloadable PDF (dompdf, pure
     * PHP — no browser binary, safe for the offline NativePHP build). Reuses the
     * exact same recompute path as {@see self::index}, scope and all, so the PDF
     * matches the on-screen totals. Cost / COGS / profit stay Owner-only: the
     * flag is passed to the view, which omits those columns entirely for
     * non-Owners.
     *
     * The PDF carries a net-sales bar chart (one diverging bar per sub-bucket,
     * built from HTML divs — dompdf's inline-SVG support is unreliable), the
     * summary totals, the breakdown table, a per-branch table when the report is
     * consolidated, and a "Top selling items" table for the whole selected range
     * (cost-free, so safe for all Admin+ viewers).
     */
    public function export(Request $request, BusinessSettings $settings): HttpResponse
    {
        $period = $this->normalizePeriod($request->query('period'));
        [$from, $to] = $this->resolveWindow($period, $this->normalizeDate($request->query('date')));
        $canViewCost = Gate::allows('view-item-cost');
        $scope = $this->normalizeScope($request->query('scope'));
        $chartBy = $this->normalizeChartBy($request->query('by'));

        $report = $scope === self::SCOPE_LOCAL
            ? $this->branchLocal($period, $from, $to, $canViewCost, topItems: true)
            : $this->consolidated($scope, $chartBy, $period, $from, $to, $canViewCost, topItems: true);

        $rows = array_map(function (array $row) use ($period): array {
            $row['label'] = $this->formatBucketLabel($period, $row['bucket']);

            return $row;
        }, $report['rows']);

        $pdf = Pdf::loadView('reports.pdf', [
            'business' => $settings->all(),
            'period' => $period,
            'periodNoun' => self::SUB_NOUNS[$period],
            'label' => $this->periodLabel($period, $from),
            'generatedAt' => Carbon::now(),
            'rows' => $rows,
            'totals' => $report['totals'],
            'topItems' => $report['topItems'],
            'canViewCost' => $canViewCost,
            // M11: which branches these figures cover, named in the header, and
            // broken out in their own table when there is more than one.
            'scopeLabel' => $this->scopeLabel($report['scope'], $report['branches']),
            'branchRows' => $report['branchRows'],
        ]);

        $filename = 'sales-report-'.$this->scopeSlug($report['scope'], $report['branches'])
            .$period.'-'.$from->toDateString().'-'.Carbon::now()->format('Ymd-His').'.pdf';

        return $pdf->download($filename);
    }

    /**
     * The branch-local report, recomputed from this device's SQLite mirror.
     * Unchanged since Phase 12 and deliberately so — it is what the till falls
     * back to, and what every non-Owner sees.
     *
     * @return array<string, mixed>
     */
    private function branchLocal(string $period, Carbon $from, Carbon $to, bool $canViewCost, bool $topItems = false): array
    {
        $figures = new SalesFigures(DB::connection());
        $rows = $figures->rows('bucket', $period, $from, $to, $canViewCost);
        $chart = $figures->chart('item', $period, $from, $to);

        return [
            'scope' => self::SCOPE_LOCAL,
            'chartBy' => 'item',
            'rows' => $rows,
            'totals' => $figures->totals($rows, $canViewCost),
            'chartItems' => $chart['items'],
            'chart' => $chart['buckets'],
            'branches' => [],
            'branchRows' => null,
            // Null, not false: nothing consolidated was asked for, so there is
            // nothing to report as unavailable.
            'consolidatedOnline' => null,
            'topItems' => $topItems ? $figures->topItems($from, $to) : [],
        ];
    }

    /**
     * The consolidated report, recomputed live from the business database.
     *
     * One online read does all of it — the roster, the per-bucket rows, the
     * per-branch rows and the chart share a single connection, opened and
     * released by {@see BranchCycle} around this closure. Two
     * reads would hold two Supavisor client slots for one screen.
     *
     * @param  string  $scope  'all', or a branch id.
     * @param  'item'|'branch'  $chartBy
     * @return array<string, mixed>
     */
    private function consolidated(string $scope, string $chartBy, string $period, Carbon $from, Carbon $to, bool $canViewCost, bool $topItems = false): array
    {
        $requested = $scope === self::SCOPE_ALL ? null : $scope;

        $data = rescue(fn (): array => $this->read->run(
            function (Connection $remote) use ($requested, $chartBy, $period, $from, $to, $canViewCost, $topItems): array {
                // Disabled branches included: a disabled branch's past sales are
                // still the business's, and hiding them would make the
                // consolidated total disagree with the sum of its rows.
                $branches = BranchRoster::read($remote);

                // A branch id that is no longer in the roster means a stale link
                // or a hand-typed one. Report the whole business rather than an
                // empty screen, and let the echoed-back scope say what happened.
                $branchId = $requested !== null && isset($branches[$requested]) ? $requested : null;

                $figures = new SalesFigures($remote, $branchId);
                $rows = $figures->rows('bucket', $period, $from, $to, $canViewCost);
                $chart = $figures->chart(
                    $chartBy,
                    $period,
                    $from,
                    $to,
                    // Branch series are named from the roster rather than by
                    // joining `branches` into every aggregate.
                    array_map(fn (array $branch): string => $branch['code'], $branches),
                );

                return [
                    'scope' => $branchId ?? self::SCOPE_ALL,
                    'chartBy' => $chartBy,
                    'rows' => $rows,
                    'totals' => $figures->totals($rows, $canViewCost),
                    'chartItems' => $chart['items'],
                    'chart' => $chart['buckets'],
                    'branches' => array_values($branches),
                    'branchRows' => $this->describeBranchRows(
                        $figures->rows('branch', $period, $from, $to, $canViewCost),
                        $branchId === null ? $branches : [$branchId => $branches[$branchId]],
                        $canViewCost,
                    ),
                    'consolidatedOnline' => true,
                    'topItems' => $topItems ? $figures->topItems($from, $to) : [],
                ];
            },
            'Consolidated reports',
        ), null, report: false);

        // Offline (or the credential could not be opened — `rescue` catches
        // Throwable, which matters because CredentialVault throws
        // SetupFailedException, not OfflineWriteException). Fall back to the
        // branch-local report and mark it, so the screen never presents one
        // branch's figures as the business's.
        if ($data === null) {
            return ['consolidatedOnline' => false] + $this->branchLocal($period, $from, $to, $canViewCost, $topItems);
        }

        return $data;
    }

    /**
     * Name the per-branch rows and zero-fill the branches that sold nothing.
     *
     * A branch missing from the table reads as "no data"; a branch showing zero
     * reads as "sold nothing", and only the second is a fact the Owner can act
     * on. Ordered by branch code, which is how the roster arrives.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, array{id: string, code: string, name: string}>  $branches
     * @return list<array<string, mixed>>
     */
    private function describeBranchRows(array $rows, array $branches, bool $canViewCost): array
    {
        $byBranch = [];
        foreach ($rows as $row) {
            $byBranch[$row['branch_id']] = $row;
        }

        $zero = [
            'transactions' => 0,
            'gross_sales' => '0.00',
            'returns' => '0.00',
            'defects' => '0.00',
            'net_sales' => '0.00',
        ] + ($canViewCost ? ['cogs' => '0.00', 'profit' => '0.00', 'uncosted_sales' => '0.00'] : []);

        $described = [];
        foreach ($branches as $id => $branch) {
            $described[] = [
                'branch_id' => $id,
                'code' => $branch['code'],
                'name' => $branch['name'],
            ] + ($byBranch[$id] ?? $zero);
        }

        return $described;
    }

    /** A human name for the reported scope, for the PDF header. */
    private function scopeLabel(string $scope, array $branches): string
    {
        if ($scope === self::SCOPE_LOCAL) {
            // A device that never hydrated has no branch name to print, and a
            // single-install Evitan POS report never had one either.
            $instance = Instance::current();

            return $instance === null ? 'This branch' : (string) $instance->branch_name;
        }

        if ($scope === self::SCOPE_ALL) {
            return 'All branches';
        }

        $branch = collect($branches)->firstWhere('id', $scope);

        return $branch === null ? 'One branch' : $branch['code'].' — '.$branch['name'];
    }

    /** The scope's slug for the export filename, empty for the branch-local report. */
    private function scopeSlug(string $scope, array $branches): string
    {
        if ($scope === self::SCOPE_LOCAL) {
            return '';
        }

        if ($scope === self::SCOPE_ALL) {
            return 'all-branches-';
        }

        $branch = collect($branches)->firstWhere('id', $scope);

        return $branch === null ? '' : Str::lower($branch['code']).'-';
    }

    /**
     * Snap an anchor date to the [start, end] bounds of the single selected
     * period. The anchor defaults to "now" when no (parseable) date is given.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveWindow(string $period, ?Carbon $anchor): array
    {
        $a = ($anchor ?? Carbon::now())->copy();

        return match ($period) {
            'weekly' => [$a->copy()->startOfWeek(), $a->copy()->endOfWeek()],
            'monthly' => [$a->copy()->startOfMonth(), $a->copy()->endOfMonth()],
            'yearly' => [$a->copy()->startOfYear(), $a->copy()->endOfYear()],
            default => [$a->copy()->startOfDay(), $a->copy()->endOfDay()],
        };
    }

    /**
     * The `filters` payload the frontend needs to render its selector: the
     * active period, the window-start anchor (what the date picker shows), the
     * prev/next anchors for the ‹ › steppers, and a human label for the period.
     *
     * @return array<string, string>
     */
    private function filters(string $period, Carbon $from): array
    {
        $step = fn (Carbon $d, int $sign): Carbon => match ($period) {
            'weekly' => $d->copy()->addWeeks($sign),
            'monthly' => $d->copy()->addMonths($sign),
            'yearly' => $d->copy()->addYears($sign),
            default => $d->copy()->addDays($sign),
        };

        return [
            'period' => $period,
            'date' => $from->toDateString(),
            'prev' => $step($from, -1)->toDateString(),
            'next' => $step($from, 1)->toDateString(),
            'label' => $this->periodLabel($period, $from),
        ];
    }

    /** A human-readable label for the selected period, e.g. "July 2026". */
    private function periodLabel(string $period, Carbon $from): string
    {
        return match ($period) {
            'weekly' => $from->format('M j').' – '.$from->copy()->endOfWeek()->format('M j, Y'),
            'monthly' => $from->format('F Y'),
            'yearly' => $from->format('Y'),
            default => $from->format('l, M j, Y'),
        };
    }

    /**
     * Human label for one sub-bucket key (the breakdown table's first column in
     * the PDF), mirroring the frontend's formatBucket. Daily buckets are
     * 'Y-m-d H' (hour), weekly/monthly 'Y-m-d' (day), yearly 'Y-m' (month).
     */
    private function formatBucketLabel(string $period, string $bucket): string
    {
        return match ($period) {
            'daily' => Carbon::createFromFormat('Y-m-d H', $bucket)->format('g:00 A'),
            'yearly' => Carbon::createFromFormat('Y-m', $bucket)->format('F Y'),
            default => Carbon::createFromFormat('Y-m-d', $bucket)->format('D, M j'),
        };
    }

    /**
     * Constrain the granularity to a known period, defaulting to daily.
     */
    private function normalizePeriod(mixed $value): string
    {
        $value = (string) $value;

        return array_key_exists($value, self::SUB_NOUNS) ? $value : 'daily';
    }

    /**
     * Constrain the requested scope, and authorize it.
     *
     * Anything other than `local` is the consolidated report, which is
     * Owner-only — refused HERE rather than by route placement, because the
     * route also serves the branch-local report an Admin is entitled to. An
     * Admin appending `?scope=all` gets a 403, never a silent downgrade to
     * figures that are not the ones they asked for.
     *
     * A device that has never hydrated has no business to consolidate: it does
     * not know which branch it is, and holds no credential to ask with.
     */
    private function normalizeScope(mixed $value): string
    {
        $scope = (string) $value;

        if ($scope === '' || $scope === self::SCOPE_LOCAL) {
            return self::SCOPE_LOCAL;
        }

        Gate::authorize('view-consolidated-reports');
        abort_unless(Instance::isHydrated(), 403, 'This device is not connected to a business database.');

        // This branch's own id is `local` under another name, and the picker
        // offers it only under that one. Snapped AFTER the checks above, so an
        // Admin naming a branch id still gets the 403 rather than a report that
        // is not the one they asked for.
        if ($this->isThisBranch($scope)) {
            return self::SCOPE_LOCAL;
        }

        return $scope === self::SCOPE_ALL || Str::isUuid($scope) ? $scope : self::SCOPE_ALL;
    }

    /**
     * The chart's series dimension. Defaults to `item` in every case, including
     * the consolidated view: one rule means switching scope never changes what
     * the chart means underfoot.
     */
    private function normalizeChartBy(mixed $value): string
    {
        return (string) $value === 'branch' ? 'branch' : 'item';
    }
}
