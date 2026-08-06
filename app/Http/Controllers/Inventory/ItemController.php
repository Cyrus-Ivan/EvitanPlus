<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Concerns\OffersBranchScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreItemRequest;
use App\Http\Requests\Inventory\UpdateItemRequest;
use App\Models\Category;
use App\Models\Concerns\HidesCostFromNonOwners;
use App\Models\Instance;
use App\Models\Item;
use App\Models\Variant;
use App\Support\Audit\AuditRecorder;
use App\Support\Sync\BranchRoster;
use App\Support\Sync\OnlineRead;
use App\Support\Sync\OnlineWrite;
use App\Support\Sync\StockReconciler;
use Illuminate\Database\Connection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Items and their variants are SHARED business data (§5.1). A product, its
 * price and whether it is archived are the same at every branch, so each write
 * here is an ONLINE WRITE that reaches the business database or does not happen
 * at all ({@see OnlineWrite}; M6.5).
 *
 * Reading the CATALOG is untouched and stays fully offline — the catalog a till
 * sells from is its local mirror.
 *
 * ## The `scope` parameter (M12): stock, and only stock
 *
 * Stock is the one thing on this screen that is per-branch, so it is the only
 * thing the scope changes. `local` (the default) reads the local snapshot and
 * is exactly what this screen has always shown; a branch id, or `all`, reads
 * `stock_snapshots` live from the business database through {@see OnlineRead}.
 * Admin+, with a Cashier snapped back to `local` rather than refused.
 *
 * **The item and variant query is local in EVERY scope.** The catalog is
 * online-authoritative and mirrored business-wide, so the local rows ARE the
 * upstream rows — search, the category filter, the archived filter, the ordering
 * and the pagination all stay offline and unchanged, and the online read is one
 * `whereIn` over the variant ids already on the page. That also makes the
 * fallback trivial: it attaches nothing, and the page renders as it does today.
 *
 * One consequence worth stating rather than fixing: a variant created at another
 * branch since this device's last pull is not in the local catalog, so it is
 * invisible here in every scope. That window is not new — it already hides the
 * variant from POS lookup and from Stock History's dropdown — and one sync cycle
 * closes it.
 *
 * A figure read this way is a BASELINE, not a reconciled figure: it excludes
 * whatever that branch has sold and not yet pushed, so it can read high. See
 * {@see StockReconciler} for the three numbers, and the notice on the page,
 * which says so where somebody is about to act on it.
 *
 * Needs no schema or grant change: upstream `stock_snapshots` is keyed
 * `(branch_id, variant_id)` and `BranchGrants::SELECT` has covered it since M2.
 */
class ItemController extends Controller
{
    use OffersBranchScope;

    /** This branch's own shelf: the local snapshot, works offline. */
    private const SCOPE_LOCAL = 'local';

    /** Every branch's shelf, totalled: Postgres, read live. */
    private const SCOPE_ALL = 'all';

    public function __construct(
        private readonly OnlineWrite $online,
        private readonly OnlineRead $read,
    ) {}

