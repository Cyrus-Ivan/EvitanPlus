<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Concerns\NormalizesDateFilter;
use App\Http\Controllers\Concerns\OffersBranchScope;
use App\Http\Controllers\Controller;
use App\Http\Controllers\LoginHistoryController;
use App\Models\Instance;
use App\Models\InventoryLedger;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\AdjustmentResolver;
use App\Support\Inventory\MovementKind;
use App\Support\Sync\BranchRoster;
use App\Support\Sync\OnlineRead;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock History (Phase 6; cross-branch in M11.8). Read-only view over the
 * append-only inventory_ledger. Available at Cashier level: the ledger carries no
 * cost, so it is safe for every authenticated user (Cashiers have view-only
 * inventory).
 *
 * One page serves both entry points — an optional `variant` filter turns the
 * global movements list into a per-variant drill-down.
 *
 * ## The `scope` parameter (M11.8)
 *
 * The same shape and the same two databases as `/reports` (M11) and
 * `/login-history` (M11.5):
 *
 *  - **`local`** (the default) — this device's SQLite mirror. Works offline, and
 *    every row in it is by definition THIS branch's.
 *  - **`all`** or a **branch id** — the business database, read live through
 *    {@see OnlineRead} and mirrored nowhere. Admin+ and online-only. A Cashier
 *    asking for one is snapped back to `local` rather than refused: the local
 *    history is theirs by right and it is on the same route.
 *
 * It exists because of what M11.8 did to the queue, not because somebody wanted
 * another filter. `Puller` scopes `inventory_ledger` to one branch, so a branch
 * that asks another branch to move stock has never been able to see what came of
 * it — and since a refusal now writes a zero-quantity ledger row rather than
 * nothing at all ({@see AdjustmentResolver}), there is finally something upstream
 * to go and read. This is the reading of it.
 *
 * Needs no schema or grant change: `inventory_ledger` carries `branch_id`
 * upstream and `BranchGrants::SELECT` has covered it since M2, because the pull
 * already reads it.
 *
 * When the online read fails the screen falls back to the branch-local history
 * and SAYS SO (`consolidatedOnline: false`, scope snapped back to `local`).
 * Presenting one branch's movements under an "All branches" heading is the one
 * outcome worse than an error.
 */
class StockHistoryController extends Controller
{
    use NormalizesDateFilter, OffersBranchScope;

    /** This branch's own movements: SQLite, works offline. */
    private const SCOPE_LOCAL = 'local';

    /** Every branch's movements: Postgres, read live. */
    private const SCOPE_ALL = 'all';

    private const PER_PAGE = 20;

    public function __construct(private readonly OnlineRead $read) {}

