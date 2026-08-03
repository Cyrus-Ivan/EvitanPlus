import EmptyState from '@/components/empty-state';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { BranchScope, BreadcrumbItem, LoginEventType, LoginHistoryEntry, Paginated, RosterBranch, User } from '@/types';
import { Head, router } from '@inertiajs/react';
import { History, WifiOff } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Login History', href: '/login-history' }];

const ALL = 'all';

// This branch's own sessions, from the local mirror — the default, and what a
// Cashier always gets. Any other value is read live from the business database.
const LOCAL = 'local';

// The props a filter or scope change reloads. Listed once because every control
// funnels through applyFilters(): a prop left out of this list silently freezes
// while the rest of the screen updates around it.
const RELOADED = ['entries', 'filters', 'scope', 'branches', 'consolidatedOnline'];

// Human labels + badge tone per event. Clean sign-in/out reads neutral; the
// abnormal terminations (crash / power loss / forced) read as warnings.
const EVENT: Record<LoginEventType, { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }> = {
    login: { label: 'Login', variant: 'default' },
    logout: { label: 'Logout', variant: 'secondary' },
    // Any unclean exit (window closed / shutdown / crash / force-kill) is detected
    // on the next launch and recorded uniformly as application_crash.
    application_crash: { label: 'Unexpected exit', variant: 'destructive' },
    forced_logout: { label: 'Forced logout', variant: 'destructive' },
};

function formatWhen(value: string | null): string {
    if (!value) {
        return '—';
    }
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
}

/**
 * Shown when cross-branch sessions were asked for and the business database
 * could not be reached, so what is on screen is this branch's own history.
 *
 * The risk this guards against is a correct-looking table that covers fewer
 * branches than its heading claims, so the first line names what is on screen.
 */
function OfflineHistoryNotice() {
    return (
        <div role="alert" className="flex items-start gap-2 rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm">
            <WifiOff className="mt-0.5 size-4 shrink-0" />
            <p>
                <span className="font-medium">These are this branch's sessions only.</span> Other branches' login history lives in the business
                database and this device is offline, so it could not be included. Reconnect to see login activity across branches.
            </p>
        </div>
    );
}

interface Filters {
    user: string | null;
    event: string | null;
    date_from: string | null;
    date_to: string | null;
}

export default function LoginHistory({
    entries,
    canViewAll,
    users,
    eventTypes,
    filters,
    scope,
    branches,
    consolidatedOnline,
}: {
    entries: Paginated<LoginHistoryEntry>;
    canViewAll: boolean;
    users: Pick<User, 'id' | 'name' | 'username'>[];
    eventTypes: LoginEventType[];
    filters: Filters;
    // M11.5. What was actually read — a failed online read comes back as
    // 'local' with consolidatedOnline false, never as the scope asked for.
    scope: BranchScope;
    branches: RosterBranch[];
    // Null when nothing cross-branch was asked for; false only on a fallback.
    consolidatedOnline: boolean | null;
}) {
    const applyFilters = (next: Partial<Filters> & { scope?: string }) => {
        const merged = { ...filters, scope, ...next };
        router.get(
            route('login-history'),
            {
                user: merged.user && merged.user !== ALL ? merged.user : undefined,
                event: merged.event && merged.event !== ALL ? merged.event : undefined,
                date_from: merged.date_from || undefined,
                date_to: merged.date_to || undefined,
                // Carried through every filter change, or narrowing by date
                // would silently drop the screen back to this branch.
                scope: merged.scope !== LOCAL ? merged.scope : undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true, only: RELOADED },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Login History" />
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-2">
                    {/* Which branches (M11.5). Admin+, and online-only — a
                        failed read snaps this back to "This branch" and the
                        notice below says why. */}
                    {canViewAll && (
                        <Select value={scope} onValueChange={(value) => applyFilters({ scope: value })}>
                            <SelectTrigger className="w-56" aria-label="Branches shown">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={LOCAL}>This branch</SelectItem>
                                <SelectItem value={ALL}>All branches</SelectItem>
                                {branches.map((branch) => (
                                    <SelectItem key={branch.id} value={branch.id}>
                                        {branch.code} — {branch.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                    {canViewAll && (
                        <Select value={filters.user ?? ALL} onValueChange={(value) => applyFilters({ user: value })}>
                            <SelectTrigger className="w-56">
                                <SelectValue placeholder="All users" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>All users</SelectItem>
                                {users.map((user) => (
                                    <SelectItem key={user.id} value={user.id}>
                                        {user.name ?? user.username}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                    <Select value={filters.event ?? ALL} onValueChange={(value) => applyFilters({ event: value })}>
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="All events" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All events</SelectItem>
                            {eventTypes.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {EVENT[type]?.label ?? type}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <div className="flex items-center gap-2">
                        <span className="text-muted-foreground text-sm">From</span>
                        <Input
                            type="date"
                            value={filters.date_from ?? ''}
                            max={filters.date_to ?? undefined}
                            onChange={(e) => applyFilters({ date_from: e.target.value || null })}
                            className="w-40"
                        />
                        <span className="text-muted-foreground text-sm">To</span>
                        <Input
                            type="date"
                            value={filters.date_to ?? ''}
                            min={filters.date_from ?? undefined}
                            onChange={(e) => applyFilters({ date_to: e.target.value || null })}
                            className="w-40"
                        />
                    </div>
                </div>

                {consolidatedOnline === false && <OfflineHistoryNotice />}

                {entries.data.length === 0 ? (
                    <EmptyState
                        icon={History}
                        title="No login events"
                        description="Authentication events will appear here as users sign in and out."
                    />
                ) : (
                    <div className="rounded-xl border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>When</TableHead>
                                    {/* The branch CODE — the identifier that
                                        prefixes every receipt this till prints,
                                        and the one a branch is called by. */}
                                    <TableHead>Branch</TableHead>
                                    {canViewAll && <TableHead>User</TableHead>}
                                    <TableHead>Event</TableHead>
                                    <TableHead>Notes</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {entries.data.map((entry) => (
                                    <TableRow key={entry.id}>
                                        <TableCell className="tabular-nums">{formatWhen(entry.occurred_at ?? entry.created_at)}</TableCell>
                                        <TableCell className="font-medium">{entry.branch ?? '—'}</TableCell>
                                        {canViewAll && <TableCell>{entry.user?.name ?? entry.user?.username ?? '—'}</TableCell>}
                                        <TableCell>
                                            <Badge variant={EVENT[entry.event_type]?.variant ?? 'outline'}>
                                                {EVENT[entry.event_type]?.label ?? entry.event_type}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">{entry.notes ?? '—'}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                <Pagination links={entries.links} />
            </div>
        </AppLayout>
    );
}
