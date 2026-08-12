<?php

namespace App\Http\Controllers;

use App\Console\Commands\SendScheduledSalesReport;
use App\Http\Controllers\Concerns\NormalizesDateFilter;
use App\Http\Controllers\Concerns\OffersBranchScope;
use App\Http\Requests\Reports\EmailReportRequest;
use App\Mail\SalesReportMail;
use App\Models\Instance;
use App\Support\Audit\AuditRecorder;
use App\Support\Email\ReportEmailException;
use App\Support\Email\ReportEmailSettings;
use App\Support\Email\ReportMailer;
use App\Support\Reports\ReportPeriods;
use App\Support\Reports\SalesFigures;
use App\Support\Reports\SalesReportBuilder;
use App\Support\Reports\SalesReportPdf;
use App\Support\Settings\BusinessSettings;
use App\Support\Sync\BranchCycle;
use App\Support\Sync\BranchRoster;
use App\Support\Sync\OnlineRead;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    private const SCOPE_LOCAL = SalesReportBuilder::SCOPE_LOCAL;

    /** The consolidated report: Postgres, every branch. */
    private const SCOPE_ALL = SalesReportBuilder::SCOPE_ALL;

    public function __construct(
        private readonly OnlineRead $read,
        private readonly SalesReportBuilder $builder,
        private readonly ReportEmailSettings $emailSettings,
    ) {}

    public function index(Request $request): Response
    {
        $period = ReportPeriods::normalize($request->query('period'));
        [$from, $to] = ReportPeriods::resolveWindow($period, $this->normalizeDate($request->query('date')));
        $canViewCost = Gate::allows('view-item-cost');
        $scope = $this->normalizeScope($request->query('scope'));
        $chartBy = $this->normalizeChartBy($request->query('by'));

        $report = $scope === self::SCOPE_LOCAL
            ? $this->builder->local($period, $from, $to, $canViewCost)
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
            // Two local reads, no network. Deliberately NOT in the frontend's
            // reload-props list: stepping the date must not re-read it, for the
            // same reason `branches` is not in it either.
            'email' => [
                'configured' => $this->emailSettings->isConfigured(),
                'recipients' => $this->emailSettings->recipients(),
                'canConfigure' => Gate::allows('access-settings'),
            ],
        ]);
    }

    /**
     * Email the selected period's report as a PDF attachment.
     *
     * This replaced the PDF DOWNLOAD outright — there is no fallback. A report
     * that lands in the Downloads folder of a shop counter has not left the
     * building, which was the only thing anybody wanted from it.
     *
     * The PDF is the same document the download produced: a net-sales bar
     * chart, the summary totals, the breakdown table, a per-branch table when
     * the report is consolidated, and a "Top selling items" table for the whole
     * selected range. See {@see SalesReportPdf}.
     *
     * The recipients come from the REQUEST, not the stored defaults: the dialog
     * pre-fills itself with the defaults and lets the sender tick, untick and
     * add for this send only, and nothing here writes back to the settings.
     *
     * Everything before the send is {@see self::index}'s recompute path, so an
     * emailed report matches the on-screen totals exactly, scope and all —
     * including {@see self::normalizeScope}, which runs FIRST so an Admin
     * asking for `all` is refused before a PDF is built rather than after.
     *
     * Cost / COGS / profit follow the SENDER's own gate here, exactly as the
     * download did. The scheduled send has no sender and answers that question
     * differently — see {@see SendScheduledSalesReport}.
     *
     * A failed send is a toast, never a 500 and never a lost page. The
     * underlying exception is already in the log by then.
     */
    public function email(
        EmailReportRequest $request,
        SalesReportPdf $pdf,
        ReportMailer $mailer,
        BusinessSettings $business,
        AuditRecorder $audit,
    ): RedirectResponse {
        $period = ReportPeriods::normalize($request->input('period'));
        [$from, $to] = ReportPeriods::resolveWindow($period, $this->normalizeDate($request->input('date')));
        $canViewCost = Gate::allows('view-item-cost');
        $scope = $this->normalizeScope($request->input('scope'));
        $chartBy = $this->normalizeChartBy($request->input('by'));

        $report = $scope === self::SCOPE_LOCAL
            ? $this->builder->local($period, $from, $to, $canViewCost, topItems: true)
            : $this->consolidated($scope, $chartBy, $period, $from, $to, $canViewCost, topItems: true);

        $recipients = $request->recipients();
        $scopeLabel = $pdf->scopeLabel($report['scope'], $report['branches']);

        try {
            $mailer->send($recipients, new SalesReportMail(
                settings: $this->emailSettings,
                business: $business,
                scopeLabel: $scopeLabel,
                periodLabel: ReportPeriods::label($period, $from),
                periodNoun: ReportPeriods::SUB_NOUNS[$period],
                totals: $report['totals'],
                canViewCost: $canViewCost,
                pdf: $pdf->render($report, $period, $from, $canViewCost),
                filename: $pdf->filename($report, $period, $from),
                sentBy: $request->user()?->name ?: $request->user()?->username,
            ));
        } catch (ReportEmailException $e) {
            $audit->record(
                action: 'report.email_failed',
                description: 'Could not email the '.$period.' sales report for '.ReportPeriods::label($period, $from).'.',
                new: ['recipients' => count($recipients), 'reason' => $e->getMessage()],
            );

            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        $audit->record(
            action: 'report.emailed',
            description: 'Emailed the '.$period.' sales report for '.ReportPeriods::label($period, $from)
                .' ('.$scopeLabel.') to '.count($recipients).' recipient(s).',
            new: [
                'recipients' => $recipients,
                'period' => $period,
                'date' => $from->toDateString(),
                'scope' => $report['scope'],
            ],
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Report sent to '.count($recipients).' recipient(s).',
        ]);
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
            return ['consolidatedOnline' => false] + $this->builder->local($period, $from, $to, $canViewCost, $topItems);
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
        ] + ($canViewCost ? ['profit' => '0.00', 'uncosted_sales' => '0.00'] : []);

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
            'label' => ReportPeriods::label($period, $from),
        ];
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