    public function index(Request $request): Response
    {
        $viewer = $request->user();
        $canViewAll = $viewer->isAdmin() || $viewer->isOwner();

        $filters = [
            'variant' => $request->query('variant') ?: null,
            // A movement kind (MovementKind), not a column value: `type` alone
            // puts a recount, a CSV import and both legs of a transfer under one
            // word, and since schema 21 that is every non-sale row there is.
            'movement' => MovementKind::parse($request->query('movement')),
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

        return Inertia::render('inventory/stock-history', [
            'entries' => $history['entries'],
            // Variants for the filter dropdown; archived included so their history
            // stays filterable. Item name is joined in for a readable label, and
            // the SKU rides along so the dropdown's search matches what is printed
            // on the shelf label. Read locally in every scope: the catalog is
            // mirrored business-wide, so any variant resolves at any branch.
            'variants' => Variant::query()
                ->with('item:id,name')
                ->orderBy('name')
                ->get(['id', 'item_id', 'name', 'sku'])
                ->map(fn (Variant $variant) => [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'item' => $variant->item?->name,
                ]),
            'movements' => MovementKind::values(),
            'filters' => [
                'variant' => $filters['variant'],
                'movement' => $filters['movement'],
                'date_from' => $filters['from']?->toDateString(),
                'date_to' => $filters['to']?->toDateString(),
            ],
            // Echoed back AFTER normalisation and after any fallback, so the
            // control always shows what was actually read rather than what was
            // asked for.
            'scope' => $history['scope'],
            'canViewAll' => $canViewAll,
            'branches' => $this->pickerBranches($this->read, $roster, $canViewAll && Instance::isHydrated()),
            'consolidatedOnline' => $history['consolidatedOnline'],
        ]);
    }

    /**
     * What was ASKED for behind one ledger row (M11.8).
     *
     * Every `type = 'stock_adjustment'` row on the ledger began as a pending
     * stock adjustment, and the two share an id — {@see AdjustmentResolver}
     * forces the ledger entry to take the queue row's own uuid. So finding the
     * row needs no join, no new column and no lookup table: the id in hand IS
     * the key.
     *
     * The PARENT leg is a second lookup, and only a receiving leg has one. It is
     * what lets the dialog attribute three separate voices — whoever asked,
     * whoever confirmed the stock left, whoever confirmed it arrived — to three
     * people at up to three branches. Without it a receiving leg is read wrong
     * rather than read short: {@see AdjustmentResolver::secondLeg()} sets that
     * row's `issued_by` to the SENDER and never stamps `issued_by_branch_id`, so
     * "asked by" taken from the row itself names the wrong person with no branch.
     *
     * A JSON route fetched when the dialog opens rather than a prop on the page,
     * for the same reason {@see InventoryQueueController::branches()} is one —
     * Stock History renders offline and is Cashier-level, and an online read in
     * its render would hold a Supavisor client slot for every page view.
     *
     * Null for a sale, a return or a defect, which never went through the queue,
     * and null offline. Neither is an error: the row's own `notes` already carry
     * the sentence version of this, precisely so it reads with no connection.
     */
    public function request(string $entry): JsonResponse
    {
        if (! Str::isUuid($entry)) {
            return response()->json(['request' => null]);
        }

        $data = rescue(fn (): ?array => $this->read->run(
            function (Connection $remote) use ($entry): ?array {
                $row = $remote->table('inventory_update_queue')->where('id', $entry)->first();

                if ($row === null) {
                    return null;
                }

                $parent = $row->parent_id === null ? null : $remote->table('inventory_update_queue')
                    ->where('id', $row->parent_id)
                    ->first();

                return $this->describeRequest($row, $parent, BranchRoster::read($remote));
            },
            'The request behind this movement',
        ), null, report: false);

        return response()->json(['request' => $data]);
    }

    /**
     * This branch's own movements, from the local mirror.
     *
     * Unchanged since Phase 6 apart from the branch column, and deliberately so —
     * it is what every Cashier sees and what the screen falls back to.
     *
     * @param  array{variant: ?string, movement: ?string, from: ?Carbon, to: ?Carbon}  $filters
     * @return array<string, mixed>
     */
    private function branchLocal(array $filters): array
    {
        // Every local row is this branch's: the SQLite table has no branch_id,
        // and the push stamps one from `instance` on the way up. So the column is
        // a statement about the device, not about the row.
        $branch = Instance::current()?->branch_code;

        $entries = InventoryLedger::query()
            ->with(['variant:id,item_id,name', 'variant.item:id,name', 'performedBy:id,name,username'])
            ->when($filters['variant'], fn ($query) => $query->where('variant_id', $filters['variant']))
            ->when($filters['movement'], fn ($query) => MovementKind::apply($query, $filters['movement']))
            ->when($filters['from'], fn ($query) => $query->where('created_at', '>=', $filters['from']->startOfDay()))
            ->when($filters['to'], fn ($query) => $query->where('created_at', '<=', $filters['to']->endOfDay()))
            ->orderByDesc('created_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (InventoryLedger $entry) => [
                'id' => $entry->id,
                'branch' => $branch,
                'quantity' => $entry->quantity,
                'resulting_snapshot' => $entry->resulting_snapshot,
                'type' => $entry->type,
                'sale_type' => $entry->sale_type,
                'reference_type' => $entry->reference_type,
                // The branch that asked, or the other end of a transfer. Stored
                // as a CODE on the row itself precisely so this page keeps
                // working offline: `branches` is Postgres-only and never pulled,
                // so an id could not be turned into a name here.
                'counterparty_branch_code' => $entry->counterparty_branch_code,
                'notes' => $entry->notes,
                'created_at' => $entry->created_at,
                'variant' => $entry->variant ? [
                    'id' => $entry->variant->id,
                    'name' => $entry->variant->name,
                    'item' => $entry->variant->item?->name,
                ] : null,
                'performed_by' => $entry->performedBy?->name,
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
     * Every branch's movements (or one named branch's), read live from the
     * business database.
     *
     * One online read does the roster and the page together — two reads would
     * hold two Supavisor client slots for one screen.
     *
     * @param  string  $scope  'all', or a branch id.
     * @param  array{variant: ?string, movement: ?string, from: ?Carbon, to: ?Carbon}  $filters
     * @return array<string, mixed>
     */
    private function consolidated(string $scope, array $filters): array
    {
        $requested = $scope === self::SCOPE_ALL ? null : $scope;

        $data = rescue(fn (): array => $this->read->run(
            function (Connection $remote) use ($requested, $filters): array {
                // Disabled branches included: a disabled branch cannot receive
                // work, but the movements it already made are still history.
                $branches = BranchRoster::read($remote);

                // A branch id no longer in the roster means a stale or hand-typed
                // link. Show the whole business rather than an empty screen, and
                // let the echoed-back scope say what happened.
                $branchId = $requested !== null && isset($branches[$requested]) ? $requested : null;

                return [
                    'entries' => $this->remotePage($remote, $branchId, $filters, $branches),
                    'scope' => $branchId ?? self::SCOPE_ALL,
                    'branches' => array_values($branches),
                    'consolidatedOnline' => true,
                ];
            },
            'Stock history across branches',
        ), null, report: false);

        // Offline, or the credential could not be opened — `rescue` catches
        // Throwable, which matters because CredentialVault throws
        // SetupFailedException rather than OfflineWriteException.
        if ($data === null) {
            return ['consolidatedOnline' => false] + $this->branchLocal($filters);
        }

        return $data;
    }

    /**
     * One page of upstream ledger rows, named and localised.
     *
     * Three things do not port from the local query above, and all of them fail
     * silently (the same class of trap M11 documents for reports):
     *
     *  - **The window bounds carry their offset.** `created_at` is `timestamptz`
     *    and the session timezone is UTC, so a naive Manila bound lands eight
     *    hours out — a shift that sorts and renders perfectly and answers the
     *    wrong question.
     *  - **The timestamps come back with an offset**, and are converted to the
     *    business timezone in PHP so a consolidated row reads the same way a
     *    local one does.
     *  - **Nothing is joined.** The catalog and every user are mirrored on this
     *    device, so names are resolved from local SQLite afterwards rather than
     *    across a database boundary that does not exist. Archived variants and
     *    archived users both still resolve, which is why neither lookup filters.
     *
     * @param  array{variant: ?string, movement: ?string, from: ?Carbon, to: ?Carbon}  $filters
     * @param  array<string, array{id: string, code: string, name: string}>  $branches
     */
    private function remotePage(Connection $remote, ?string $branchId, array $filters, array $branches): LengthAwarePaginator
    {
        $page = $remote->table('inventory_ledger')
            // On the raw column, so the (branch_id, created_at) index applies.
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->when($filters['variant'], fn ($query) => $query->where('variant_id', $filters['variant']))
            ->when($filters['movement'], function ($query) use ($filters) {
                foreach (MovementKind::conditions($filters['movement']) as $column => $value) {
                    $query->where($column, $value);
                }
            })
            ->when($filters['from'], fn ($query) => $query->where('created_at', '>=', $this->bound($filters['from']->startOfDay())))
            ->when($filters['to'], fn ($query) => $query->where('created_at', '<=', $this->bound($filters['to']->endOfDay())))
            ->orderByDesc('created_at')
            // A tiebreak the local path does not need: `created_at` is whole
            // seconds here (precision 0), and a CSV import writes a page of rows
            // inside one of them.
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $rows = $page->getCollection();

        $variants = Variant::query()
            ->with('item:id,name')
            ->whereIn('id', $rows->map(fn (object $row): string => (string) $row->variant_id)->all())
            ->get()
            ->keyBy('id');

        $users = User::query()
            ->withTrashed()
            ->whereIn('id', $rows->map(fn (object $row): string => (string) $row->performed_by)->all())
            ->pluck('name', 'id');

        return $page->setCollection($rows->map(function (object $row) use ($variants, $users, $branches): array {
            $variant = $variants->get((string) $row->variant_id);

            return [
                'id' => (string) $row->id,
                // A branch missing from the roster would be a row whose branch was
                // hard-deleted, which `restrictOnDelete` forbids — but the table
                // renders a dash rather than crashing on a value it cannot name.
                'branch' => $branches[(string) $row->branch_id]['code'] ?? null,
                'quantity' => (int) $row->quantity,
                'resulting_snapshot' => (int) $row->resulting_snapshot,
                'type' => (string) $row->type,
                'sale_type' => $row->sale_type === null ? null : (string) $row->sale_type,
                'reference_type' => (string) $row->reference_type,
                'counterparty_branch_code' => $row->counterparty_branch_code === null
                    ? null
                    : (string) $row->counterparty_branch_code,
                'notes' => $row->notes === null ? null : (string) $row->notes,
                'created_at' => $this->localise($row->created_at),
                'variant' => $variant === null ? null : [
                    'id' => (string) $variant->id,
                    'name' => $variant->name,
                    'item' => $variant->item?->name,
                ],
                'performed_by' => $users[(string) $row->performed_by] ?? null,
            ];
        }));
    }

    /**
     * The queue row behind a ledger entry, named from the local mirror.
     *
     * Every flat field describes THIS leg. `parent` is the sending leg of a
     * transfer and describes that one — a different person, at a different
     * branch, answering a different question — which is why it is a nested block
     * rather than more columns alongside.
     *
     * @param  array<string, array{id: string, code: string, name: string}>  $branches
     * @return array<string, mixed>
     */
    private function describeRequest(object $row, ?object $parent, array $branches): array
    {
        $users = User::query()
            ->withTrashed()
            ->whereIn('id', array_filter([
                (string) $row->issued_by,
                $row->resolved_by === null ? null : (string) $row->resolved_by,
                $parent === null ? null : (string) $parent->issued_by,
                $parent?->resolved_by === null ? null : (string) $parent->resolved_by,
            ]))
            ->get(['id', 'name', 'role'])
            ->keyBy('id')
            // A keyed array, not a Collection: `Collection::get()` is typed as
            // always returning its value type, and a queue row whose user has
            // not been mirrored to this device yet genuinely has none.
            ->all();

        $transfer = (string) $row->reference_type === 'transfer';

        return [
            'id' => (string) $row->id,
            'status' => (string) $row->status,
            'kind' => $transfer ? 'transfer' : 'adjustment',
            'source' => (string) $row->reference_type,
            // Signed, and the point of the whole endpoint: the ledger row says
            // what moved, and only this says what was asked for.
            'requested' => (int) $row->quantity,
            'approved' => $row->approved_quantity === null ? null : (int) $row->approved_quantity,
            'resolution_reason' => $row->resolution_reason,
            // The requester's own words, kept apart from the answerer's so each
            // can be shown under the name of whoever wrote it.
            'notes' => $row->notes,
            'issued_by' => $this->actor($users, $row->issued_by, [
                'branch' => $this->branchRef($row->issued_by_branch_id ?? null, $branches),
                'at' => $this->localise($row->created_at),
            ]),
            'resolved_by' => $row->resolved_by === null
                ? null
                : $this->actor($users, $row->resolved_by),
            'resolved_at' => $this->localise($row->resolved_at),
            'branch' => $this->branchRef($row->branch_id, $branches),
            'parent' => $parent === null ? null : [
                'requested' => (int) $parent->quantity,
                'approved' => $parent->approved_quantity === null ? null : (int) $parent->approved_quantity,
                'resolution_reason' => $parent->resolution_reason,
                'notes' => $parent->notes,
                // Whoever FIRST asked for the stock to move, which this leg does
                // not know: its own `issued_by` is the person who confirmed the
                // stock left, and it carries no issuing branch at all.
                'issued_by' => $this->actor($users, $parent->issued_by, [
                    'branch' => $this->branchRef($parent->issued_by_branch_id ?? null, $branches),
                    'at' => $this->localise($parent->created_at),
                ]),
                'resolved_by' => $parent->resolved_by === null
                    ? null
                    : $this->actor($users, $parent->resolved_by),
                'resolved_at' => $this->localise($parent->resolved_at),
                'branch' => $this->branchRef($parent->branch_id, $branches),
            ],
        ];
    }

    /**
     * A person on a queue row, named from the local mirror.
     *
     * "Unknown user" rather than null: the row is proof somebody did this, and a
     * device that has not pulled them yet must not imply otherwise.
     *
     * @param  array<string, User>  $users
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function actor(array $users, mixed $id, array $extra = []): array
    {
        $user = $users[(string) $id] ?? null;

        return [
            'name' => $user === null ? 'Unknown user' : $user->name,
            'role' => $user?->role->value,
        ] + $extra;
    }

    /**
     * @param  array<string, array{id: string, code: string, name: string}>  $branches
     * @return array{id: string, code: string, name: string}|null
     */
    private function branchRef(mixed $id, array $branches): ?array
    {
        if ($id === null) {
            return null;
        }

        return $branches[(string) $id] ?? ['id' => (string) $id, 'code' => '??', 'name' => 'Unknown branch'];
    }

    /**
     * A window bound Postgres will read as the Manila wall-clock time it means.
     */
    private function bound(Carbon $moment): string
    {
        return $moment->format('Y-m-d H:i:s.uP');
    }

    /**
     * An upstream `timestamptz` as a business-local moment.
     */
    private function localise(mixed $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse((string) $value)->setTimezone(config('app.timezone'));
    }

    /**
     * Which branches to read, snapped to what this viewer and this device can
     * actually serve. Matches {@see LoginHistoryController} deliberately: a
     * Cashier, or a device that was never connected to a business database, gets
     * `local` back rather than a 403.
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
