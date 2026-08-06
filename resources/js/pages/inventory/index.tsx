import { BranchScopeSelect } from '@/components/branch-scope-select';
import { useConfirm } from '@/components/confirm-dialog';
import EmptyState from '@/components/empty-state';
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
import type { BranchScope, BreadcrumbItem, Category, Item, Paginated, RosterBranch, Variant } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeftRight, ChevronRight, History, Info, Package, Pencil, SlidersHorizontal, WifiOff } from 'lucide-react';
import { Fragment, useEffect, useRef, useState } from 'react';

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

function priceLabel(item: Item): string {
    const prices = (item.variants ?? []).map((v) => Number(v.selling_price)).filter((n) => !Number.isNaN(n));
    if (prices.length === 0) {
        return '—';
    }
    const min = Math.min(...prices);
    const max = Math.max(...prices);
    return min === max ? min.toFixed(2) : `${min.toFixed(2)} – ${max.toFixed(2)}`;
}

export default function InventoryIndex({
    items,
    categories,
    filters,
    online,
    scope,
    canViewAll,
    branches,
    consolidatedOnline,
}: {
    items: Paginated<Item>;
    categories: Pick<Category, 'id' | 'name'>[];
    filters: Filters;
    // Whether an online WRITE is possible — the catalog edits and the stock
    // dialogs. Independent of `consolidatedOnline`, which is about one read.
    online: boolean;
    scope: BranchScope;
    canViewAll: boolean;
    branches: RosterBranch[];
    consolidatedOnline: boolean | null;
}) {
    const { hasRoleAtLeast, can } = usePermissions();
    const confirm = useConfirm();
    const canManage = hasRoleAtLeast('admin');
    const canViewCost = can('view-item-cost');

    const [search, setSearch] = useState(filters.search ?? '');
    // Only one item's variant panel is open at a time.
    const [expandedId, setExpandedId] = useState<string | null>(null);
    // The variant currently open in the stock-adjust dialog (with its item name).
    const [adjustTarget, setAdjustTarget] = useState<{ variant: Variant; itemName: string } | null>(null);
    // The same, for the transfer dialog. Two pieces of state rather than one with
    // a mode, because they are two dialogs — an adjustment is one claim about one
    // shelf, a transfer is two claims about two, answered by two people.
    const [transferTarget, setTransferTarget] = useState<{ variant: Variant; itemName: string } | null>(null);
    const isFirstRender = useRef(true);

    const toggleExpanded = (id: string) => setExpandedId((prev) => (prev === id ? null : id));

    // Number of columns the variant detail row must span. Unchanged by the branch
    // scope on purpose: cross-branch stock is a breakdown inside the expanded
    // variant panel's Stock cell, not a new top-level column.
    const columnCount = 6 + (canManage ? 1 : 0) + 1;

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
                // The scoped figures ride along inside `items`.
                only: ['items', 'filters', 'scope', 'consolidatedOnline'],
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

    const archiveItem = async (item: Item) => {
        if (
            await confirm({
                title: `Archive "${item.name}"?`,
                description: 'It will be hidden from the catalog but kept in history. You can restore it later.',
                destructive: true,
                confirmLabel: 'Archive',
            })
        ) {
            router.patch(route('items.archive', item.id), {}, { preserveScroll: true });
        }
    };

    const restoreItem = async (item: Item) => {
        if (await confirm({ title: `Restore "${item.name}"?`, description: 'It will reappear in the active catalog.', confirmLabel: 'Restore' })) {
            router.patch(route('items.restore', item.id), {}, { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Inventory" />
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4">
                {/* Stock joined the catalog here in M7: a manual adjustment is a
                    statement about the business's figure, not this device's, so
                    it is an online write like every other edit on this screen. */}
                {!online && <OfflineNotice what="The product catalog and stock levels" />}
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search name, SKU, barcode" className="w-64" />
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
                                // docblock and `ItemController`'s.
                                allowAll={false}
                            />
                        )}
                    </div>

                    {/* One button, because it is the only one that acts on this
                        page. Stock History, Categories and Import / Export were
                        here until they got their own sidebar entries — they are
                        destinations, and a list of products is not where you
                        navigate from. */}
                    {canManage && (
                        <Button asChild>
                            <Link href={route('items.create')}>New item</Link>
                        </Button>
                    )}
                </div>

                {consolidatedOnline === false && <OfflineStockNotice />}
                {consolidatedOnline === true && <BaselineNotice />}

                {items.data.length === 0 ? (
                    <EmptyState
                        icon={Package}
                        title="No items found"
                        description={
                            filters.search || filters.category
                                ? 'Try adjusting your search or filters.'
                                : 'Create your first item to start building the catalog.'
                        }
                        action={
                            canManage ? (
                                <Button asChild>
                                    <Link href={route('items.create')}>New item</Link>
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <div className="rounded-xl border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-10" />
                                    <TableHead>Name</TableHead>
                                    <TableHead>Category</TableHead>
                                    <TableHead>Unit</TableHead>
                                    <TableHead>Variants</TableHead>
                                    <TableHead>Price</TableHead>
                                    <TableHead>Status</TableHead>
                                    {canManage && <TableHead className="text-right">Actions</TableHead>}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {items.data.map((item) => {
                                    const variants = item.variants ?? [];
                                    const isExpanded = expandedId === item.id;
                                    const hasVariants = variants.length > 0;
                                    // Tint the whole item row when any of its variants is at or below its
                                    // low-stock threshold, so low stock is visible without expanding.
                                    const hasLowStock = variants.some((v) => isLowStock(v));

                                    return (
                                        <Fragment key={item.id}>
                                            <TableRow
                                                className={
                                                    hasLowStock && !item.archived_at
                                                        ? 'bg-red-200 hover:bg-red-300 dark:bg-red-900/60 dark:hover:bg-red-800/60'
                                                        : undefined
                                                }
                                            >
                                                <TableCell className="w-10">
                                                    {hasVariants && (
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-7"
                                                            aria-label={isExpanded ? 'Hide variants' : 'Show variants'}
                                                            aria-expanded={isExpanded}
                                                            onClick={() => toggleExpanded(item.id)}
                                                        >
                                                            <ChevronRight
                                                                className={`size-4 transition-transform ${isExpanded ? 'rotate-90' : ''}`}
                                                            />
                                                        </Button>
                                                    )}
                                                </TableCell>
                                                <TableCell className="font-medium">{item.name}</TableCell>
                                                <TableCell>{item.category?.name ?? '—'}</TableCell>
                                                <TableCell>{item.unit}</TableCell>
                                                <TableCell>{variants.length}</TableCell>
                                                <TableCell>{priceLabel(item)}</TableCell>
                                                <TableCell>
                                                    {item.archived_at ? <Badge variant="secondary">Archived</Badge> : <Badge>Active</Badge>}
                                                    {/* Text companion to the row tint so low stock is not color-only. */}
                                                    {!item.archived_at && hasLowStock && (
                                                        <Badge variant="destructive" className="ml-1 font-normal">
                                                            Low stock
                                                        </Badge>
                                                    )}
                                                </TableCell>
                                                {canManage && (
                                                    <TableCell className="text-right">
                                                        <div className="flex justify-end gap-1">
                                                            <Button variant="ghost" size="sm" asChild>
                                                                <Link href={route('items.edit', item.id)}>
                                                                    <Pencil className="size-4" /> Edit
                                                                </Link>
                                                            </Button>
                                                            {item.archived_at ? (
                                                                <Button variant="ghost" size="sm" onClick={() => restoreItem(item)}>
                                                                    Restore
                                                                </Button>
                                                            ) : (
                                                                <Button variant="ghost" size="sm" onClick={() => archiveItem(item)}>
                                                                    Archive
                                                                </Button>
                                                            )}
                                                        </div>
                                                    </TableCell>
                                                )}
                                            </TableRow>
                                            {isExpanded && (
                                                <TableRow className="hover:bg-transparent">
                                                    <TableCell colSpan={columnCount} className="bg-muted/30 p-0">
                                                        <div className="px-4 py-3">
                                                            {/* Full width: the panel is the whole row's worth of
                                                                space, and the variant actions need it. */}
                                                            <Table className="w-full">
                                                                <TableHeader>
                                                                    <TableRow className="hover:bg-transparent">
                                                                        <TableHead>Variant</TableHead>
                                                                        <TableHead>SKU</TableHead>
                                                                        <TableHead>Barcode</TableHead>
                                                                        <TableHead className="text-right">Price</TableHead>
                                                                        {canViewCost && <TableHead className="text-right">Cost</TableHead>}
                                                                        <TableHead className="text-right">Stock</TableHead>
                                                                        <TableHead className="text-right">Low-stock alert</TableHead>
                                                                        {canManage && <TableHead className="text-right">Actions</TableHead>}
                                                                    </TableRow>
                                                                </TableHeader>
                                                                <TableBody>
                                                                    {variants.map((variant) => {
                                                                        // `scoped_stock` is the figure for the branch
                                                                        // scope on screen; `current_stock` is this
                                                                        // device's own, and is what remains when the
                                                                        // scope is local or the read fell back.
                                                                        const stock = variant.scoped_stock ?? variant.current_stock ?? 0;
                                                                        const isLow = isLowStock(variant);
                                                                        return (
                                                                            <TableRow
                                                                                key={variant.id}
                                                                                className={
                                                                                    isLow
                                                                                        ? 'bg-red-200 hover:bg-red-200 dark:bg-red-900/60 dark:hover:bg-red-900/60'
                                                                                        : 'hover:bg-transparent'
                                                                                }
                                                                            >
                                                                                <TableCell className="font-medium">
                                                                                    {variant.name || item.name}
                                                                                </TableCell>
                                                                                <TableCell className="tabular-nums">{variant.sku ?? '—'}</TableCell>
                                                                                <TableCell className="tabular-nums">
                                                                                    {variant.barcode ?? '—'}
                                                                                </TableCell>
                                                                                <TableCell className="text-right tabular-nums">
                                                                                    {Number(variant.selling_price).toFixed(2)}
                                                                                </TableCell>
                                                                                {canViewCost && (
                                                                                    <TableCell className="text-right tabular-nums">
                                                                                        {variant.cost != null ? Number(variant.cost).toFixed(2) : '—'}
                                                                                    </TableCell>
                                                                                )}
                                                                                <TableCell className="text-right">
                                                                                    <span className="inline-flex items-center gap-2 tabular-nums">
                                                                                        {stock}
                                                                                        {isLow && (
                                                                                            <Badge variant="destructive" className="font-normal">
                                                                                                Low
                                                                                            </Badge>
                                                                                        )}
                                                                                    </span>
                                                                                </TableCell>
                                                                                <TableCell className="text-right tabular-nums">
                                                                                    {variant.low_stock_threshold}
                                                                                </TableCell>
                                                                                {canManage && (
                                                                                    <TableCell className="text-right">
                                                                                        <div className="flex justify-end gap-1">
                                                                                            <Button
                                                                                                variant="ghost"
                                                                                                size="sm"
                                                                                                onClick={() =>
                                                                                                    setAdjustTarget({ variant, itemName: item.name })
                                                                                                }
                                                                                            >
                                                                                                <SlidersHorizontal className="size-4" /> Adjust stocks
                                                                                            </Button>
                                                                                            <Button
                                                                                                variant="ghost"
                                                                                                size="sm"
                                                                                                onClick={() =>
                                                                                                    setTransferTarget({
                                                                                                        variant,
                                                                                                        itemName: item.name,
                                                                                                    })
                                                                                                }
                                                                                            >
                                                                                                <ArrowLeftRight className="size-4" /> Transfer
                                                                                            </Button>
                                                                                            <Button variant="ghost" size="sm" asChild>
                                                                                                <Link
                                                                                                    href={route('stock-history.index', {
                                                                                                        variant: variant.id,
                                                                                                    })}
                                                                                                >
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
                                                    </TableCell>
                                                </TableRow>
                                            )}
                                        </Fragment>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </div>
                )}

                <Pagination links={items.links} />
            </div>

            {canManage && (
                <>
                    <StockAdjustDialog
                        open={adjustTarget !== null}
                        onOpenChange={(open) => !open && setAdjustTarget(null)}
                        variant={adjustTarget?.variant ?? null}
                        itemName={adjustTarget?.itemName}
                    />
                    <StockTransferDialog
                        open={transferTarget !== null}
                        onOpenChange={(open) => !open && setTransferTarget(null)}
                        variant={transferTarget?.variant ?? null}
                        itemName={transferTarget?.itemName}
                    />
                </>
            )}
        </AppLayout>
    );
}
