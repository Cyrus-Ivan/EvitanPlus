<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Concerns\OffersBranchScope;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Concerns\HidesCostFromNonOwners;
use App\Models\Instance;
use App\Models\Variant;
use App\Support\Sync\BranchRoster;
use App\Support\Sync\OnlineRead;
use App\Support\Sync\OnlineWrite;
use App\Support\Sync\RemoteStock;
use App\Support\Sync\StockReconciler;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock, and only stock (M13).
 *
 * This screen and {@see ItemController}'s used to be one page doing two jobs.
 * They are separate now because they disagree about almost everything: the
 * catalog is business-wide, mirrored and edited by an online write; stock is
 * per-branch, ledger-derived, and moved only by a person at the branch agreeing
 * that it moved. The catalog's natural row is an ITEM; stock's is a VARIANT —
 * price and SKU live on the variant, and so does the shelf. Putting both in one
 * table is what forced every stock figure one click deep behind a chevron.
 *
 * So the row here is one variant, flat, with no expansion. Nothing on this page
 * writes the catalog; nothing on the Items page reads a shelf.
 *
 * ## The `scope` parameter (M12): stock, and only stock
 *
 * Stock is the one thing on this screen that is per-branch, so it is the only
 * thing the scope changes. `local` (the default) reads the local snapshot and is
 * exactly what this screen has always shown; a branch id reads `stock_snapshots`
 * live from the business database through {@see OnlineRead}. Admin+, with a
 * Cashier snapped back to `local` rather than refused.
 *
 * **One branch at a time, and no `all`** — the one screen carrying the branch
 * scope that does not offer it. Everywhere else a wider scope adds rows to a
 * list; here it would have to add them to a *number*, and a business-wide total
 * per variant says nothing on its own: twenty units is twenty spread evenly or
 * twenty at one branch and none at the other four. That needed a per-branch
 * breakdown under every figure to be readable at all, which is a lot of distinct
 * meaning stacked into one table cell. `?scope=all` is snapped back to `local`
 * like any other scope this screen cannot serve.
 *
 * **The variant query is local in EVERY scope.** The catalog is
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
class InventoryController extends Controller
{
    use OffersBranchScope;

    /** This branch's own shelf: the local snapshot, works offline. */
    private const SCOPE_LOCAL = 'local';

    /**
     * One variant per row, so 25 rather than the catalog's 15 — a row here is
     * narrower than an item with its variants folded underneath it.
     */
    private const PER_PAGE = 25;

    public function __construct(
        private readonly OnlineWrite $online,
        private readonly OnlineRead $read,
    ) {}

