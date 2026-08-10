import ItemForm from '@/components/inventory/item-form';
import { OfflineNotice } from '@/components/offline-notice';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, Category, Item } from '@/types';
import { Head } from '@inertiajs/react';

export default function EditItem({ item, categories, online }: { item: Item; categories: Pick<Category, 'id' | 'name'>[]; online: boolean }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Items', href: '/items' },
        { title: item.name, href: `/inventory/${item.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${item.name}`} />
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4">
                {!online && <OfflineNotice what="The product catalog" />}
                <ItemForm item={item} categories={categories} />
            </div>
        </AppLayout>
    );
}
