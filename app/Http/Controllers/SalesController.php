<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\NormalizesDateFilter;
use App\Http\Controllers\Concerns\OffersBranchScope;
use App\Http\Controllers\Inventory\StockHistoryController;
use App\Models\Instance;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Models\Variant;
use App\Support\Printing\PrinterException;
use App\Support\Printing\ReceiptPrinter;
use App\Support\Sales\SoldLineLabel;
use App\Support\Search\LikeQuery;
use App\Support\Sync\BranchRoster;
use App\Support\Sync\OnlineRead;
use Illuminate\Database\Connection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sales history (Phase 9; cross-branch in M12). Read-only view over the
 * append-only transactions / transaction_items captured at checkout — no write
 * logic lives here.
 *
 * Access is Cashier-level: every authenticated user may browse all sales (a
 * cashier must be able to look up any transaction to reprint or, later, return
 * it). Item cost is Owner-only, so the detail payload includes per-line cost
 * only for a viewer who may see it.
 *
 * ## The `scope` parameter (M12)
 *
 * The same shape and the same two databases as `/reports` (M11),
 * `/login-history` (M11.5) and `/inventory/stock-history` (M11.8):
 *
 *  - **`local`** (the default) — this device's SQLite mirror. Works offline, and
 *    every row in it is by definition THIS branch's.
 *  - **`all`** or a **branch id** — the business database, read live through
 *    {@see OnlineRead} and mirrored nowhere. Admin+ and online-only. A Cashier
 *    asking for one is snapped back to `local` rather than refused: the local
 *    history is theirs by right and it is on the same route.
 *
 * Admin+ rather than Owner-only, because a sales list carries no cost and no
 * profit — the two things §6 reserves for the Owner. The DETAIL still gates cost
 * per line, in both scopes.
 *
 * Needs no schema or grant change: `transactions` and `sale_returns` carry
 * `branch_id` upstream with a `(branch_id, created_at)` index apiece, and
 * `BranchGrants::SELECT` has covered all four tables since M2 because the push
 * writes them. Nothing had ever read them.
 *
 * When the online read fails the screen falls back to the branch-local list and
 * SAYS SO (`consolidatedOnline: false`, scope snapped back to `local`).
 * Presenting one branch's sales under an "All branches" heading is the one
 * outcome worse than an error.
 */
class SalesController extends Controller
{
    use NormalizesDateFilter, OffersBranchScope;

    /** Allowed payment types (mirrors the POS checkout). */
    private const PAYMENT_TYPES = ['cash', 'cashless', 'others'];

    /** This branch's own sales: SQLite, works offline. */
    private const SCOPE_LOCAL = 'local';

    /** Every branch's sales: Postgres, read live. */
    private const SCOPE_ALL = 'all';

    private const PER_PAGE = 20;

    public function __construct(private readonly OnlineRead $read) {}

