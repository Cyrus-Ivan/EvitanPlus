import { useConfirm } from '@/components/confirm-dialog';
import EmptyState from '@/components/empty-state';
import CategoryDialog from '@/components/inventory/category-dialog';
import { OfflineNotice } from '@/components/offline-notice';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
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

    const changeStatus = (status: string) => {
        router.get(
            route('categories.index'),
            { status: status !== 'active' ? status : undefined },
            { preserveState: true, preserveScroll: true, replace: true, only: ['categories', 'filters'] },
        );
    };

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
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4">
                {!online && <OfflineNotice what="The product catalog" />}
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Select value={filters.status} onValueChange={changeStatus}>
                        <SelectTrigger className="w-36">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="archived">Archived</SelectItem>
                            <SelectItem value="all">All</SelectItem>
                        </SelectContent>
                    </Select>

                    {canManage && <Button onClick={openCreate}>New category</Button>}
                </div>

                {categories.length === 0 ? (
                    <EmptyState
                        icon={Tags}
                        title="No categories"
                        description="Categories group items in the catalog and POS."
                        action={canManage ? <Button onClick={openCreate}>New category</Button> : undefined}
                    />
                ) : (
                    <div className="rounded-xl border">
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
                                            {category.archived_at ? <Badge variant="secondary">Archived</Badge> : <Badge>Active</Badge>}
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
                    </div>
                )}

                <CategoryDialog open={dialogOpen} onOpenChange={setDialogOpen} category={editing} />
            </div>
        </AppLayout>
    );
}
