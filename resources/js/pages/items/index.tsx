import { useConfirm } from '@/components/confirm-dialog';
import EmptyState from '@/components/empty-state';
import ImportItemsDialog from '@/components/inventory/import-items-dialog';
import { OfflineNotice } from '@/components/offline-notice';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, Category, Item, Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, FileSpreadsheet, Package, Pencil } from 'lucide-react';
import { Fragment, useEffect, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Items', href: '/items' }];

const ALL_CATEGORIES = 'all';

interface Filters {
    search: string | null;
    category: string | null;
    status: string;
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

/**
 * The CATALOG: what the business sells.
 *
 * This was `/inventory` until M13, when stock moved to a screen of its own. What
 * left with it: the stock and low-stock columns, the Adjust / Transfer / History
 * buttons, the branch scope picker and both of its notices. Nothing on this page
 * is per-branch any more, so there is nothing here for a scope to change.
 *
 * The variant panel stayed. It is the only place SKU, barcode and cost are
 * legible at a glance, and those are catalog facts — an item is a name and a
 * price list, and the price list is its variants.
 */
export default function ItemsIndex({
    items,
    categories,
    filters,
    online,
}: {
    items: Paginated<Item>;
    categories: Pick<Category, 'id' | 'name'>[];
    filters: Filters;
    // Whether an online WRITE is possible. The catalog READS fine offline, so
    // this greys out the edits and the import rather than the page.
    online: boolean;
}) {
    const { hasRoleAtLeast, can } = usePermissions();
    const confirm = useConfirm();
    const canManage = hasRoleAtLeast('admin');
    const canViewCost = can('view-item-cost');

    const [search, setSearch] = useState(filters.search ?? '');
    // Only one item's variant panel is open at a time.
    const [expandedId, setExpandedId] = useState<string | null>(null);
    const [importing, setImporting] = useState(false);
    const isFirstRender = useRef(true);

    const toggleExpanded = (id: string) => setExpandedId((prev) => (prev === id ? null : id));

    // Number of columns the variant detail row must span.
    const columnCount = 6 + (canManage ? 1 : 0) + 1;

    const applyFilters = (next: Partial<Filters>) => {
        const merged = { search, category: filters.category, status: filters.status, ...next };
        router.get(
            route('items.index'),
            {
                search: merged.search || undefined,
                category: merged.category && merged.category !== ALL_CATEGORIES ? merged.category : undefined,
                status: merged.status && merged.status !== 'active' ? merged.status : undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['items', 'filters'],
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
            <Head title="Items" />
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4">
                {/* The catalog is shared business data: a product, its price and
                    whether it is archived are the same at every branch, so every
                    edit here is an online write that arrives or does not happen. */}
                {!online && <OfflineNotice what="The product catalog" />}
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
                    </div>

                    {canManage && (
                        <div className="flex items-center gap-2">
                            <Button variant="outline" onClick={() => setImporting(true)}>
                                <FileSpreadsheet className="size-4" /> Import CSV
                            </Button>
                            <Button asChild>
                                <Link href={route('items.create')}>New item</Link>
                            </Button>
                        </div>
                    )}
                </div>

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

                                    return (
                                        <Fragment key={item.id}>
                                            <TableRow>
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
                                                            <Table className="w-full">
                                                                <TableHeader>
                                                                    <TableRow className="hover:bg-transparent">
                                                                        <TableHead>Variant</TableHead>
                                                                        <TableHead>SKU</TableHead>
                                                                        <TableHead>Barcode</TableHead>
                                                                        <TableHead className="text-right">Price</TableHead>
                                                                        {canViewCost && <TableHead className="text-right">Cost</TableHead>}
                                                                        <TableHead className="text-right">Low-stock alert</TableHead>
                                                                    </TableRow>
                                                                </TableHeader>
                                                                <TableBody>
                                                                    {variants.map((variant) => (
                                                                        <TableRow key={variant.id} className="hover:bg-transparent">
                                                                            <TableCell className="font-medium">{variant.name || item.name}</TableCell>
                                                                            <TableCell className="tabular-nums">{variant.sku ?? '—'}</TableCell>
                                                                            <TableCell className="tabular-nums">{variant.barcode ?? '—'}</TableCell>
                                                                            <TableCell className="text-right tabular-nums">
                                                                                {Number(variant.selling_price).toFixed(2)}
                                                                            </TableCell>
                                                                            {canViewCost && (
                                                                                <TableCell className="text-right tabular-nums">
                                                                                    {variant.cost != null ? Number(variant.cost).toFixed(2) : '—'}
                                                                                </TableCell>
                                                                            )}
                                                                            {/* Displayed, not edited: the threshold is a
                                                                                property of the product and belongs on the
                                                                                item form with price and cost. Inventory
                                                                                shows it beside the shelf it applies to. */}
                                                                            <TableCell className="text-right tabular-nums">
                                                                                {variant.low_stock_threshold}
                                                                            </TableCell>
                                                                        </TableRow>
                                                                    ))}
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

            {canManage && <ImportItemsDialog open={importing} onOpenChange={setImporting} online={online} />}
        </AppLayout>
    );
}
