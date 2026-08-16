import EmptyState from '@/components/data/empty-state';
import { ArchivedBadge } from '@/components/data/status-badge';
import TableCard from '@/components/data/table-card';
import FilterBar from '@/components/filters/filter-bar';
import StatusFilter from '@/components/filters/status-filter';
import { useConfirm } from '@/components/forms/confirm-dialog';
import CategoryDialog from '@/components/inventory/category-dialog';
import PageShell from '@/components/page/page-shell';
import { OfflineNotice } from '@/components/sync/offline-notice';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useListFilters } from '@/hooks/use-list-filters';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, Category } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Tags } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Items', href: '/items' },
    { title: 'Categories', href: '/items/categories' },
];

export default function Categories({ categories, filters, online }: { categories: Category[]; filters: { status: string }; online: boolean }) {
    const { hasRoleAtLeast } = usePermissions();
    const confirm = useConfirm();
    const canManage = hasRoleAtLeast('admin');

    const [dialogOpen, setDialogOpen] = useState(false);
    const [editing, setEditing] = useState<Category | null>(null);

    const openCreate = () => {
        setEditing(null);
        setDialogOpen(true);
    };

    const openEdit = (category: Category) => {
        setEditing(category);
        setDialogOpen(true);
    };

    const { apply } = useListFilters({
        url: route('categories.index'),
        values: filters,
        defaults: { status: 'active' },
        only: ['categories', 'filters'],
    });

    const archiveCategory = async (category: Category) => {
        if (
            await confirm({
                title: `Archive "${category.name}"?`,
                description: 'Items keep their category in history; it is only hidden from pickers.',
                destructive: true,
                confirmLabel: 'Archive',
            })
        ) {
            router.patch(route('categories.archive', category.id), {}, { preserveScroll: true });
        }
    };

    const restoreCategory = async (category: Category) => {
        if (await confirm({ title: `Restore "${category.name}"?`, confirmLabel: 'Restore' })) {
            router.patch(route('categories.restore', category.id), {}, { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Categories" />
            <PageShell>
                {!online && <OfflineNotice what="The product catalog" />}
                <FilterBar actions={canManage && <Button onClick={openCreate}>New category</Button>}>
                    <StatusFilter value={filters.status} onChange={(value) => apply({ status: value })} />
                </FilterBar>

                <TableCard
                    isEmpty={categories.length === 0}
                    empty={
                        <EmptyState
                            icon={Tags}
                            title="No categories"
                            description="Categories group items in the catalog and POS."
                            action={canManage ? <Button onClick={openCreate}>New category</Button> : undefined}
                        />
                    }
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Items</TableHead>
                                <TableHead>Status</TableHead>
                                {canManage && <TableHead className="text-right">Actions</TableHead>}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {categories.map((category) => (
                                <TableRow key={category.id}>
                                    <TableCell className="font-medium">{category.name}</TableCell>
                                    <TableCell>{category.items_count ?? 0}</TableCell>
                                    <TableCell>
                                        <ArchivedBadge archived={Boolean(category.archived_at)} />
                                    </TableCell>
                                    {canManage && (
                                        <TableCell className="text-right">
                                            <div className="flex justify-end gap-1">
                                                <Button variant="ghost" size="sm" onClick={() => openEdit(category)}>
                                                    Edit
                                                </Button>
                                                {category.archived_at ? (
                                                    <Button variant="ghost" size="sm" onClick={() => restoreCategory(category)}>
                                                        Restore
                                                    </Button>
                                                ) : (
                                                    <Button variant="ghost" size="sm" onClick={() => archiveCategory(category)}>
                                                        Archive
                                                    </Button>
                                                )}
                                            </div>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </TableCard>

                <CategoryDialog open={dialogOpen} onOpenChange={setDialogOpen} category={editing} />
            </PageShell>
        </AppLayout>
    );
}