    public function index(Request $request): Response
    {
        $viewer = $request->user();
        $canViewAll = $viewer->isAdmin() || $viewer->isOwner();

        $filters = [
            'search' => trim((string) $request->query('search', '')) ?: null,
            'payment_type' => $this->normalizePaymentType($request->query('payment_type')),
            'user' => $request->query('user') ?: null,
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

        return Inertia::render('sales', [
            'transactions' => $history['transactions'],
            // Archived cashiers still appear so their historical sales stay
            // filterable. Read locally in every scope: users are mirrored
            // business-wide, so any cashier resolves at any branch.
            'users' => User::withTrashed()->orderBy('name')->get(['id', 'name', 'username']),
            'paymentTypes' => self::PAYMENT_TYPES,
            'filters' => [
                'search' => $filters['search'],
                'payment_type' => $filters['payment_type'],
                'user' => $filters['user'],
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
     * This branch's own sales, from the local mirror.
     *
     * Unchanged since Phase 9 apart from the branch column, and deliberately so
     * — it is what every Cashier sees and what the screen falls back to.
     *
     * @param  array{search: ?string, payment_type: ?string, user: ?string, from: ?Carbon, to: ?Carbon}  $filters
     * @return array<string, mixed>
     */
    private function branchLocal(array $filters): array
    {
        // Every local row is this branch's: the SQLite table has no branch_id,
        // and the push stamps one from `instance` on the way up. So the column is
        // a statement about the device, not about the row.
        $branch = Instance::current()?->branch_code;

        $transactions = Transaction::query()
            ->with('user:id,name,username')
            ->withCount('items')
            ->when($filters['search'], fn ($query) => $query->whereLikeAny(['transaction_number'], $filters['search']))
            ->when($filters['payment_type'], fn ($query) => $query->where('payment_type', $filters['payment_type']))
            ->when($filters['user'], fn ($query) => $query->where('user_id', $filters['user']))
            ->when($filters['from'], fn ($query) => $query->where('created_at', '>=', $filters['from']->startOfDay()))
            ->when($filters['to'], fn ($query) => $query->where('created_at', '<=', $filters['to']->endOfDay()))
            ->orderByDesc('created_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'branch' => $branch,
                'transaction_number' => $transaction->transaction_number,
                'payment_type' => $transaction->payment_type,
                'discount' => $transaction->discount,
                'discount_type' => $transaction->discount_type,
                'total' => $transaction->total,
                'created_at' => $transaction->created_at,
                'items_count' => $transaction->items_count,
                'user' => $transaction->user ? [
                    'id' => $transaction->user->id,
                    'name' => $transaction->user->name,
                    'username' => $transaction->user->username,
                ] : null,
            ]);

        return [
            'transactions' => $transactions,
            'scope' => self::SCOPE_LOCAL,
            'branches' => [],
            // Null, not false: nothing cross-branch was asked for, so there is
            // nothing to report as unavailable.
            'consolidatedOnline' => null,
        ];
    }

    /**
     * Every branch's sales (or one named branch's), read live from the business
     * database.
     *
     * One online read does the roster and the page together — two reads would
     * hold two Supavisor client slots for one screen.
     *
     * @param  string  $scope  'all', or a branch id.
     * @param  array{search: ?string, payment_type: ?string, user: ?string, from: ?Carbon, to: ?Carbon}  $filters
     * @return array<string, mixed>
     */
    private function consolidated(string $scope, array $filters): array
    {
        $requested = $scope === self::SCOPE_ALL ? null : $scope;

        $data = rescue(fn (): array => $this->read->run(
            function (Connection $remote) use ($requested, $filters): array {
                // Disabled branches included: a disabled branch cannot trade any
                // more, but the sales it already made are still the business's.
                $branches = BranchRoster::read($remote);

                // A branch id no longer in the roster means a stale or hand-typed
                // link. Show the whole business rather than an empty screen, and
                // let the echoed-back scope say what happened.
                $branchId = $requested !== null && isset($branches[$requested]) ? $requested : null;

                return [
                    'transactions' => $this->remotePage($remote, $branchId, $filters, $branches),
                    'scope' => $branchId ?? self::SCOPE_ALL,
                    'branches' => array_values($branches),
                    'consolidatedOnline' => true,
                ];
            },
            'Sales across branches',
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
     * One page of upstream sales, named and localised.
     *
     * Four things do not port from the local query above, and all of them fail
     * silently (the same class of trap M11 documents for reports):
     *
     *  - **The search is `ilike`, not `like`.** SQLite's LIKE is case-insensitive
     *    for ASCII and Postgres's is not, so typing `mnl` would match
     *    `MNL-0000000001` on this device and match nothing at all upstream. The
     *    macro cannot be reused anyway — it registers on the Eloquent builder,
     *    and this is a query builder — but {@see LikeQuery::escape()} is shared
     *    so a literal `%` stays literal in both.
     *  - **The window bounds carry their offset.** `created_at` is `timestamptz`
     *    and the session timezone is UTC, so a naive Manila bound lands eight
     *    hours out — a shift that sorts and renders perfectly and answers the
     *    wrong question.
     *  - **The ordering needs a tiebreak.** `created_at` is whole seconds here
     *    (precision 0) and a busy till rings several sales inside one, so a page
     *    boundary landing mid-second would repeat or skip rows. Not
     *    `transaction_number`: it is unique per branch, not per business.
     *  - **`numeric` arrives as an exact decimal string**, never a float. Cast to
     *    string and leave it alone; the money helpers read both databases' shape
     *    identically only as long as nobody rounds it here.
     *
     * Nothing is joined. Every user is mirrored on this device, so names are
     * resolved from local SQLite afterwards rather than across a database
     * boundary that does not exist — and archived users still resolve, which is
     * why the lookup does not filter.
     *
     * @param  array{search: ?string, payment_type: ?string, user: ?string, from: ?Carbon, to: ?Carbon}  $filters
     * @param  array<string, array{id: string, code: string, name: string}>  $branches
     */
    private function remotePage(Connection $remote, ?string $branchId, array $filters, array $branches): LengthAwarePaginator
    {
        $page = $remote->table('transactions')
            // On the raw column, so the (branch_id, created_at) index applies.
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->when($filters['search'], fn ($query) => $query->whereRaw(
                "transaction_number ilike ? escape '\\'",
                ['%'.LikeQuery::escape((string) $filters['search']).'%'],
            ))
            ->when($filters['payment_type'], fn ($query) => $query->where('payment_type', $filters['payment_type']))
            ->when($filters['user'], fn ($query) => $query->where('user_id', $filters['user']))
            ->when($filters['from'], fn ($query) => $query->where('created_at', '>=', $this->bound($filters['from']->startOfDay())))
            ->when($filters['to'], fn ($query) => $query->where('created_at', '<=', $this->bound($filters['to']->endOfDay())))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->select([
                'id', 'branch_id', 'transaction_number', 'payment_type',
                'discount', 'discount_type', 'total', 'user_id', 'created_at',
            ])
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $rows = $page->getCollection();
        $ids = $rows->map(fn (object $row): string => (string) $row->id)->all();

        // A second query rather than a grouped join: `withCount` does not exist
        // on a query builder, and a joined subquery would aggregate the whole
        // transaction_items table TWICE per render — `paginate()` runs its own
        // count(*) and would drag the join through it. This rides the
        // transaction_id index for exactly one page of keys, inside the same
        // online read, so it costs no extra connection.
        $counts = $ids === [] ? collect() : $remote->table('transaction_items')
            ->whereIn('transaction_id', $ids)
            ->groupBy('transaction_id')
            ->selectRaw('transaction_id, count(*) as items_count')
            ->pluck('items_count', 'transaction_id');

        $users = $this->localUsers($rows->map(fn (object $row) => $row->user_id));

        return $page->setCollection($rows->map(fn (object $row): array => [
            'id' => (string) $row->id,
            // A branch missing from the roster would be a row whose branch was
            // hard-deleted, which `restrictOnDelete` forbids — but the table
            // renders a dash rather than crashing on a value it cannot name.
            'branch' => $branches[(string) $row->branch_id]['code'] ?? null,
            'transaction_number' => (string) $row->transaction_number,
            'payment_type' => (string) $row->payment_type,
            'discount' => (string) $row->discount,
            'discount_type' => $row->discount_type === null ? null : (string) $row->discount_type,
            'total' => (string) $row->total,
            'created_at' => $this->localise($row->created_at),
            'items_count' => (int) ($counts[(string) $row->id] ?? 0),
            'user' => $this->actor($users, $row->user_id),
        ]));
    }

    /**
     * One sale in full.
     *
     * The binding is a string rather than a `Transaction` because the id may name
     * a row that is not on this device. `?scope=` is what decides which database
     * to look in; it is NOT used to name the branch, which is read off the row
     * itself — so `?scope=all` and `?scope=<any branch id>` behave identically
     * and a stale bookmark still opens the right sale.
     *
     * `sales.print` and `sales.returns.store` keep their model binding, so a
     * remote id 404s there rather than acting on the wrong row. That is defence
     * in depth behind hiding both buttons: this payload's `readOnly` is what the
     * page actually reads.
     */
    public function show(Request $request, string $transaction): Response|RedirectResponse
    {
        $viewer = $request->user();
        $canViewAll = $viewer->isAdmin() || $viewer->isOwner();
        $canViewCost = Gate::allows('view-item-cost');

        $scope = $this->normalizeScope($request->query('scope'), $canViewAll);

        if ($scope === self::SCOPE_LOCAL) {
            return $this->localDetail(Transaction::findOrFail($transaction), $canViewCost);
        }

        $data = rescue(fn (): array|false => $this->read->run(
            fn (Connection $remote): array|false => $this->remoteDetail($remote, $transaction, $canViewCost),
            'This sale',
        ), null, report: false);

        // Two different nulls, and conflating them would report a receipt number
        // that does not exist as a network problem. `false` is the read
        // succeeding and finding nothing; `null` is never getting there.
        if ($data === false) {
            abort(404);
        }

        if ($data === null) {
            return to_route('sales', ['scope' => $scope])->with('toast', [
                'type' => 'error',
                'message' => 'The business database could not be reached, so that sale could not be opened.',
            ]);
        }

        return Inertia::render('sales/show', $data + ['canViewCost' => $canViewCost]);
    }

    /**
     * A sale this device holds. Unchanged since Phase 11.
     */
    private function localDetail(Transaction $transaction, bool $canViewCost): Response
    {
        $transaction->load([
            'items' => fn ($query) => $query->withSum('returnItems as returned_quantity', 'quantity'),
            'items.variant.item:id,name,unit',
            'user:id,name,username',
            'saleReturns' => fn ($query) => $query->orderByDesc('created_at'),
            'saleReturns.performedBy:id,name,username',
            'saleReturns.items.sourceItem.variant.item:id,name,unit',
        ]);

        return Inertia::render('sales/show', [
            'transaction' => [
                'id' => $transaction->id,
                'transaction_number' => $transaction->transaction_number,
                'payment_type' => $transaction->payment_type,
                'discount' => $transaction->discount,
                'discount_type' => $transaction->discount_type,
                'total' => $transaction->total,
                'cash_received' => $transaction->cash_received,
                'change' => $transaction->change,
                'comment' => $transaction->comment,
                'created_at' => $transaction->created_at,
                'user' => $transaction->user ? [
                    'id' => $transaction->user->id,
                    'name' => $transaction->user->name,
                    'username' => $transaction->user->username,
                ] : null,
                'items' => $transaction->items->map(function (TransactionItem $item) use ($canViewCost) {
                    $line = [
                        'id' => $item->id,
                        'label' => $item->displayLabel(),
                        'quantity' => $item->quantity,
                        'selling_price' => $item->selling_price,
                        'discount' => $item->discount,
                        'total' => $item->total,
                        // How much of this line has already been returned/defected
                        // — the modal caps inputs against the remainder.
                        'returned_quantity' => (int) ($item->returned_quantity ?? 0),
                    ];

                    // Cost is Owner-only: the key is absent entirely for others.
                    if ($canViewCost) {
                        $line['cost'] = $item->cost;
                    }

                    return $line;
                })->all(),
                'returns' => $transaction->saleReturns->map(fn (SaleReturn $saleReturn) => [
                    'id' => $saleReturn->id,
                    'return_number' => $saleReturn->return_number,
                    'type' => $saleReturn->type,
                    'reason' => $saleReturn->reason,
                    'refund_payment_type' => $saleReturn->refund_payment_type,
                    'total_refund' => $saleReturn->total_refund,
                    'created_at' => $saleReturn->created_at,
                    'performed_by' => $saleReturn->performedBy ? [
                        'id' => $saleReturn->performedBy->id,
                        'name' => $saleReturn->performedBy->name,
                        'username' => $saleReturn->performedBy->username,
                    ] : null,
                    'items' => $saleReturn->items->map(function (SaleReturnItem $line) use ($canViewCost) {
                        $row = [
                            'id' => $line->id,
                            'label' => $line->sourceItem ? $line->sourceItem->displayLabel() : 'Unknown product',
                            'quantity' => $line->quantity,
                            'refund_amount' => $line->refund_amount,
                        ];

                        if ($canViewCost) {
                            $row['cost'] = $line->cost;
                        }

                        return $row;
                    })->all(),
                ])->all(),
            ],
            'canViewCost' => $canViewCost,
            'readOnly' => false,
            'branch' => null,
        ]);
    }

    /**
     * A sale another branch made, read live.
     *
     * Five queries on one connection. `false` — not null — for a row that is not
     * there, so {@see show()} can tell "no such receipt" from "no network".
     *
     * ## Cost is guarded by the column list and nothing else
     *
     * `HidesCostFromNonOwners::toArray()` is a MODEL serialization hook. These are
     * query-builder rows, so it never runs: whatever is selected reaches the
     * payload. The conditional select is therefore the entire guard, and for a
     * non-Owner the key must be ABSENT rather than null, exactly as
     * {@see localDetail()} leaves it.
     *
     * `returned_quantity` is summed in PHP from the return lines already in hand
     * rather than asked for again. It only enables the Return button, which
     * read-only mode hides — but the payload has one shape, so it is filled.
     *
     * @return array<string, mixed>|false
     */
    private function remoteDetail(Connection $remote, string $id, bool $canViewCost): array|false
    {
        if (! Str::isUuid($id)) {
            return false;
        }

        $row = $remote->table('transactions')->where('id', $id)->first();

        if ($row === null) {
            return false;
        }

        $branches = BranchRoster::read($remote);

        $itemColumns = ['id', 'variant_id', 'quantity', 'selling_price', 'discount', 'total'];
        $lineColumns = ['id', 'sale_return_id', 'transaction_item_id', 'variant_id', 'quantity', 'refund_amount'];

        if ($canViewCost) {
            $itemColumns[] = 'cost';
            $lineColumns[] = 'cost';
        }

        $items = $remote->table('transaction_items')
            ->where('transaction_id', $id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get($itemColumns);

        $returns = $remote->table('sale_returns')
            ->where('transaction_id', $id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'return_number', 'type', 'reason', 'refund_payment_type', 'total_refund', 'performed_by', 'created_at']);

        $lines = $returns->isEmpty() ? collect() : $remote->table('sale_return_items')
            ->whereIn('sale_return_id', $returns->pluck('id')->all())
            ->get($lineColumns);

        $returned = $lines
            ->groupBy(fn (object $line): string => (string) $line->transaction_item_id)
            ->map(fn (Collection $group): int => (int) $group->sum('quantity'));

        $variants = $this->localVariants($items->pluck('variant_id')->merge($lines->pluck('variant_id')));
        $users = $this->localUsers($returns->pluck('performed_by')->push($row->user_id));

        return [
            'transaction' => [
                'id' => (string) $row->id,
                'transaction_number' => (string) $row->transaction_number,
                'payment_type' => (string) $row->payment_type,
                'discount' => (string) $row->discount,
                'discount_type' => $row->discount_type === null ? null : (string) $row->discount_type,
                'total' => (string) $row->total,
                'cash_received' => $row->cash_received === null ? null : (string) $row->cash_received,
                'change' => $row->change === null ? null : (string) $row->change,
                'comment' => $row->comment === null ? null : (string) $row->comment,
                'created_at' => $this->localise($row->created_at),
                'user' => $this->actor($users, $row->user_id),
                'items' => $items->map(function (object $item) use ($variants, $returned, $canViewCost): array {
                    $line = [
                        'id' => (string) $item->id,
                        'label' => SoldLineLabel::for($variants[(string) $item->variant_id] ?? null),
                        'quantity' => (int) $item->quantity,
                        'selling_price' => (string) $item->selling_price,
                        'discount' => (string) $item->discount,
                        'total' => (string) $item->total,
                        'returned_quantity' => $returned[(string) $item->id] ?? 0,
                    ];

                    if ($canViewCost) {
                        $line['cost'] = $item->cost === null ? null : (string) $item->cost;
                    }

                    return $line;
                })->all(),
                'returns' => $returns->map(function (object $saleReturn) use ($lines, $variants, $users, $canViewCost): array {
                    return [
                        'id' => (string) $saleReturn->id,
                        'return_number' => (string) $saleReturn->return_number,
                        'type' => (string) $saleReturn->type,
                        'reason' => $saleReturn->reason === null ? null : (string) $saleReturn->reason,
                        'refund_payment_type' => $saleReturn->refund_payment_type === null
                            ? null
                            : (string) $saleReturn->refund_payment_type,
                        'total_refund' => (string) $saleReturn->total_refund,
                        'created_at' => $this->localise($saleReturn->created_at),
                        'performed_by' => $this->actor($users, $saleReturn->performed_by),
                        'items' => $lines
                            ->where('sale_return_id', $saleReturn->id)
                            ->map(function (object $line) use ($variants, $canViewCost): array {
                                $row = [
                                    'id' => (string) $line->id,
                                    'label' => SoldLineLabel::for($variants[(string) $line->variant_id] ?? null),
                                    'quantity' => (int) $line->quantity,
                                    'refund_amount' => (string) $line->refund_amount,
                                ];

                                if ($canViewCost) {
                                    $row['cost'] = $line->cost === null ? null : (string) $line->cost;
                                }

                                return $row;
                            })->values()->all(),
                    ];
                })->all(),
            ],
            'readOnly' => true,
            'branch' => $branches[(string) $row->branch_id]['code'] ?? null,
        ];
    }

    /**
     * Reprint a sale's receipt (Phase 11). Cashier-level, like viewing the sale.
     * The receipt is re-rendered from the immutable transaction and carries a
     * "REPRINT" marker. Printing never mutates the sale, so any failure is only
     * surfaced as a toast.
     *
     * Still bound to a local `Transaction`, and that is the rule rather than an
     * oversight: a receipt printed here for a sale another branch made is a
     * document claiming something untrue.
     */
    public function print(Transaction $transaction, ReceiptPrinter $printer): RedirectResponse
    {
        try {
            $printed = $printer->print($transaction, isReprint: true);
        } catch (PrinterException $e) {
            report($e);

            return back()->with('toast', [
                'type' => 'error',
                'message' => "Receipt {$transaction->transaction_number} failed to print: {$e->getMessage()}",
            ]);
        }

        if (! $printed) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'No receipt printer is configured. Set one up in Business settings.',
            ]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Receipt {$transaction->transaction_number} sent to the printer.",
        ]);
    }

    /**
     * Variants named from the local mirror, archived ones included.
     *
     * Keyed arrays rather than Collections, here and in {@see localUsers()}, for
     * the reason StockHistoryController records: `Collection::get()` is typed as
     * always returning its value type, and an upstream row whose variant or user
     * this device has not pulled yet genuinely has none.
     *
     * @param  Collection<int, mixed>  $ids
     * @return array<string, Variant>
     */
    private function localVariants(Collection $ids): array
    {
        return Variant::query()
            ->with('item:id,name,unit')
            ->whereIn('id', $this->keys($ids))
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * Users named from the local mirror, archived ones included.
     *
     * @param  Collection<int, mixed>  $ids
     * @return array<string, User>
     */
    private function localUsers(Collection $ids): array
    {
        return User::query()
            ->withTrashed()
            ->whereIn('id', $this->keys($ids))
            ->get(['id', 'name', 'username'])
            ->keyBy('id')
            ->all();
    }

    /**
     * The distinct, non-null ids in a column of upstream rows.
     *
     * @param  Collection<int, mixed>  $ids
     * @return list<string>
     */
    private function keys(Collection $ids): array
    {
        return $ids->filter()->map(fn (mixed $id): string => (string) $id)->unique()->values()->all();
    }

    /**
     * A cashier on an upstream row, in the shape the local path emits.
     *
     * Null when the id is null; a device that has not pulled that user yet keeps
     * the id and says "Unknown user", because the row is proof somebody rang
     * this up.
     *
     * @param  array<string, User>  $users
     * @return array<string, mixed>|null
     */
    private function actor(array $users, mixed $id): ?array
    {
        if ($id === null) {
            return null;
        }

        $user = $users[(string) $id] ?? null;

        return [
            'id' => (string) $id,
            'name' => $user === null ? 'Unknown user' : $user->name,
            'username' => $user === null ? '' : $user->username,
        ];
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
     * actually serve. Matches {@see StockHistoryController}
     * deliberately: a Cashier, or a device that was never connected to a business
     * database, gets `local` back rather than a 403.
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

    /**
     * Constrain the payment-type filter to a known value, else null.
     */
    private function normalizePaymentType(mixed $value): ?string
    {
        $value = (string) $value;

        return in_array($value, self::PAYMENT_TYPES, true) ? $value : null;
    }
}
