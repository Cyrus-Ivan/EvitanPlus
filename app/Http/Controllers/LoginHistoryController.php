<?php

namespace App\Http\Controllers;

use App\Enums\LoginEventType;
use App\Http\Controllers\Concerns\NormalizesDateFilter;
use App\Http\Controllers\Concerns\OffersBranchScope;
use App\Models\Instance;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\Sync\BranchRoster;
use App\Support\Sync\OnlineRead;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Login History display (Phase 14; cross-branch in M11.5). Read-only view over
 * the append-only login_history captured since Phase 2 — no capture logic lives
 * here.
 *
 * Role scoping: a Cashier may only ever see their own events; Admins and the
 * Owner see everyone and may filter by user. The own-only constraint is applied
 * server-side and cannot be widened by a crafted `user` query parameter.
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
 * snapped back to `local` rather than refused, exactly as their `user` filter is
 * snapped back to themselves: the same route still owes them the view they are
 * entitled to.
 *
 * When the online read fails the screen falls back to the branch-local history
 * and SAYS SO (`consolidatedOnline: false`, scope snapped back to `local`).
 * Presenting one branch's sessions under an "All branches" heading is the one
 * outcome worse than an error.
 */
class LoginHistoryController extends Controller
{
    use NormalizesDateFilter, OffersBranchScope;

    /** This branch's own sessions: SQLite, works offline. */
    private const SCOPE_LOCAL = 'local';

    /** Every branch's sessions: Postgres, read live. */
    private const SCOPE_ALL = 'all';

    private const PER_PAGE = 20;

    public function __construct(private readonly OnlineRead $read) {}

    public function index(Request $request): Response
    {
        $viewer = $request->user();
        $canViewAll = $viewer->isAdmin() || $viewer->isOwner();

        $filters = [
            'user' => $canViewAll ? ($request->query('user') ?: null) : $viewer->getKey(),
            'event' => $this->normalizeEvent($request->query('event')),
            'from' => $this->normalizeDate($request->query('date_from')),
            'to' => $this->normalizeDate($request->query('date_to')),
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
            'entries' => $history['entries'],
            'canViewAll' => $canViewAll,
            // Only offered to those who may see everyone; archived users included
            // so their historical events stay filterable. Read locally in every
            // scope: `users` is the one table mirrored business-wide, precisely
            // so any user can be resolved at any branch offline.
            'users' => $canViewAll
                ? User::withTrashed()->orderBy('name')->get(['id', 'name', 'username'])
                : [],
            'eventTypes' => array_map(fn (LoginEventType $type) => $type->value, LoginEventType::cases()),
            'filters' => [
                'user' => $canViewAll ? $filters['user'] : null,
                'event' => $filters['event'],
                'date_from' => $filters['from']?->toDateString(),
                'date_to' => $filters['to']?->toDateString(),
            ],
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
     * 14 apart from the branch column, and deliberately so — it is what every
     * Cashier sees and what the screen falls back to.
     *
     * @param  array{user: ?string, event: ?string, from: ?Carbon, to: ?Carbon}  $filters
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
            // created_at) so the range matches exactly what the table shows.
            ->when($filters['from'], fn ($query) => $query->whereRaw(
                'COALESCE(occurred_at, created_at) >= ?',
                [$filters['from']->startOfDay()->format('Y-m-d H:i:s')],
            ))
            ->when($filters['to'], fn ($query) => $query->whereRaw(
                'COALESCE(occurred_at, created_at) <= ?',
                [$filters['to']->endOfDay()->format('Y-m-d H:i:s')],
            ))
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (LoginHistory $entry) => [
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
            ]);

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
     * @param  array{user: ?string, event: ?string, from: ?Carbon, to: ?Carbon}  $filters
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
                    'entries' => $this->remotePage($remote, $branchId, $filters, $branches),
                    'scope' => $branchId ?? self::SCOPE_ALL,
                    'branches' => array_values($branches),
                    'consolidatedOnline' => true,
                ];
            },
            'Login history across branches',
        ), null, report: false);

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
     * One page of upstream login history, named and localised.
     *
     * Three things here do not port from the local query above, and all of them
     * fail silently (the same class of trap M11 documents for reports):
     *
     *  - **The window bounds carry their offset.** These columns are
     *    `timestamptz` and the session timezone is UTC, so a naive Manila bound
     *    lands eight hours out — a shift that sorts and renders perfectly and
     *    answers the wrong question. The sub-second digits ride along for the
     *    same reason `SalesFigures::bound()` keeps them (one format, one rule);
     *    they are inert here, since `timestampTz` takes Laravel's default
     *    precision of 0 and the column holds whole seconds.
     *  - **The timestamps come back with an offset.** They are converted to the
     *    business timezone in PHP rather than with `to_char`, which keeps one
     *    code path for formatting and avoids binding a format string per column.
     *  - **The user is joined, not eager-loaded.** A LEFT JOIN so a row survives
     *    a user that is missing upstream, and unfiltered on `deleted_at` because
     *    archived users must still resolve in login history (CLAUDE.md).
     *
     * @param  array{user: ?string, event: ?string, from: ?Carbon, to: ?Carbon}  $filters
     * @param  array<string, array{id: string, code: string, name: string}>  $branches
     */
    private function remotePage(Connection $remote, ?string $branchId, array $filters, array $branches): LengthAwarePaginator
    {
        $page = $remote->table('login_history as history')
            ->leftJoin('users as actor', 'actor.id', '=', 'history.user_id')
            ->when($branchId, fn ($query) => $query->where('history.branch_id', $branchId))
            ->when($filters['user'], fn ($query) => $query->where('history.user_id', $filters['user']))
            ->when($filters['event'], fn ($query) => $query->where('history.event_type', $filters['event']))
            ->when($filters['from'], fn ($query) => $query->whereRaw(
                'coalesce(history.occurred_at, history.created_at) >= ?',
                [$this->bound($filters['from']->startOfDay())],
            ))
            ->when($filters['to'], fn ($query) => $query->whereRaw(
                'coalesce(history.occurred_at, history.created_at) <= ?',
                [$this->bound($filters['to']->endOfDay())],
            ))
            ->orderByDesc('history.occurred_at')
            ->orderByDesc('history.created_at')
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
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return $page->setCollection($page->getCollection()->map(fn (object $row): array => [
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
        ]));
    }

    /**
     * A window bound Postgres will read as the Manila wall-clock time it means.
     * Both halves matter — see {@see self::remotePage()}.
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
        return LoginEventType::tryFrom((string) $event)?->value;
    }

    /**
     * Which branches to read, snapped to what this viewer and this device can
     * actually serve.
     *
     * A Cashier, or a device that was never connected to a business database,
     * gets `local` back rather than a 403: the local history is theirs by right
     * and it is on the same route.
     *
     * This branch's own id is `local` under another name, and the picker offers
     * it only under that one — a bookmarked link naming it would otherwise leave
     * the control showing a value that is not in its list.
     */
    private function normalizeScope(mixed $value, bool $canViewAll): string
    {
        $scope = (string) $value;

        if ($scope === '' || $scope === self::SCOPE_LOCAL || ! $canViewAll || ! Instance::isHydrated()) {
            return self::SCOPE_LOCAL;
        }

        if ($this->isThisBranch($scope)) {
            return self::SCOPE_LOCAL;
        }

        return $scope === self::SCOPE_ALL || Str::isUuid($scope) ? $scope : self::SCOPE_ALL;
    }
}
