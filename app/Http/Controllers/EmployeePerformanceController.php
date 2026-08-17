<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\NormalizesMonthFilter;
use App\Http\Requests\Reports\EmailEmployeePerformanceRequest;
use App\Mail\EmployeePerformanceMail;
use App\Models\Instance;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Email\ReportEmailException;
use App\Support\Email\ReportEmailSettings;
use App\Support\Email\ReportMailer;
use App\Support\Http\FilterText;
use App\Support\Reports\EmployeeDaySales;
use App\Support\Reports\EmployeePerformanceBuilder;
use App\Support\Reports\EmployeePerformancePdf;
use App\Support\Reports\ReportPeriods;
use App\Support\Settings\BusinessSettings;
use App\Support\Sync\BranchCycle;
use App\Support\Sync\OnlineRead;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Employee performance: ONE employee, ONE month, attendance and sales on the
 * same calendar. Read-only.
 *
 * The business could already answer "what did this branch sell?" (`/reports`)
 * and "who signed in?" (`/login-history`), but nothing tied the two to a person.
 * This does: each day of the month carries the shifts that employee worked and
 * the sales they rang, recomputed from the append-only source tables by
 * {@see EmployeePerformanceBuilder} — never a stored total.
 *
 * ## One employee at a time, on purpose
 *
 * There is no "all employees" view and no all-employees PDF. A performance
 * document is about a person; a grid of everyone invites the comparison that a
 * page of numbers cannot honestly support (a cashier on the quiet shift is not
 * a worse cashier). The email dialog picks WHICH employees to send, and produces
 * one PDF each.
 *
 * ## No branch scope: online-only, and always the whole business
 *
 * Alone among the reporting screens this one takes no `scope` parameter
 * (`docs/glossary.md` §"Branch scope"). Every other scoped screen answers a
 * question about a BRANCH — what did this till sell, who signed in here, what is
 * on this shelf — and `local` is an honest default there because the branch's own
 * rows are on the device. This answers a question about a PERSON, and a person is
 * not a branch: somebody can sign in at Manila on Monday and at Cebu on Tuesday,
 * and the branch-local view of that month is not a smaller version of the truth
 * but a wrong one. Reading the local mirror would render an employee posted
 * elsewhere as a month of zeros — a calendar that looks entirely correct and says
 * they never came in.
 *
 * So the figures come from the business database through {@see OnlineRead} and
 * from nowhere else, mirrored nowhere, with no local fallback: offline the screen
 * renders and says it cannot report, which is the one outcome better than a
 * partial month presented as a whole one. Each shift carries the branch it was
 * worked at, and the summary splits the hours by branch.
 *
 * The employee PICKER is still read locally. `users` is mirrored business-wide,
 * so the local rows are the upstream rows, and reading them costs nothing and
 * keeps the offline screen naming who it would have reported on.
 *
 * ## Admin+, and no finer gate than that
 *
 * The whole route sits in the `role:admin` group, so Admins and the Owner reach
 * it and a Cashier never does. There is nothing narrower to enforce inside: this
 * report carries no cost, COGS or profit at all — see
 * {@see EmployeePerformanceBuilder} — which is what lets an Admin and the Owner
 * read the identical document, and keeps margin off an email whose recipient list
 * is not a role.
 */
class EmployeePerformanceController extends Controller
{
    use NormalizesMonthFilter;

    public function __construct(
        private readonly OnlineRead $read,
        private readonly EmployeePerformanceBuilder $builder,
        private readonly ReportEmailSettings $emailSettings,
    ) {}

    public function index(Request $request): Response
    {
        $employees = $this->employees();
        [$from, $to] = $this->window($request->query('month'), $request->query('year'));
        $employee = $this->selected($request->query('user'), $employees, $from, $to);

        // Null on a business with nobody to report on, and null when the business
        // database could not be reached. The screen tells those two apart by
        // `online`, and says the second out loud.
        $reports = $employee === null
            ? []
            : $this->reports([$employee->getKey()], $from, $to);

        $report = $employee === null || $reports === null
            ? null
            : ($reports[$employee->getKey()] ?? null);

        return Inertia::render('employee-performance', [
            'employee' => $employee === null ? null : $this->describe($employee),
            // Archived users included so a month worked before somebody left is
            // still readable. Read locally: `users` is mirrored business-wide
            // precisely so any user resolves at any branch, and the picker is
            // still worth showing on a device that cannot reach Postgres.
            'employees' => $employees->map(fn (User $user) => $this->describe($user))->all(),
            'days' => $report['days'] ?? [],
            'summary' => $report['summary'] ?? null,
            'filters' => $this->monthFilters($from),
            'years' => $this->yearsOffered(),
            // False means the figures could not be read at all — there is no
            // local month to fall back to and deliberately so.
            'online' => $reports !== null,
            // Two local reads, no network. Deliberately NOT in the frontend's
            // reload-props list: stepping the month must not re-read it.
            'email' => [
                'configured' => $this->emailSettings->isConfigured(),
                'recipients' => $this->emailSettings->recipients(),
                'canConfigure' => Gate::allows('access-settings'),
            ],
        ]);
    }

