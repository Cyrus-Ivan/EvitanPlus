import ItemForm from '@/components/inventory/item-form';
import { OfflineNotice } from '@/components/offline-notice';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, Category } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'New item', href: '/inventory/create' },
];

export default function CreateItem({ categories, online }: { categories: Pick<Category, 'id' | 'name'>[]; online: boolean }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New item" />
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4">
                {!online && <OfflineNotice what="The product catalog" />}
                <ItemForm categories={categories} />
            </div>
        </AppLayout>
    );
}
