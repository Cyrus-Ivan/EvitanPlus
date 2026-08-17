<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreItemRequest;
use App\Http\Requests\Inventory\UpdateItemRequest;
use App\Models\Category;
use App\Models\Item;
use App\Models\Variant;
use App\Support\Audit\AuditRecorder;
use App\Support\Http\FilterText;
use App\Support\Sync\OnlineWrite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The CATALOG: what the business sells, and nothing about how much of it is on
 * any shelf.
 *
 * Items and their variants are SHARED business data (§5.1). A product, its price
 * and whether it is archived are the same at every branch, so each write here is
 * an ONLINE WRITE that reaches the business database or does not happen at all
 * ({@see OnlineWrite}; M6.5).
 *
 * Reading the catalog is untouched and stays fully offline — the catalog a till
 * sells from is its local mirror.
 *
 * ## Stock left this controller in M13
 *
 * This page and {@see InventoryController}'s were one screen doing two jobs. The
 * two disagree about almost everything — authority (business-wide vs per-branch),
 * how a change is made (an online write vs a person confirming a movement
 * happened), and even the natural row (an item here, a VARIANT there) — so the
 * stock half moved out whole: the branch `scope` parameter, the consolidated
 * read, the `scoped_stock` attribute and the three per-variant actions. There is
 * no scope on this screen at all, because nothing on it is per-branch.
 *
 * What stayed is `low_stock_threshold`, which is edited on the item form beside
 * price and cost. It looks like a stock figure and is not one: it is a property
 * of the PRODUCT, the same number at every branch, and it travels with the rest
 * of the catalog on the same online write. Inventory displays it; here is where
 * it is set.
 */
class ItemController extends Controller
{
    public function __construct(
        private readonly OnlineWrite $online,
    ) {}

    /**
     * Paginated item catalog with search, category filter and archived filter.
     */
    public function index(Request $request): Response
    {
        $search = FilterText::orNull($request->query('search'));
        $categoryId = $request->query('category') ?: null;
        $status = $this->normalizeStatus($request->query('status'));

        $items = Item::query()
            ->with([
                'category:id,name',
                // No `stockSnapshot`: nothing on this page shows a shelf, and
                // eager-loading one per variant is a query paid for a column
                // that is never rendered.
                'variants' => fn ($query) => $query->active()->orderBy('created_at'),
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

        return Inertia::render('items/index', [
            'items' => $items,
            // A question about WRITES — whether New item, Edit and the CSV import
            // can reach the business database. The catalog READS fine offline in
            // every case, which is why this is a separate prop and not a guard on
            // the page.
            'online' => $this->online->possible(),
            'categories' => Category::active()->orderBy('name')->get(['id', 'name']),
            'filters' => [
                'search' => $search,
                'category' => $categoryId,
                'status' => $status,
            ],
        ]);
    }

    /**
     * Show the "new item" form.
     */
    public function create(): Response
    {
        Gate::authorize('manage-inventory');

        return Inertia::render('items/create', [
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

        return to_route('items.index')->with('toast', [
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

        return Inertia::render('items/edit', [
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

        return to_route('items.index')->with('toast', [
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