    /**
     * Email one month's performance for one or more employees: ONE message,
     * one PDF per employee.
     *
     * Several employees rather than one because the question is usually asked of
     * a team at the end of a month, and one PDF each rather than one combined
     * document because a performance report is about a person — the same reason
     * the screen shows one at a time. The recipients come from the REQUEST, not
     * the stored defaults: the dialog pre-fills itself and lets the sender tick,
     * untick and add for this send only, and nothing here writes back.
     *
     * Online-only like the screen, and for the same reason: there is no
     * branch-local month to send instead. The read runs FIRST, so an offline
     * device is refused before any PDF is built rather than after.
     *
     * A failed send is a toast, never a 500 and never a lost page. The underlying
     * exception is already in the log by then.
     */
    public function email(
        EmailEmployeePerformanceRequest $request,
        EmployeePerformancePdf $pdf,
        ReportMailer $mailer,
        BusinessSettings $business,
        AuditRecorder $audit,
    ): RedirectResponse {
        [$from, $to] = $this->window($request->input('month'), $request->input('year'));

        $employees = User::withTrashed()
            ->whereIn('id', $request->employees())
            ->orderBy('name')
            ->get();

        if ($employees->isEmpty()) {
            return back()->with('toast', ['type' => 'error', 'message' => 'No such employee.']);
        }

        $reports = $this->reports($employees->modelKeys(), $from, $to);

        if ($reports === null) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'This report is read from the business database, and this device cannot reach it.',
            ]);
        }

        $periodLabel = ReportPeriods::label(EmployeePerformanceBuilder::PERIOD, $from);

        $documents = $employees->map(fn (User $employee): array => [
            'name' => (string) ($employee->name ?: $employee->username),
            'pdf' => $pdf->render(
                $reports[$employee->getKey()],
                $this->describe($employee),
                $from,
            ),
            'filename' => $pdf->filename($employee, $from),
            // The body prints a line per employee, so a recipient reads the
            // shape of the month without opening an attachment.
            'summary' => $reports[$employee->getKey()]['summary'],
        ])->all();

        $recipients = $request->recipients();

        try {
            $mailer->send($recipients, new EmployeePerformanceMail(
                settings: $this->emailSettings,
                business: $business,
                periodLabel: $periodLabel,
                documents: $documents,
                sentBy: $request->user()?->name ?: $request->user()?->username,
            ));
        } catch (ReportEmailException $e) {
            $audit->record(
                action: 'employee_report.email_failed',
                description: 'Could not email the employee performance report for '.$periodLabel.'.',
                new: [
                    'recipients' => count($recipients),
                    'employees' => count($documents),
                    'reason' => $e->getMessage(),
                ],
            );

            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        $audit->record(
            action: 'employee_report.emailed',
            description: 'Emailed the employee performance report for '.$periodLabel
                .' covering '.count($documents).' employee(s) to '
                .count($recipients).' recipient(s).',
            new: [
                'recipients' => $recipients,
                'employees' => $employees->pluck('username')->all(),
                'month' => $from->toDateString(),
            ],
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Report sent to '.count($recipients).' recipient(s).',
        ]);
    }

    /**
     * The receipts behind one day of the calendar, fetched when its dialog opens.
     *
     * A separate read rather than a prop, for the reason `stock-history.request`
     * is: the month has thirty-one days and nobody opens more than a few of them,
     * so carrying every day's sales into every render would multiply the page
     * payload for rows that are mostly never looked at. The figures the calendar
     * itself prints come with the month; this is the list underneath them.
     *
     * Online-only like everything else on this screen, and with no local
     * fallback: the day belongs to a person, not to this branch. When the read
     * cannot happen `sales` is null and the dialog says so, rather than showing
     * an empty list that would read as "sold nothing".
     */
    public function day(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user' => ['required', 'string'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        // Archived users included, for the same reason the picker lists them: a
        // day worked before somebody left is still readable. An id that is not a
        // user 404s rather than reporting an empty day for nobody.
        abort_if(
            User::withTrashed()->whereKey($validated['user'])->doesntExist(),
            404,
        );

        $day = Carbon::createFromFormat('Y-m-d', $validated['date'])->startOfDay();

        return response()->json([
            'sales' => $this->sales($validated['user'], $day),
        ]);
    }

    /**
     * One day's sales for one employee, from the business database.
     *
     * Null when it could not be read at all — the same two cases as
     * {@see self::reports()}, and deliberately nothing behind either.
     *
     * @return ?list<array<string, mixed>>
     */
    private function sales(string $userId, Carbon $day): ?array
    {
        if (! Instance::isHydrated()) {
            return null;
        }

        return rescue(fn (): array => $this->read->run(
            fn (Connection $remote): array => (new EmployeeDaySales($remote))->forDay($userId, $day),
            'Employee day sales',
        ), null, report: OnlineRead::reportUnlessOffline());
    }

    /**
     * Build the month for every named employee, from the business database.
     *
     * One path for the screen (one employee) and the email (up to twenty),
     * because they must produce the same figures — and because every employee has
     * to be built inside the SAME online read. {@see BranchCycle} owns that
     * connection for the length of the closure; one read per employee would hold
     * one Supavisor client slot each.
     *
     * Returns NULL when the business database cannot be reached, and there is
     * deliberately nothing behind that: no local month, no partial answer. A
     * calendar covering one branch is not a smaller version of this report.
     *
     * @param  list<string>  $userIds
     * @return ?array<string, array{days: list<array<string, mixed>>, summary: array<string, mixed>}>
     */
    private function reports(array $userIds, Carbon $from, Carbon $to): ?array
    {
        // A device that never ran the wizard holds no credential to ask with, so
        // there is nothing to try and no probe timeout worth paying.
        if (! Instance::isHydrated()) {
            return null;
        }

        // `rescue` catches Throwable, which matters because CredentialVault
        // throws SetupFailedException rather than OfflineWriteException.
        return rescue(fn (): array => $this->read->run(
            function (Connection $remote) use ($userIds, $from, $to): array {
                $reports = [];
                foreach ($userIds as $userId) {
                    $reports[$userId] = $this->builder->build($remote, $userId, $from, $to);
                }

                return $reports;
            },
            'Employee performance',
        ), null, report: OnlineRead::reportUnlessOffline());
    }

    /**
     * Everyone who could be reported on, archived users included.
     *
     * @return Collection<int, User>
     */
    private function employees()
    {
        return User::withTrashed()
            ->orderBy('name')
            ->orderBy('username')
            ->get(['id', 'name', 'username', 'role', 'deleted_at']);
    }

    /**
     * Which employee this page is about.
     *
     * Defaults to whoever rang the most sales ON THIS DEVICE in the selected
     * month. `users` is mirrored business-wide, so the picker lists the whole
     * business and the first name in it is as likely as not somebody posted to a
     * branch nobody here is asking about.
     *
     * Read from the LOCAL mirror, and the one thing on this screen that is: it
     * decides only which employee the page OPENS on, never what is reported under
     * them, and being local means it costs no query inside the online read and
     * still names somebody on a device that cannot reach Postgres — so the
     * offline notice appears under a chosen employee rather than a blank picker.
     *
     * Falls back to the first ACTIVE employee by name when nothing was sold
     * here that month, so the page is never blank. An id that is not a user
     * 404s rather than silently reporting on somebody else.
     *
     * @param  Collection<int, User>  $employees
     */
    private function selected(mixed $requested, Collection $employees, Carbon $from, Carbon $to): ?User
    {
        $id = FilterText::of($requested);

        if ($id !== '') {
            $employee = $employees->firstWhere('id', $id);

            abort_if($employee === null, 404);

            return $employee;
        }

        $busiest = DB::table('transactions')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('user_id')
            ->orderByRaw('count(*) desc')
            ->value('user_id');

        return ($busiest === null ? null : $employees->firstWhere('id', (string) $busiest))
            ?? $employees->firstWhere('deleted_at', null)
            ?? $employees->first();
    }

    /**
     * The employee as every surface names them — the screen, the PDF header and
     * the audit description.
     *
     * @return array{id: string, name: string, username: string, role: string, archived: bool}
     */
    private function describe(User $employee): array
    {
        return [
            'id' => (string) $employee->getKey(),
            'name' => (string) ($employee->name ?: $employee->username),
            'username' => (string) $employee->username,
            'role' => $employee->role->value,
            'archived' => $employee->trashed(),
        ];
    }

    /**
     * The reported month, as a [from, to] window.
     *
     * Monthly and nothing else: there is no daily or weekly employee report, so
     * the picker is a month and a year rather than an anchor date and a period —
     * which is exactly the shape {@see NormalizesMonthFilter} owns, shared with
     * login history's calendar. Kept as a named method rather than calling the
     * trait directly from both call sites, so the two of them cannot drift and
     * this docblock has somewhere to live.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(mixed $month, mixed $year): array
    {
        return $this->monthWindow($month, $year);
    }
}
