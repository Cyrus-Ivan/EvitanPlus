<?php

namespace App\Http\Controllers;

use App\Enums\LoginEventType;
use App\Http\Controllers\Concerns\NormalizesMonthFilter;
use App\Http\Controllers\Concerns\OffersBranchScope;
use App\Models\Instance;
use App\Models\LoginHistory;
use App\Support\Http\FilterText;
use App\Support\LoginHistory\LoginCalendar;
use App\Support\Sync\BranchRoster;
use App\Support\Sync\OnlineRead;
// The base Carbon, matching what NormalizesMonthFilter's window is: hinting
// Laravel's narrower subclass here is a TypeError that `rescue()` swallows, and
// the screen reports as being offline.
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Login History display (Phase 14; cross-branch in M11.5; a calendar since).
 * Read-only view over the append-only login_history captured since Phase 2 — no
 * capture logic lives here.
 *
 * Role scoping: a Cashier may only ever see their own events; Admins and the
 * Owner see everyone. The own-only constraint is applied server-side, and no
 * `user` query parameter is read at all, so nothing crafted can widen it.
 *
 * ## A branch, not a person
 *
 * There is no user filter here, and that is the screen's subject rather than an
 * omission. This is the per-BRANCH view — who signed in at this till, and when.
 * The per-person question is `/employee-performance`, which asks it of the same
 * `login_history` (via AttendanceFigures) and answers it far better than a
 * filtered event list could: one employee, one month, their shifts and their
 * sales on the same calendar. A picker here would put a second, worse copy of
 * that question on a screen about somewhere rather than somebody.
 *
 * ## A month, not a page
 *
 * The screen is a calendar and the window is therefore ONE MONTH, picked by
 * month and year ({@see NormalizesMonthFilter}, shared with employee
 * performance) rather than by a free date range. There is no pagination: the
 * month is the page, its rows come back in one read, and
 * {@see LoginCalendar::days()} cuts them into day cells. A day's events are
 * carried in full so the day dialog opens from data the client already holds —
 * which is also what makes it work offline in the `local` scope, where a second
 * request would have nothing to ask.
 *
 * Both reads are ordered ASCENDING, unlike every other index in the app. A
 * paginated list is newest-first because its far end is unbounded; a day is
 * bounded and reads forward as it happened, and a truncated cell should hide the
 * end of the day rather than its beginning.
 *
 * ## The `scope` parameter (M11.5)
 *
 * The same shape `/reports` grew in M11, for the same reason and against the
 * same two databases:
 *
 *  - **`local`** (the default) — this device's SQLite mirror. Works offline, and
 *    every row in it is by definition THIS branch's, which is why the branch
 *    column can be filled from `instance` without the local table carrying a
 *    `branch_id` at all.
 *  - **`all`** or a **branch id** — the business database, read live through
 *    {@see OnlineRead} and mirrored nowhere. Admin+ and online-only.
 *
 * `login_history` is local-write and pushed insert-only: it goes UP and never
 * comes back down, so no branch holds another branch's sessions and no pull will
 * ever change that. Cross-branch login activity exists only upstream — which is
 * what `MULTIBRANCH-BRAINSTORM.md` §6 promised ("the Owner/admin can see login
 * activity across branches online") and what this reads. It needs no schema or
 * grant change: Postgres has carried `branch_id` on this table with a
 * `(branch_id, occurred_at)` index since M1, and `BranchGrants` has granted
 * SELECT on it since M2.
 *
 * Unlike reports this is Admin+ rather than Owner-only — there is no cost or
 * profit here, and §6 names both roles. A Cashier asking for a wider scope is
 * snapped back to `local` rather than refused, exactly as they are pinned to
 * their own events rather than refused the screen: the same route still owes
 * them the view they are entitled to.
 *
 * When the online read fails the screen falls back to the branch-local history
 * and SAYS SO (`consolidatedOnline: false`, scope snapped back to `local`).
 * Presenting one branch's sessions under an "All branches" heading is the one
 * outcome worse than an error.
 */
class LoginHistoryController extends Controller
{
    use NormalizesMonthFilter, OffersBranchScope;

    public function __construct(
        private readonly OnlineRead $read,
        private readonly LoginCalendar $calendar,
    ) {}

    public function index(Request $request): Response
    {
        $viewer = $request->user();
        $canViewAll = $viewer->isAdmin() || $viewer->isOwner();

        [$from, $to] = $this->monthWindow($request->query('month'), $request->query('year'));

        $filters = [
            // Not a filter — the Cashier's own-only constraint, which is why
            // nothing here reads a `user` query parameter. See the class
            // docblock.
            'user' => $canViewAll ? null : $viewer->getKey(),
            'event' => $this->normalizeEvent($request->query('event')),
            'from' => $from,
            'to' => $to,
        ];

        $scope = $this->normalizeScope($request->query('scope'), $canViewAll);

        $history = $scope === self::SCOPE_LOCAL
            ? $this->branchLocal($filters)
            : $this->consolidated($scope, $filters);

        // Null means the local path ran and no roster came back with it, so the
        // picker's list is read on its own. Anything else — including the empty
        // list a failed consolidated read leaves — already cost one online read
        // this request and is reused rather than attempted again.
        $roster = $history['consolidatedOnline'] === null ? null : $history['branches'];

        return Inertia::render('login-history', [
            // Every day of the month, each carrying its own events oldest-first.
            'days' => $this->calendar->days($from, $to, $history['entries']),
            // The month's event count. On screen above the grid, so a filtered
            // month that renders as empty cells still says how much it matched.
            'total' => count($history['entries']),
            'canViewAll' => $canViewAll,
            'eventTypes' => array_map(fn (LoginEventType $type) => $type->value, LoginEventType::cases()),
            'filters' => [
                'event' => $filters['event'],
                // The month, its label and the ‹ › steppers' destinations —
                // echoed back AFTER normalisation, so a hand-typed `?month=13`
                // leaves the control showing the month actually read.
                ...$this->monthFilters($from),
            ],
            // Never in the frontend's reload list: stepping the month must not
            // re-send a list that cannot change.
            'years' => $this->yearsOffered(),
            // M11.5. Echoed back AFTER normalisation and after any fallback, so
            // the control always shows what was actually read rather than what
            // was asked for.
            'scope' => $history['scope'],
            // Every branch but this one: the picker's first entry is already
            // this branch, as `local` (see OffersBranchScope).
            'branches' => $this->pickerBranches($this->read, $roster, $canViewAll && Instance::isHydrated()),
            'consolidatedOnline' => $history['consolidatedOnline'],
        ]);
    }

    /**
     * This branch's own sessions, from the local mirror. Unchanged since Phase
     * 14 apart from the branch column and the month window, and deliberately so
     * — it is what every Cashier sees and what the screen falls back to.
     *
     * @param  array{user: ?string, event: ?string, from: Carbon, to: Carbon}  $filters
     * @return array<string, mixed>
     */
    private function branchLocal(array $filters): array
    {
        // Every local row is this branch's: the SQLite table has no branch_id,
        // and the push stamps one from `instance` on the way up. So the column
        // is a statement about the device, not about the row.
        $branch = Instance::current()?->branch_code;

        $entries = LoginHistory::query()
            ->with('user:id,name,username')
            ->when($filters['user'], fn ($query) => $query->where('user_id', $filters['user']))
            ->when($filters['event'], fn ($query) => $query->where('event_type', $filters['event']))
            // Filter on the displayed "When" value (occurred_at falls back to
            // created_at) so the month matches exactly what the cells show —
            // this same COALESCE is what LoginCalendar buckets on.
            ->whereRaw(
                'COALESCE(occurred_at, created_at) >= ?',
                [$filters['from']->format('Y-m-d H:i:s')],
            )
            ->whereRaw(
                'COALESCE(occurred_at, created_at) <= ?',
                [$filters['to']->format('Y-m-d H:i:s')],
            )
            // Ascending: a day reads forward. The `id` tiebreak keeps two events
            // sharing a second in a stable order, as AttendanceFigures does.
            ->orderByRaw('COALESCE(occurred_at, created_at)')
            ->orderBy('id')
            ->get()
            ->map(fn (LoginHistory $entry) => [
                'id' => $entry->id,
                'branch' => $branch,
                'event_type' => $entry->event_type,
                'occurred_at' => $entry->occurred_at,
                'created_at' => $entry->created_at,
                'notes' => $entry->notes,
                'user' => $entry->user ? [
                    'id' => $entry->user->id,
                    'name' => $entry->user->name,
                    'username' => $entry->user->username,
                ] : null,
            ])
            ->all();

        return [
            'entries' => $entries,
            'scope' => self::SCOPE_LOCAL,
            'branches' => [],
            // Null, not false: nothing cross-branch was asked for, so there is
            // nothing to report as unavailable.
            'consolidatedOnline' => null,
        ];
    }

    /**
     * Every branch's sessions (or one named branch's), read live from the
     * business database.
     *
     * One online read does the roster and the page together — two reads would
     * hold two Supavisor client slots for one screen.
     *
     * @param  string  $scope  'all', or a branch id.
     * @param  array{user: ?string, event: ?string, from: Carbon, to: Carbon}  $filters
     * @return array<string, mixed>
     */
    private function consolidated(string $scope, array $filters): array
    {
        $requested = $scope === self::SCOPE_ALL ? null : $scope;

        $data = rescue(fn (): array => $this->read->run(
            function (Connection $remote) use ($requested, $filters): array {
                // Disabled branches included: a disabled branch cannot receive
                // work, but the sessions it already had are still history.
                $branches = BranchRoster::read($remote);

                // A branch id no longer in the roster means a stale or
                // hand-typed link. Show the whole business rather than an empty
                // screen, and let the echoed-back scope say what happened.
                $branchId = $requested !== null && isset($branches[$requested]) ? $requested : null;

                return [
                    'entries' => $this->remoteEntries($remote, $branchId, $filters, $branches),
                    'scope' => $branchId ?? self::SCOPE_ALL,
                    'branches' => array_values($branches),
                    'consolidatedOnline' => true,
                ];
            },
            'Login history across branches',
        ), null, report: OnlineRead::reportUnlessOffline());

        // Offline, or the credential could not be opened — `rescue` catches
        // Throwable, which matters because CredentialVault throws
        // SetupFailedException rather than OfflineWriteException. Fall back and
        // mark it, so the screen never presents one branch's sessions as the
        // business's.
        if ($data === null) {
            return ['consolidatedOnline' => false] + $this->branchLocal($filters);
        }

        return $data;
    }

    /**
     * The month of upstream login history, named and localised.
     *
     * Three things here do not port from the local query above, and all of them
     * fail silently (the same class of trap M11 documents for reports):
     *
     *  - **The window bounds carry their offset.** These columns are
     *    `timestamptz` and the session timezone is UTC, so a naive Manila bound
     *    lands eight hours out — a month that sorts and renders perfectly and
     *    answers the wrong question. The sub-second digits ride along for the
     *    same reason `SalesFigures::bound()` keeps them (one format, one rule);
     *    they are inert here, since `timestampTz` takes Laravel's default
     *    precision of 0 and the column holds whole seconds.
     *  - **The timestamps come back with an offset.** They are converted to the
     *    business timezone in PHP rather than with `to_char`, which keeps one
     *    code path for formatting and avoids binding a format string per column.
     *    It is also what lets {@see LoginCalendar} bucket a row on the business
     *    day it happened rather than the UTC one.
     *  - **The user is joined, not eager-loaded.** A LEFT JOIN so a row survives
     *    a user that is missing upstream, and unfiltered on `deleted_at` because
     *    archived users must still resolve in login history (CLAUDE.md).
     *
     * @param  array{user: ?string, event: ?string, from: Carbon, to: Carbon}  $filters
     * @param  array<string, array{id: string, code: string, name: string}>  $branches
     * @return list<array<string, mixed>>
     */
    private function remoteEntries(Connection $remote, ?string $branchId, array $filters, array $branches): array
    {
        $rows = $remote->table('login_history as history')
            ->leftJoin('users as actor', 'actor.id', '=', 'history.user_id')
            ->when($branchId, fn ($query) => $query->where('history.branch_id', $branchId))
            ->when($filters['user'], fn ($query) => $query->where('history.user_id', $filters['user']))
            ->when($filters['event'], fn ($query) => $query->where('history.event_type', $filters['event']))
            ->whereRaw(
                'coalesce(history.occurred_at, history.created_at) >= ?',
                [$this->bound($filters['from'])],
            )
            ->whereRaw(
                'coalesce(history.occurred_at, history.created_at) <= ?',
                [$this->bound($filters['to'])],
            )
            // Ascending with an `id` tiebreak: `timestamptz` is precision 0
            // upstream, so a sign-out and the sign-in after it can share a
            // second and would otherwise order differently on every read.
            ->orderByRaw('coalesce(history.occurred_at, history.created_at)')
            ->orderBy('history.id')
            ->select(
                'history.id',
                'history.branch_id',
                'history.event_type',
                'history.occurred_at',
                'history.created_at',
                'history.notes',
                'actor.id as user_id',
                'actor.name as user_name',
                'actor.username as user_username',
            )
            ->get();

        return $rows->map(fn (object $row): array => [
            'id' => (string) $row->id,
            // A branch missing from the roster would be a row whose branch was
            // hard-deleted, which `restrictOnDelete` forbids — but the table
            // renders a dash rather than crashing on a value it cannot name.
            'branch' => $branches[(string) $row->branch_id]['code'] ?? null,
            'event_type' => (string) $row->event_type,
            'occurred_at' => $this->localise($row->occurred_at),
            'created_at' => $this->localise($row->created_at),
            'notes' => $row->notes === null ? null : (string) $row->notes,
            'user' => $row->user_id === null ? null : [
                'id' => (string) $row->user_id,
                'name' => $row->user_name === null ? null : (string) $row->user_name,
                'username' => (string) $row->user_username,
            ],
        ])->all();
    }

    /**
     * A window bound Postgres will read as the Manila wall-clock time it means.
     * Both halves matter — see {@see self::remoteEntries()}.
     */
    private function bound(Carbon $moment): string
    {
        return $moment->format('Y-m-d H:i:s.uP');
    }

    /**
     * An upstream `timestamptz` as a business-local moment, so a consolidated
     * row reads the same way a local one does.
     */
    private function localise(mixed $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse((string) $value)->setTimezone(config('app.timezone'));
    }

    /**
     * Constrain the event filter to a known LoginEventType value, else null.
     */
    private function normalizeEvent(mixed $event): ?string
    {
        return LoginEventType::tryFrom(FilterText::of($event))?->value;
    }
}