    /**
     * Paginated variant list with its stock, searchable and branch-scoped.
     */
    public function index(Request $request): Response
    {
        $viewer = $request->user();
        $canViewAll = $viewer->isAdmin() || $viewer->isOwner();

        $search = trim((string) $request->query('search', '')) ?: null;
        $categoryId = $request->query('category') ?: null;
        $status = $this->normalizeStatus($request->query('status'));

        $variants = Variant::query()
            ->active()
            ->join('items', 'items.id', '=', 'variants.item_id')
            ->with(['item:id,name,unit,category_id,archived_at', 'item.category:id,name', 'stockSnapshot'])
            ->when($search, function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->whereLikeAny(['variants.sku', 'variants.barcode', 'variants.name'], $search)
                        ->orWhereLike('items.name', $search);
                });
            })
            ->when($categoryId, fn ($query) => $query->where('items.category_id', $categoryId))
            ->when($status === 'active', fn ($query) => $query->whereNull('items.archived_at'))
            ->when($status === 'archived', fn ($query) => $query->whereNotNull('items.archived_at'))
            ->orderBy('items.name')
            ->orderBy('variants.created_at')
            // Load-bearing: without it the join's `items.id` lands on the model as
            // `id` and every row wears the wrong primary key — which would send
            // the adjust dialog, the transfer dialog and the history link at an
            // ITEM id, all of them silently.
            ->select('variants.*')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $variants->getCollection()->each->append('current_stock');

        $scope = $this->normalizeScope($request->query('scope'), $canViewAll);

        $stock = $scope === self::SCOPE_LOCAL
            ? ['scope' => self::SCOPE_LOCAL, 'branches' => [], 'consolidatedOnline' => null]
            : $this->consolidated($scope, $variants);

        // Null means nothing cross-branch was attempted, so the picker's list is
        // read on its own. Anything else already cost one online read this
        // request — including the empty list a failed read leaves — and is reused
        // rather than attempted again.
        $roster = $stock['consolidatedOnline'] === null ? null : $stock['branches'];

        return Inertia::render('inventory/index', [
            'variants' => $variants,
            // A question about WRITES — whether the stock dialogs and the batch
            // dialog can reach the business database. Deliberately not folded into
            // `consolidatedOnline`, which is a question about one read that
            // already happened: a failed roster read must not grey out the adjust
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
     * Attach another branch's stock to the variants on this page.
     *
     * The roster and the snapshots come back in ONE online read, so a page view
     * holds one Supavisor client slot rather than two.
     *
     * On failure this returns the local scope and attaches nothing, which leaves
     * the page byte-identical to its offline render: `current_stock` is still
     * there and still this device's. That is the whole reason the variant query
     * stayed local — there is no half-consolidated state to design a screen for.
     *
     * @param  string  $scope  A branch id, and never this branch's own.
     * @param  LengthAwarePaginator<int, Variant>  $variants
     * @return array<string, mixed>
     */
    private function consolidated(string $scope, LengthAwarePaginator $variants): array
    {
        $variantIds = $variants->getCollection()
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        $data = rescue(fn (): array => $this->read->run(
            function (Connection $remote) use ($scope, $variantIds, $variants): array {
                // Disabled branches included: a disabled branch cannot receive
                // work, but the stock still sitting on its shelf is the
                // business's and has to be findable.
                $branches = BranchRoster::read($remote);

                // A branch id no longer in the roster means a stale or hand-typed
                // link. Fall back exactly as a failed read does, rather than
                // reading no rows and printing a page of confident zeros.
                if (! isset($branches[$scope])) {
                    return [
                        'scope' => self::SCOPE_LOCAL,
                        'branches' => array_values($branches),
                        'consolidatedOnline' => false,
                    ];
                }

                $this->attach($variants, RemoteStock::read($remote, $scope, $variantIds));

                return [
                    'scope' => $scope,
                    'branches' => array_values($branches),
                    'consolidatedOnline' => true,
                ];
            },
            'Stock across branches',
        ), null, report: false);

        return $data ?? ['scope' => self::SCOPE_LOCAL, 'branches' => [], 'consolidatedOnline' => false];
    }

    /**
     * Put the scoped figure on the variants the page will serialise.
     *
     * The naming is load-bearing. `current_stock` is a get-only cached
     * {@see Variant::currentStock()} accessor: writing to it would land in
     * `$attributes`, be ignored by `getAttribute()`, and render THIS branch's
     * numbers under another branch's heading with no error anywhere. So the
     * scoped figure gets a name of its own and the local one is left exactly as
     * it was — which is also what makes the failed-read fallback
     * indistinguishable from an ordinary local render.
     *
     * A variant that branch holds no row for reads zero: upstream a snapshot is
     * written the first time stock moves, so no row means none has, and none has
     * moved is none on the shelf.
     *
     * The attribute is for serialisation only. Setting it marks the model dirty,
     * so nothing on this path may call `save()` on a variant.
     *
     * @param  LengthAwarePaginator<int, Variant>  $variants
     * @param  array<string, int>  $stock  Quantity keyed by variant id.
     */
    private function attach(LengthAwarePaginator $variants, array $stock): void
    {
        $variants->getCollection()->each(function (Variant $variant) use ($stock): void {
            $variant->setAttribute('scoped_stock', $stock[(string) $variant->getKey()] ?? 0);
        });
    }

    /**
     * WHICH branch's stock to read, snapped to what this viewer and this device
     * can actually serve. Matches {@see StockHistoryController} deliberately: a
     * Cashier, or a device that was never connected to a business database, gets
     * `local` back rather than a 403 — the local shelf is what they are here for.
     *
     * Singular, unlike every other screen: `all` is not a scope this one has, so
     * it falls through to the same `local` that `?scope=junk` gets. That is what
     * keeps the option from coming back by URL after the picker stopped offering
     * it (see the class docblock for why it does not).
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

        return Str::isUuid($scope) ? $scope : self::SCOPE_LOCAL;
    }

    /**
     * Constrain the archived filter to a known tri-state, defaulting to active.
     *
     * The flag read is the ITEM's: a variant of an archived item is not on sale
     * whatever its own `archived_at` says, and the variant query is already
     * filtered to active variants by `Variant::active()`.
     *
     * `cost` never appears on this screen in any scope, so
     * {@see HidesCostFromNonOwners} has nothing to do here.
     */
    private function normalizeStatus(mixed $status): string
    {
        return in_array($status, ['active', 'archived', 'all'], true) ? $status : 'active';
    }
}
