import EmptyState from '@/components/data/empty-state';
import Pagination from '@/components/data/pagination';
import { ArchivedBadge } from '@/components/data/status-badge';
import TableCard from '@/components/data/table-card';
import FilterBar from '@/components/filters/filter-bar';
import SearchInput from '@/components/filters/search-input';
import StatusFilter from '@/components/filters/status-filter';
import { useConfirm } from '@/components/forms/confirm-dialog';
import PageShell from '@/components/page/page-shell';
import { OfflineNotice } from '@/components/sync/offline-notice';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import ResetPasswordDialog from '@/components/users/reset-password-dialog';
import RoleDialog from '@/components/users/role-dialog';
import UserDialog from '@/components/users/user-dialog';
import { useDebouncedFilter, useListFilters } from '@/hooks/use-list-filters';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { ROLE_LABEL, outranks } from '@/lib/roles';
import type { BreadcrumbItem, ManagedUser, Paginated, UserRole } from '@/types';
import { Head, router } from '@inertiajs/react';
import { KeyRound, UserCog, UsersRound } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'User Management', href: '/users' }];

interface Filters {
    search: string | null;
    status: string;
}

export default function UsersIndex({
    users,
    assignableRoles,
    filters,
    online,
}: {
    users: Paginated<ManagedUser>;
    assignableRoles: UserRole[];
    filters: Filters;
    online: boolean;
}) {
    const { role } = usePermissions();
    const confirm = useConfirm();

    const [search, setSearch] = useState(filters.search ?? '');
    const [createOpen, setCreateOpen] = useState(false);
    const [roleTarget, setRoleTarget] = useState<ManagedUser | null>(null);
    const [resetTarget, setResetTarget] = useState<ManagedUser | null>(null);

    // The actor may act on a user only if they strictly outrank them (mirrors the
    // server-side UserPolicy). Owners are never manageable — nothing outranks them.
    const canManage = (target: ManagedUser) => (role ? outranks(role, target.role) : false);

    const { apply } = useListFilters<Filters>({
        url: route('users'),
        values: { ...filters, search },
        defaults: { status: 'active' },
        only: ['users', 'filters'],
    });

    useDebouncedFilter(search, (value) => apply({ search: value }));

    const archiveUser = async (user: ManagedUser) => {
        if (
            await confirm({
                title: `Archive "${user.name ?? user.username}"?`,
                description:
                    'They will be hidden from the active list but kept in Sales, Login History, and audit records. You can restore them later.',
                destructive: true,
                confirmLabel: 'Archive',
            })
        ) {
            router.patch(route('users.archive', user.id), {}, { preserveScroll: true });
        }
    };

    const restoreUser = async (user: ManagedUser) => {
        if (
            await confirm({
                title: `Restore "${user.name ?? user.username}"?`,
                description: 'They will reappear in the active list.',
                confirmLabel: 'Restore',
            })
        ) {
            router.patch(route('users.restore', user.id), {}, { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="User Management" />
            <PageShell>
                {!online && <OfflineNotice what="Staff accounts" />}
                <FilterBar actions={assignableRoles.length > 0 && <Button onClick={() => setCreateOpen(true)}>New user</Button>}>
                    <SearchInput value={search} onChange={setSearch} placeholder="Search name or username" label="Search users" />
                    <StatusFilter value={filters.status} onChange={(value) => apply({ status: value })} />
                </FilterBar>

                <TableCard
                    isEmpty={users.data.length === 0}
                    empty={
                        <EmptyState
                            icon={UsersRound}
                            title="No users found"
                            description={filters.search ? 'Try adjusting your search or filters.' : 'Create an employee account to get started.'}
                            action={assignableRoles.length > 0 ? <Button onClick={() => setCreateOpen(true)}>New user</Button> : undefined}
                        />
                    }
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Username</TableHead>
                                <TableHead>Role</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {users.data.map((user) => {
                                const manageable = canManage(user);
                                return (
                                    <TableRow key={user.id}>
                                        <TableCell className="font-medium">{user.name ?? '—'}</TableCell>
                                        <TableCell className="tabular-nums">{user.username}</TableCell>
                                        <TableCell>{ROLE_LABEL[user.role]}</TableCell>
                                        <TableCell>
                                            <ArchivedBadge archived={Boolean(user.deleted_at)} />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <div className="flex justify-end gap-1">
                                                {manageable && !user.deleted_at && (
                                                    <>
                                                        <Button variant="ghost" size="sm" onClick={() => setRoleTarget(user)}>
                                                            <UserCog className="size-4" /> Role
                                                        </Button>
                                                        <Button variant="ghost" size="sm" onClick={() => setResetTarget(user)}>
                                                            <KeyRound className="size-4" /> Reset
                                                        </Button>
                                                        <Button variant="ghost" size="sm" onClick={() => archiveUser(user)}>
                                                            Archive
                                                        </Button>
                                                    </>
                                                )}
                                                {manageable && user.deleted_at && (
                                                    <Button variant="ghost" size="sm" onClick={() => restoreUser(user)}>
                                                        Restore
                                                    </Button>
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </TableCard>

                <Pagination links={users.links} />
            </PageShell>

            <UserDialog open={createOpen} onOpenChange={setCreateOpen} assignableRoles={assignableRoles} />
            <RoleDialog
                open={roleTarget !== null}
                onOpenChange={(open) => !open && setRoleTarget(null)}
                user={roleTarget}
                assignableRoles={assignableRoles}
            />
            <ResetPasswordDialog open={resetTarget !== null} onOpenChange={(open) => !open && setResetTarget(null)} user={resetTarget} />
        </AppLayout>
    );
}
