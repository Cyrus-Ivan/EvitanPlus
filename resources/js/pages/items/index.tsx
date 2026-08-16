import EmptyState from '@/components/data/empty-state';
import Pagination from '@/components/data/pagination';
import { ArchivedBadge } from '@/components/data/status-badge';
import TableCard, { NumericCell } from '@/components/data/table-card';
import CategoryFilter from '@/components/filters/category-filter';
import FilterBar from '@/components/filters/filter-bar';
import SearchInput from '@/components/filters/search-input';
import StatusFilter from '@/components/filters/status-filter';
import { useConfirm } from '@/components/forms/confirm-dialog';
import ImportItemsDialog from '@/components/inventory/import-items-dialog';
import PageShell from '@/components/page/page-shell';
import { OfflineNotice } from '@/components/sync/offline-notice';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useDebouncedFilter, useListFilters } from '@/hooks/use-list-filters';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { ALL } from '@/lib/filters';
import type { BreadcrumbItem, Category, Item, Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, FileSpreadsheet, Package, Pencil } from 'lucide-react';
import { Fragment, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Items', href: '/items' }];

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

    const toggleExpanded = (id: string) => setExpandedId((prev) => (prev === id ? null : id));

    // Number of columns the variant detail row must span.
    const columnCount = 6 + (canManage ? 1 : 0) + 1;

    const { apply } = useListFilters<Filters>({
        url: route('items.index'),
        values: { ...filters, search },
        defaults: { category: ALL, status: 'active' },
        only: ['items', 'filters'],
    });

    useDebouncedFilter(search, (value) => apply({ search: value }));

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
            <PageShell>
                {/* The catalog is shared business data: a product, its price and
                    whether it is archived are the same at every branch, so every
                    edit here is an online write that arrives or does not happen. */}
                {!online && <OfflineNotice what="The product catalog" />}
                <FilterBar
                    actions={
                        canManage && (
                            <>
                                <Button variant="outline" onClick={() => setImporting(true)}>
                                    <FileSpreadsheet className="size-4" /> Import CSV
                                </Button>
                                <Button asChild>
                                    <Link href={route('items.create')}>New item</Link>
                                </Button>
                            </>
                        )
                    }
                >
                    <SearchInput value={search} onChange={setSearch} placeholder="Search name, SKU, barcode" label="Search items" />
                    <CategoryFilter value={filters.category} categories={categories} onChange={(value) => apply({ category: value })} />
                    <StatusFilter value={filters.status} onChange={(value) => apply({ status: value })} />
                </FilterBar>

                <TableCard
                    isEmpty={items.data.length === 0}
                    empty={
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
                    }
                >
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
                                                        <ChevronRight className={`size-4 transition-transform ${isExpanded ? 'rotate-90' : ''}`} />
                                                    </Button>
                                                )}
                                            </TableCell>
                                            <TableCell className="font-medium">{item.name}</TableCell>
                                            <TableCell>{item.category?.name ?? '—'}</TableCell>
                                            <TableCell>{item.unit}</TableCell>
                                            <TableCell>{variants.length}</TableCell>
                                            <TableCell>{priceLabel(item)}</TableCell>
                                            <TableCell>
                                                <ArchivedBadge archived={Boolean(item.archived_at)} />
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
                                                                        <NumericCell>{Number(variant.selling_price).toFixed(2)}</NumericCell>
                                                                        {canViewCost && (
                                                                            <NumericCell>
                                                                                {variant.cost != null ? Number(variant.cost).toFixed(2) : '—'}
                                                                            </NumericCell>
                                                                        )}
                                                                        {/* Displayed, not edited: the threshold is a
                                                                                property of the product and belongs on the
                                                                                item form with price and cost. Inventory
                                                                                shows it beside the shelf it applies to. */}
                                                                        <NumericCell>{variant.low_stock_threshold}</NumericCell>
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
                </TableCard>

                <Pagination links={items.links} />
            </PageShell>

            {canManage && <ImportItemsDialog open={importing} onOpenChange={setImporting} online={online} />}
        </AppLayout>
    );
}
