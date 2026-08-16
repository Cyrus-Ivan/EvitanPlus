import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';

import HeadingSmall from '@/components/page/heading-small';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Profile settings',
        href: '/settings/profile',
    },
];

/**
 * Read-only identity view. Accounts are provisioned and managed through User
 * Management (Phase 13) — names are immutable after creation and users are
 * archived, never self-deleted — so the profile offers no edit or delete.
 * Password changes live in Settings → Password.
 */
export default function Profile() {
    const { auth } = usePage<SharedData>().props;
    // The profile page renders behind the `auth` layout, so the user is present.
    const user = auth.user!;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Profile settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Profile information" description="Your account identity. Contact an administrator to change it." />

                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input id="name" className="mt-1 block w-full" value={user.name ?? ''} readOnly disabled />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="username">Username</Label>
                        <Input id="username" className="mt-1 block w-full" value={user.username} readOnly disabled />
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
