import { BranchScopeSelect } from '@/components/branch-scope-select';
import EmptyState from '@/components/empty-state';
import BatchAdjustDialog from '@/components/inventory/batch-adjust-dialog';
import BatchTransferDialog from '@/components/inventory/batch-transfer-dialog';
import ImportOpeningStockDialog from '@/components/inventory/import-opening-stock-dialog';
import StockAdjustDialog from '@/components/inventory/stock-adjust-dialog';
import StockTransferDialog from '@/components/inventory/stock-transfer-dialog';
import { OfflineNotice } from '@/components/offline-notice';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import type { BranchScope, BreadcrumbItem, Category, Paginated, RosterBranch, Variant } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeftRight, History, Info, Package, SlidersHorizontal, Upload, WifiOff } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Inventory', href: '/inventory' }];

const ALL_CATEGORIES = 'all';

const LOCAL = 'local';

interface Filters {
    search: string | null;
    category: string | null;
    status: string;
}

/**
 * The one figure on this screen that is worth less than it looks.
 *
 * Another branch's stock is read from `stock_snapshots`, which is that branch's
 * BASELINE — the figure that last reached the business database. Sales it has
 * rung up and not yet pushed are not deducted, so the number reads high, and it
 * reads high in exactly the situation where somebody is about to promise a
 * customer that the other branch has one.
 */
function BaselineNotice() {
    return (
        <div role="status" className="text-muted-foreground flex items-start gap-2 rounded-md border border-dashed p-3 text-sm">
            <Info className="mt-0.5 size-4 shrink-0" />
            <p>
                <span className="text-foreground font-medium">These are the figures that last reached the business database.</span> A branch's sales
                that have not synced yet are not deducted, so a number here can read higher than what is actually on that branch's shelf.
            </p>
        </div>
    );
}

/**
 * Cross-branch stock was asked for and could not be reached.
 *
 * A compact line rather than a banner, and it sits under the picker rather than
 * at the top of the page, because when the device is offline the screen is
 * *already* carrying `OfflineNotice` — two amber boxes saying "no connection"
 * read as one warning repeated rather than as two different facts. The fact this
 * one adds is narrow: the scope you chose is not the scope you are looking at.
 */
function OfflineStockNotice() {
    return (
        <p role="alert" className="flex items-center gap-1.5 text-sm text-amber-700 dark:text-amber-500">
            <WifiOff className="size-3.5 shrink-0" />
            Showing this branch's own stock — the business database could not be reached.
        </p>
    );
}

/**
 * Is this variant low, on the shelf the current scope is showing?
 *
 * One shelf, always — the scope names a single branch — so the badge means the
 * same thing wherever it is pointed: the figure on screen is at or below the
 * reorder point. `scoped_stock` is the remote branch's; `current_stock` is this
 * device's, and is what remains when the scope is local or the read fell back.
 */
function isLowStock(variant: Variant): boolean {
    return (variant.scoped_stock ?? variant.current_stock ?? 0) <= variant.low_stock_threshold;
}

/**
 * STOCK: how much of each product is on one branch's shelf.
 *
 * One row per VARIANT, flat, with no expansion — which is the whole reason this
 * screen exists apart from Items. Stock lives on the variant, so a table whose
 * row is an item has to hide every figure one click deep behind a chevron, and
 * the numbers people came to read were the ones you could not see.
 *
 * Nothing here edits the catalog. The three actions ask for stock to MOVE, and
 * every one of them writes a pending row somebody at the affected branch has to
 * confirm — see `AdjustmentQueue`.
 */
