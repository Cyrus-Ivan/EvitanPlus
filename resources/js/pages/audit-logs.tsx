import EmptyState from '@/components/empty-state';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { AuditLogEntry, BreadcrumbItem, Paginated, User } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ChevronDown, ChevronRight, ScrollText } from 'lucide-react';
import { Fragment, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Audit Logs', href: '/audit-logs' }];

const ALL = 'all';

// Badge tone keyed on the action's top-level domain. Destructive actions
// (archive / password reset / defect write-off) read as warnings.
function actionTone(action: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (action.endsWith('.archived') || action === 'user.password_reset' || action === 'sale.defect.created') {
        return 'destructive';
    }
    if (action.startsWith('settings.')) {
        return 'secondary';
    }
    return 'default';
}

// Turn "inventory.item.updated" into "Inventory · Item · Updated".
function actionLabel(action: string): string {
    return action
        .split('.')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1).replace(/_/g, ' '))
        .join(' · ');
}

function formatWhen(value: string | null): string {
    if (!value) {
        return '—';
    }
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
}

interface Filters {
    user: string | null;
    action: string | null;
    date_from: string | null;
    date_to: string | null;
}

function ValueBlock({ title, values }: { title: string; values: Record<string, unknown> | null }) {
    if (!values || Object.keys(values).length === 0) {
        return null;
    }
    return (
        <div className="min-w-0 flex-1">
            <div className="text-muted-foreground mb-1 text-xs font-medium uppercase">{title}</div>
            <pre className="bg-muted overflow-x-auto rounded-md p-3 text-xs">{JSON.stringify(values, null, 2)}</pre>
        </div>
    );
}

export default function AuditLogs({
    entries,
    users,
    actions,
    filters,
}: {
    entries: Paginated<AuditLogEntry>;
    users: Pick<User, 'id' | 'name' | 'username'>[];
    actions: string[];
    filters: Filters;
}) {
    const [expanded, setExpanded] = useState<string | null>(null);

    const applyFilters = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        router.get(
            route('audit-logs'),
            {
                user: merged.user && merged.user !== ALL ? merged.user : undefined,
                action: merged.action && merged.action !== ALL ? merged.action : undefined,
                date_from: merged.date_from || undefined,
                date_to: merged.date_to || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true, only: ['entries', 'filters'] },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Audit Logs" />
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-2">
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
                    <Select value={filters.action ?? ALL} onValueChange={(value) => applyFilters({ action: value })}>
                        <SelectTrigger className="w-64">
                            <SelectValue placeholder="All actions" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All actions</SelectItem>
                            {actions.map((action) => (
                                <SelectItem key={action} value={action}>
                                    {actionLabel(action)}
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

                {entries.data.length === 0 ? (
                    <EmptyState
                        icon={ScrollText}
                        title="No audit entries"
                        description="Significant business actions will appear here as they happen."
                    />
                ) : (
                    <div className="rounded-xl border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-8" />
                                    <TableHead>When</TableHead>
                                    <TableHead>User</TableHead>
                                    <TableHead>Action</TableHead>
                                    <TableHead>Description</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {entries.data.map((entry) => {
                                    const hasDetail = Boolean(
                                        (entry.old_values && Object.keys(entry.old_values).length > 0) ||
                                            (entry.new_values && Object.keys(entry.new_values).length > 0),
                                    );
                                    const isOpen = expanded === entry.id;
                                    return (
                                        <Fragment key={entry.id}>
                                            <TableRow
                                                className={hasDetail ? 'cursor-pointer' : undefined}
                                                onClick={hasDetail ? () => setExpanded(isOpen ? null : entry.id) : undefined}
                                            >
                                                <TableCell className="text-muted-foreground">
                                                    {hasDetail ? (
                                                        isOpen ? (
                                                            <ChevronDown className="size-4" />
                                                        ) : (
                                                            <ChevronRight className="size-4" />
                                                        )
                                                    ) : null}
                                                </TableCell>
                                                <TableCell className="whitespace-nowrap tabular-nums">{formatWhen(entry.created_at)}</TableCell>
                                                <TableCell>{entry.user?.name ?? entry.user?.username ?? 'System'}</TableCell>
                                                <TableCell>
                                                    <Badge variant={actionTone(entry.action)}>{actionLabel(entry.action)}</Badge>
                                                </TableCell>
                                                <TableCell className="text-muted-foreground">{entry.description ?? '—'}</TableCell>
                                            </TableRow>
                                            {isOpen && hasDetail && (
                                                <TableRow>
                                                    <TableCell colSpan={5} className="bg-muted/30">
                                                        <div className="flex flex-col gap-4 p-2 sm:flex-row">
                                                            <ValueBlock title="Before" values={entry.old_values} />
                                                            <ValueBlock title="After" values={entry.new_values} />
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

                <Pagination links={entries.links} />
            </div>
        </AppLayout>
    );
}
