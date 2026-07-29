import { useConfirm } from '@/components/confirm-dialog';
import EmptyState from '@/components/empty-state';
import { OfflineNotice } from '@/components/offline-notice';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import ResetPasswordDialog from '@/components/users/reset-password-dialog';
import RoleDialog from '@/components/users/role-dialog';
import UserDialog from '@/components/users/user-dialog';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { outranks } from '@/lib/roles';
import type { BreadcrumbItem, ManagedUser, Paginated, UserRole } from '@/types';
import { Head, router } from '@inertiajs/react';
import { KeyRound, UserCog, UsersRound } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'User Management', href: '/users' }];

const ROLE_LABEL: Record<UserRole, string> = { owner: 'Owner', admin: 'Admin', cashier: 'Cashier' };

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
    const isFirstRender = useRef(true);

    // The actor may act on a user only if they strictly outrank them (mirrors the
    // server-side UserPolicy). Owners are never manageable — nothing outranks them.
    const canManage = (target: ManagedUser) => (role ? outranks(role, target.role) : false);

    const applyFilters = (next: Partial<Filters>) => {
        const merged = { search, status: filters.status, ...next };
        router.get(
            route('users'),
            {
                search: merged.search || undefined,
                status: merged.status && merged.status !== 'active' ? merged.status : undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true, only: ['users', 'filters'] },
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
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4">
                {!online && <OfflineNotice what="Staff accounts" />}
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search name or username" className="w-64" />
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

                    {assignableRoles.length > 0 && <Button onClick={() => setCreateOpen(true)}>New user</Button>}
                </div>

                {users.data.length === 0 ? (
                    <EmptyState
                        icon={UsersRound}
                        title="No users found"
                        description={filters.search ? 'Try adjusting your search or filters.' : 'Create an employee account to get started.'}
                        action={assignableRoles.length > 0 ? <Button onClick={() => setCreateOpen(true)}>New user</Button> : undefined}
                    />
                ) : (
                    <div className="rounded-xl border">
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
                                                {user.deleted_at ? <Badge variant="secondary">Archived</Badge> : <Badge>Active</Badge>}
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
                    </div>
                )}

                <Pagination links={users.links} />
            </div>

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
