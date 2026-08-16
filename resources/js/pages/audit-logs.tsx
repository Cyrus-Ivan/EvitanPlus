import EmptyState from '@/components/data/empty-state';
import Pagination from '@/components/data/pagination';
import TableCard from '@/components/data/table-card';
import DateRangeFilter from '@/components/filters/date-range-filter';
import FilterBar from '@/components/filters/filter-bar';
import PageShell from '@/components/page/page-shell';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useListFilters } from '@/hooks/use-list-filters';
import AppLayout from '@/layouts/app-layout';
import { ALL } from '@/lib/filters';
import { formatWhen } from '@/lib/format';
import type { AuditLogEntry, BreadcrumbItem, Paginated, User } from '@/types';
import { Head } from '@inertiajs/react';
import { ChevronDown, ChevronRight, ScrollText } from 'lucide-react';
import { Fragment, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Audit Logs', href: '/audit-logs' }];

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

    const { apply } = useListFilters<Filters>({
        url: route('audit-logs'),
        values: filters,
        defaults: { user: ALL, action: ALL },
        only: ['entries', 'filters'],
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Audit Logs" />
            <PageShell>
                <FilterBar>
                    <Select value={filters.user ?? ALL} onValueChange={(value) => apply({ user: value })}>
                        <SelectTrigger className="w-56" aria-label="User">
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
                    <Select value={filters.action ?? ALL} onValueChange={(value) => apply({ action: value })}>
                        <SelectTrigger className="w-64" aria-label="Action">
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
                    <DateRangeFilter from={filters.date_from} to={filters.date_to} onChange={apply} label="Entry date" />
                </FilterBar>

                <TableCard
                    isEmpty={entries.data.length === 0}
                    empty={
                        <EmptyState
                            icon={ScrollText}
                            title="No audit entries"
                            description="Significant business actions will appear here as they happen."
                        />
                    }
                >
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
                                                {hasDetail ? isOpen ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" /> : null}
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
                </TableCard>

                <Pagination links={entries.links} />
            </PageShell>
        </AppLayout>
    );
}