export default function InventoryIndex({
    variants,
    categories,
    filters,
    online,
    scope,
    canViewAll,
    branches,
    consolidatedOnline,
    openingWindow,
}: {
    variants: Paginated<Variant>;
    categories: Pick<Category, 'id' | 'name'>[];
    filters: Filters;
    // Whether an online WRITE is possible — the stock dialogs and the batch
    // dialog. Independent of `consolidatedOnline`, which is about one read.
    online: boolean;
    scope: BranchScope;
    canViewAll: boolean;
    branches: RosterBranch[];
    consolidatedOnline: boolean | null;
    // Whether this branch has yet to sell anything, and so may still set its
    // stock from a spreadsheet. Server-decided; the control below is a
    // convenience, not the rule.
    openingWindow: boolean;
}) {
    const { hasRoleAtLeast } = usePermissions();
    const canManage = hasRoleAtLeast('admin');

    const [search, setSearch] = useState(filters.search ?? '');
    const [adjustTarget, setAdjustTarget] = useState<Variant | null>(null);
    // A separate piece of state rather than one with a mode, because they are two
    // dialogs — an adjustment is one claim about one shelf, a transfer is two
    // claims about two, answered by two people.
    const [transferTarget, setTransferTarget] = useState<Variant | null>(null);
    // And again for the batch pair, for the same reason: one branch and a
    // direction is not two branches and no direction, and a quantity on a line
    // does not mean the same thing in both.
    const [adjustBatching, setAdjustBatching] = useState(false);
    const [transferBatching, setTransferBatching] = useState(false);
    const [importing, setImporting] = useState(false);
    const isFirstRender = useRef(true);

    // Only ever true on a branch that has never sold anything, and only for an
    // Admin or the Owner. The import is scoped to this device's shelf, so it is
    // offered on the local scope alone — under another branch's heading the
    // button would look like it set that branch's stock, and it does not.
    const canImportOpeningStock = canManage && openingWindow && scope === LOCAL;

    const applyFilters = (next: Partial<Filters> & { scope?: string }) => {
        const merged = { search, category: filters.category, status: filters.status, ...next };
        router.get(
            route('inventory'),
            {
                search: merged.search || undefined,
                category: merged.category && merged.category !== ALL_CATEGORIES ? merged.category : undefined,
                status: merged.status && merged.status !== 'active' ? merged.status : undefined,
                // Carried through EVERY change. The search box is debounced onto
                // this function, so dropping the scope here would silently walk
                // the reader back to this branch on the next keystroke.
                scope: (next.scope ?? scope) === LOCAL ? undefined : (next.scope ?? scope),
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                // `branches` is deliberately out of this list: the roster costs an
                // online read, and typing in the search box must not pay for it.
                // The scoped figures ride along inside `variants`.
                only: ['variants', 'filters', 'scope', 'consolidatedOnline'],
            },
        );
    };

    // Debounce the search box so we don't fire a request per keystroke.
    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            return;
        }
        const timeout = setTimeout(() => applyFilters({ search }), 300);
        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Inventory" />
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4">
                {/* Stock is shared with the business: a recount that reached only
                    this device would be reverted by the next cycle's baseline, so
                    asking for one is an online write like every other. */}
                {!online && <OfflineNotice what="Stock updates" />}
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search product, variant, SKU, barcode"
                            className="w-72"
                        />
                        <Select value={filters.category ?? ALL_CATEGORIES} onValueChange={(value) => applyFilters({ category: value })}>
                            <SelectTrigger className="w-44">
                                <SelectValue placeholder="All categories" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL_CATEGORIES}>All categories</SelectItem>
                                {categories.map((category) => (
                                    <SelectItem key={category.id} value={category.id}>
                                        {category.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select value={filters.status} onValueChange={(value) => applyFilters({ status: value })}>
                            <SelectTrigger className="w-36">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="active">Active</SelectItem>
                                <SelectItem value="archived">Archived</SelectItem>
                                <SelectItem value="all">All</SelectItem>
                            </SelectContent>
                        </Select>
                        {canViewAll && (
                            <BranchScopeSelect
                                scope={scope}
                                branches={branches}
                                onChange={(next) => applyFilters({ scope: next })}
                                label="Stock shown for"
                                // One branch at a time here: see the component's
                                // docblock and `InventoryController`'s.
                                allowAll={false}
                            />
                        )}
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {canImportOpeningStock && (
                            <Button variant="outline" onClick={() => setImporting(true)}>
                                <Upload className="size-4" /> Import opening stock
                            </Button>
                        )}
                        {/* The same pair as the per-row actions below, asked for
                            many products at once. */}
                        {canManage && (
                            <>
                                <Button variant="outline" onClick={() => setTransferBatching(true)}>
                                    <ArrowLeftRight className="size-4" /> Batch transfer
                                </Button>
                                <Button onClick={() => setAdjustBatching(true)}>
                                    <SlidersHorizontal className="size-4" /> Batch adjustment
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                {/* Said once, where the button is, because it is the whole reason
                    the button is there and the whole reason it will go away. */}
                {canImportOpeningStock && (
                    <p className="text-muted-foreground text-sm">
                        This branch has not sold anything yet, so its opening stock can still be set from a spreadsheet. After the first sale, stock
                        moves only through a request somebody at the branch confirms.
                    </p>
                )}

                {consolidatedOnline === false && <OfflineStockNotice />}
                {consolidatedOnline === true && <BaselineNotice />}

                {variants.data.length === 0 ? (
                    <EmptyState
                        icon={Package}
                        title="No products found"
                        description={
                            filters.search || filters.category
                                ? 'Try adjusting your search or filters.'
                                : 'Add products under Items, then set their opening stock here.'
                        }
                    />
                ) : (
                    <div className="rounded-xl border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Product</TableHead>
                                    <TableHead>Variant</TableHead>
                                    <TableHead>SKU</TableHead>
                                    <TableHead>Barcode</TableHead>
                                    <TableHead>Category</TableHead>
                                    <TableHead className="text-right">Stock</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Low-stock alert</TableHead>
                                    {canManage && <TableHead className="text-right">Actions</TableHead>}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {variants.data.map((variant) => {
                                    // `scoped_stock` is the figure for the branch scope on
                                    // screen; `current_stock` is this device's own, and is
                                    // what remains when the scope is local or the read fell
                                    // back.
                                    const stock = variant.scoped_stock ?? variant.current_stock ?? 0;
                                    // The ITEM's flag, not the variant's: the query only
                                    // returns active variants, so a row is here because its
                                    // product is, and archiving a product is what takes it
                                    // off sale at every branch.
                                    const archived = variant.item?.archived_at != null;
                                    const isLow = isLowStock(variant);

                                    return (
                                        <TableRow
                                            key={variant.id}
                                            className={isLow ? 'bg-red-200 hover:bg-red-300 dark:bg-red-900/60 dark:hover:bg-red-800/60' : undefined}
                                        >
                                            <TableCell className="font-medium">{variant.item?.name ?? '—'}</TableCell>
                                            {/* An unnamed variant is the "no variant" case:
                                                one product, one price, nothing to
                                                distinguish. Dashed rather than repeating
                                                the product name back. */}
                                            <TableCell>{variant.name || '—'}</TableCell>
                                            <TableCell className="tabular-nums">{variant.sku ?? '—'}</TableCell>
                                            <TableCell className="tabular-nums">{variant.barcode ?? '—'}</TableCell>
                                            <TableCell>{variant.item?.category?.name ?? '—'}</TableCell>
                                            <TableCell className="text-right tabular-nums">{stock}</TableCell>
                                            <TableCell>
                                                {archived ? <Badge variant="secondary">Archived</Badge> : <Badge>Active</Badge>}
                                                {/* Text companion to the row tint, so low
                                                    stock is never colour-only. */}
                                                {isLow && (
                                                    <Badge variant="destructive" className="ml-1 font-normal">
                                                        Low stock
                                                    </Badge>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">{variant.low_stock_threshold}</TableCell>
                                            {canManage && (
                                                <TableCell className="text-right">
                                                    <div className="flex justify-end gap-1">
                                                        <Button variant="ghost" size="sm" onClick={() => setAdjustTarget(variant)}>
                                                            <SlidersHorizontal className="size-4" /> Adjust stocks
                                                        </Button>
                                                        <Button variant="ghost" size="sm" onClick={() => setTransferTarget(variant)}>
                                                            <ArrowLeftRight className="size-4" /> Transfer
                                                        </Button>
                                                        <Button variant="ghost" size="sm" asChild>
                                                            <Link href={route('stock-history.index', { variant: variant.id })}>
                                                                <History className="size-4" /> History
                                                            </Link>
                                                        </Button>
                                                    </div>
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </div>
                )}

                <Pagination links={variants.links} />
            </div>

            {canManage && (
                <>
                    <StockAdjustDialog
                        open={adjustTarget !== null}
                        onOpenChange={(open) => !open && setAdjustTarget(null)}
                        variant={adjustTarget}
                        itemName={adjustTarget?.item?.name}
                    />
                    <StockTransferDialog
                        open={transferTarget !== null}
                        onOpenChange={(open) => !open && setTransferTarget(null)}
                        variant={transferTarget}
                        itemName={transferTarget?.item?.name}
                    />
                    <BatchTransferDialog open={transferBatching} onOpenChange={setTransferBatching} />
                    <BatchAdjustDialog open={adjustBatching} onOpenChange={setAdjustBatching} />
                </>
            )}

            {canImportOpeningStock && <ImportOpeningStockDialog open={importing} onOpenChange={setImporting} online={online} scope={scope} />}
        </AppLayout>
    );
}