    /**
     * Paginated item catalog with search, category filter and archived filter.
     */
    public function index(Request $request): Response
    {
        $viewer = $request->user();
        $canViewAll = $viewer->isAdmin() || $viewer->isOwner();

        $search = trim((string) $request->query('search', '')) ?: null;
        $categoryId = $request->query('category') ?: null;
        $status = $this->normalizeStatus($request->query('status'));

        $items = Item::query()
            ->with([
                'category:id,name',
                'variants' => fn ($query) => $query->active()->with('stockSnapshot')->orderBy('created_at'),
            ])
            ->when($search, function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->whereLike('name', $search)
                        ->orWhereHas('variants', fn ($variant) => $variant->whereLikeAny(['sku', 'barcode'], $search));
                });
            })
            ->when($categoryId, fn ($query) => $query->where('category_id', $categoryId))
            ->when($status === 'active', fn ($query) => $query->whereNull('archived_at'))
            ->when($status === 'archived', fn ($query) => $query->whereNotNull('archived_at'))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        // Surface current stock (from the eager-loaded snapshot) on each variant.
        $items->getCollection()->each(
            fn (Item $item) => $item->variants->each->append('current_stock'),
        );

        $scope = $this->normalizeScope($request->query('scope'), $canViewAll);

        $stock = $scope === self::SCOPE_LOCAL
            ? ['scope' => self::SCOPE_LOCAL, 'branches' => [], 'consolidatedOnline' => null]
            : $this->consolidated($scope, $items);

        // Null means nothing cross-branch was attempted, so the picker's list is
        // read on its own. Anything else already cost one online read this
        // request — including the empty list a failed read leaves — and is reused
        // rather than attempted again.
        $roster = $stock['consolidatedOnline'] === null ? null : $stock['branches'];

        return Inertia::render('inventory/index', [
            'items' => $items,
            // A question about WRITES — whether Add product and the stock dialogs
            // can reach the business database. Deliberately not folded into
            // `consolidatedOnline`, which is a question about one read that
            // already happened: a failed roster read must not grey out the edit
            // buttons, and a healthy write path must not imply the cross-branch
            // numbers arrived.
            'online' => $this->online->possible(),
            'categories' => Category::active()->orderBy('name')->get(['id', 'name']),
            'filters' => [
                'search' => $search,
                'category' => $categoryId,
                'status' => $status,
            ],
            // Echoed back AFTER normalisation and after any fallback, so the
            // control always shows which shelf is on screen rather than which one
            // was asked for.
            'scope' => $stock['scope'],
            'canViewAll' => $canViewAll,
            'branches' => $this->pickerBranches($this->read, $roster, $canViewAll && Instance::isHydrated()),
            'consolidatedOnline' => $stock['consolidatedOnline'],
        ]);
    }

    /**
     * Attach another branch's stock — or every branch's — to the variants on this
     * page.
     *
     * The roster and the snapshots come back in ONE online read, so a page view
     * holds one Supavisor client slot rather than two.
     *
     * On failure this returns the local scope and attaches nothing, which leaves
     * the page byte-identical to its offline render: `current_stock` is still
     * there and still this device's. That is the whole reason the catalog query
     * stayed local — there is no half-consolidated state to design a screen for.
     *
     * @param  string  $scope  'all', or a branch id.
     * @param  LengthAwarePaginator<int, Item>  $items
     * @return array<string, mixed>
     */
    private function consolidated(string $scope, LengthAwarePaginator $items): array
    {
        $requested = $scope === self::SCOPE_ALL ? null : $scope;

        $variantIds = $items->getCollection()
            ->flatMap(fn (Item $item): Collection => $item->variants->pluck('id'))
            ->unique()
            ->values()
            ->all();

        $data = rescue(fn (): array => $this->read->run(
            function (Connection $remote) use ($requested, $variantIds, $items): array {
                // Disabled branches included: a disabled branch cannot receive
                // work, but the stock still sitting on its shelf is the
                // business's and has to be findable.
                $branches = BranchRoster::read($remote);

                // A branch id no longer in the roster means a stale or hand-typed
                // link. Show the whole business rather than an empty screen, and
                // let the echoed-back scope say what happened.
                $branchId = $requested !== null && isset($branches[$requested]) ? $requested : null;

                $this->attach($items, $this->remoteStock($remote, $branchId, $variantIds), $branches);

                return [
                    'scope' => $branchId ?? self::SCOPE_ALL,
                    'branches' => array_values($branches),
                    'consolidatedOnline' => true,
                ];
            },
            'Stock across branches',
        ), null, report: false);

        return $data ?? ['scope' => self::SCOPE_LOCAL, 'branches' => [], 'consolidatedOnline' => false];
    }

    /**
     * The upstream stock rows for one page of variants.
     *
     * Deliberately NOT grouped. The `all` scope has to show which branch holds
     * what — a total on its own cannot tell "twenty spread evenly" from "twenty
     * at one branch and none at the other four" — so aggregating here would throw
     * away the answer and force a second query to get it back. One page is at
     * most a few dozen variants; the sum is done in PHP.
     *
     * `variants` is never touched upstream, which is also how `variants.cost`
     * stays out of reach: {@see HidesCostFromNonOwners} is a
     * model hook and would not run on a query-builder row.
     *
     * @param  list<string>  $variantIds
     * @return Collection<int, \stdClass>
     */
    private function remoteStock(Connection $remote, ?string $branchId, array $variantIds): Collection
    {
        if ($variantIds === []) {
            return collect();
        }

        return $remote->table('stock_snapshots')
            ->whereIn('variant_id', $variantIds)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->get(['branch_id', 'variant_id', 'quantity']);
    }

    /**
     * Put the scoped figures on the variants the page will serialise.
     *
     * Two new attributes, and the naming is load-bearing. `current_stock` is a
     * get-only cached {@see Variant::currentStock()} accessor: writing to it
     * would land in `$attributes`, be ignored by `getAttribute()`, and render
     * THIS branch's numbers under another branch's heading with no error
     * anywhere. So the scoped figure gets a name of its own and the local one is
     * left exactly as it was — which is also what makes the failed-read fallback
     * indistinguishable from an ordinary local render.
     *
     * A branch with no row for a variant is OMITTED rather than shown as zero.
     * "Has never handled this product" and "has run out of it" are different
     * statements, and zero-filling would put a low-stock badge on every branch
     * that has never stocked half the catalog.
     *
     * These attributes are for serialisation only. Setting them marks the model
     * dirty, so nothing on this path may call `save()` on a variant.
     *
     * @param  LengthAwarePaginator<int, Item>  $items
     * @param  Collection<int, \stdClass>  $rows
     * @param  array<string, array{id: string, code: string, name: string}>  $branches
     */
    private function attach(LengthAwarePaginator $items, Collection $rows, array $branches): void
    {
        $byVariant = $rows->groupBy(fn (object $row): string => (string) $row->variant_id);

        // Roster order, which is by code — so the breakdown reads the same way
        // down the whole page instead of in whatever order Postgres answered.
        $order = array_flip(array_keys($branches));

        $items->getCollection()->each(function (Item $item) use ($byVariant, $branches, $order): void {
            $item->variants->each(function (Variant $variant) use ($byVariant, $branches, $order): void {
                $rows = $byVariant->get((string) $variant->getKey(), collect())
                    ->sortBy(fn (object $row): int => $order[(string) $row->branch_id] ?? PHP_INT_MAX)
                    ->values();

                $variant->setAttribute('branch_stock', $rows->map(fn (object $row): array => [
                    'branch' => $branches[(string) $row->branch_id]['code'] ?? '??',
                    'quantity' => (int) $row->quantity,
                ])->all());

                $variant->setAttribute('scoped_stock', (int) $rows->sum('quantity'));
            });
        });
    }

    /**
     * Which branches' stock to read, snapped to what this viewer and this device
     * can actually serve. Matches {@see StockHistoryController} deliberately: a
     * Cashier, or a device that was never connected to a business database, gets
     * `local` back rather than a 403 — the local shelf is what they are here for.
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
     * Show the "new item" form.
     */
    public function create(): Response
    {
        Gate::authorize('manage-inventory');

        return Inertia::render('inventory/create', [
            'categories' => Category::active()->orderBy('name')->get(['id', 'name']),
            'online' => $this->online->possible(),
        ]);
    }

    public function store(StoreItemRequest $request, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validated();

        $item = $this->online->run(function () use ($data, $audit) {
            $item = Item::create([
                'name' => $data['name'],
                'unit' => $data['unit'],
                'category_id' => $data['category_id'] ?? null,
                'description' => $data['description'] ?? null,
            ]);

            $this->online->mirror($item);

            foreach ($data['variants'] as $variant) {
                $this->online->mirror($item->variants()->create($this->variantAttributes($variant)));
            }

            $audit->record(
                action: 'inventory.item.created',
                auditable: $item,
                description: "Created item \"{$item->name}\".",
                new: $this->itemSnapshot($item->load('variants')),
            );

            return $item;
        }, 'A product');

        return to_route('inventory')->with('toast', [
            'type' => 'success',
            'message' => 'Item created.',
        ]);
    }

    /**
     * Show the edit form with the item's active variants.
     */
    public function edit(Item $item): Response
    {
        Gate::authorize('manage-inventory');

        $item->load(['variants' => fn ($query) => $query->active()->orderBy('created_at')]);

        return Inertia::render('inventory/edit', [
            'item' => $item,
            'categories' => Category::active()->orderBy('name')->get(['id', 'name']),
            'online' => $this->online->possible(),
        ]);
    }

    public function update(UpdateItemRequest $request, Item $item, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validated();

        $before = $this->itemSnapshot($item->load('variants'));

        $this->online->run(function () use ($data, $item, $audit, $before) {
            // `name` is deliberately absent: item names are immutable after
            // creation (UpdateItemRequest rejects a differing name; even a
            // case-only resubmission must not drift the stored value).
            $item->update([
                'unit' => $data['unit'],
                'category_id' => $data['category_id'] ?? null,
                'description' => $data['description'] ?? null,
            ]);

            $existing = $item->variants()->get()->keyBy('id');
            $keptIds = [];

            foreach ($data['variants'] as $variant) {
                $id = $variant['id'] ?? null;

                if ($id !== null && $existing->has($id)) {
                    $existing->get($id)->update($this->variantAttributes($variant));
                    $this->online->mirror($existing->get($id));
                    $keptIds[] = $id;
                } else {
                    $created = $item->variants()->create($this->variantAttributes($variant));
                    $this->online->mirror($created);
                    $keptIds[] = $created->id;
                }
            }

            // Variants removed from the form are archived, never deleted. Newly
            // created variants are in $keptIds, so they are not swept here.
            // Read back before the mass update so the archived rows can be
            // mirrored: a bulk builder update returns a count, not the rows, and
            // a variant archived only locally would go on selling at every other
            // branch.
            $archiving = $item->variants()->active()->whereNotIn('id', $keptIds)->get();

            $item->variants()
                ->active()
                ->whereNotIn('id', $keptIds)
                ->update(['archived_at' => now()]);

            $this->online->mirror(...$archiving->fresh()->all());

            $this->online->mirror($item);

            // Every item must keep at least one active variant.
            if ($item->variants()->active()->count() === 0) {
                throw ValidationException::withMessages([
                    'variants' => 'An item must have at least one active variant.',
                ]);
            }

            $audit->record(
                action: 'inventory.item.updated',
                auditable: $item,
                description: "Updated item \"{$item->name}\".",
                old: $before,
                new: $this->itemSnapshot($item->load(['variants' => fn ($query) => $query->active()])),
            );
        }, 'A product');

        return to_route('inventory')->with('toast', [
            'type' => 'success',
            'message' => 'Item updated.',
        ]);
    }

    public function archive(Item $item, AuditRecorder $audit): RedirectResponse
    {
        Gate::authorize('manage-inventory');

        $this->online->run(function () use ($item, $audit): void {
            $item->update(['archived_at' => now()]);

            $this->online->mirror($item);

            $audit->record(
                action: 'inventory.item.archived',
                auditable: $item,
                description: "Archived item \"{$item->name}\".",
            );
        }, 'A product');

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Item archived.',
        ]);
    }

    public function restore(Item $item, AuditRecorder $audit): RedirectResponse
    {
        Gate::authorize('manage-inventory');

        $this->online->run(function () use ($item, $audit): void {
            $item->update(['archived_at' => null]);

            $this->online->mirror($item);

            $audit->record(
                action: 'inventory.item.restored',
                auditable: $item,
                description: "Restored item \"{$item->name}\".",
            );
        }, 'A product');

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Item restored.',
        ]);
    }

    /**
     * Build the persistable variant attributes. `cost` is present only when the
     * actor may edit it (stripped upstream in the request otherwise), so it is
     * copied through conditionally and never overwritten with null.
     *
     * @param  array<string, mixed>  $variant
     * @return array<string, mixed>
     */
    private function variantAttributes(array $variant): array
    {
        $name = $variant['name'] ?? null;
        $name = is_string($name) ? trim($name) : $name;

        $attributes = [
            // A blank name persists as null: single-variant ("no variant") items
            // keep one unnamed variant that simply references the parent item.
            'name' => ($name === null || $name === '') ? null : $name,
            'sku' => $variant['sku'] ?? null,
            'barcode' => $variant['barcode'] ?? null,
            'selling_price' => $variant['selling_price'],
            'low_stock_threshold' => $variant['low_stock_threshold'] ?? 0,
        ];

        if (array_key_exists('cost', $variant)) {
            $attributes['cost'] = $variant['cost'];
        }

        return $attributes;
    }

    /**
     * A compact before/after snapshot of an item and its variants for the audit
     * trail. `cost` is included so the Owner sees price/cost history; the read
     * layer redacts it for non-Owner viewers. Assumes variants are loaded.
     *
     * @return array<string, mixed>
     */
    private function itemSnapshot(Item $item): array
    {
        return [
            'name' => $item->name,
            'unit' => $item->unit,
            'category_id' => $item->category_id,
            'description' => $item->description,
            'variants' => $item->variants
                ->map(fn (Variant $variant) => [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'selling_price' => $variant->selling_price,
                    'cost' => $variant->cost,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Constrain the archived filter to a known tri-state, defaulting to active.
     */
    private function normalizeStatus(mixed $status): string
    {
        return in_array($status, ['active', 'archived', 'all'], true) ? $status : 'active';
    }
}
