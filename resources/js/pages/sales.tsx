import EmptyState from '@/components/data/empty-state';
import Pagination from '@/components/data/pagination';
import TableCard, { NumericCell } from '@/components/data/table-card';
import { BranchScopeSelect } from '@/components/filters/branch-scope-select';
import DateRangeFilter from '@/components/filters/date-range-filter';
import FilterBar from '@/components/filters/filter-bar';
import SearchInput from '@/components/filters/search-input';
import PageShell from '@/components/page/page-shell';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCurrency } from '@/hooks/use-currency';
import { useListFilters } from '@/hooks/use-list-filters';
import AppLayout from '@/layouts/app-layout';
import { ALL } from '@/lib/filters';
import { formatWhen } from '@/lib/format';
import { paymentLabel, paymentVariant } from '@/lib/payment';
import type { BranchScope, BreadcrumbItem, Paginated, PaymentType, RosterBranch, TransactionListRow, User } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ReceiptText, WifiOff } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Sales', href: '/sales' }];

const LOCAL = 'local';

/**
 * Shown when cross-branch sales were asked for and the business database could
 * not be reached, so what is on screen is this branch's own takings.
 *
 * The risk this guards against is a correct-looking table that covers fewer
 * branches than its heading claims — and here the reader is looking at money, so
 * the first line names what is on screen before anything else.
 */
function OfflineSalesNotice() {
    return (
        <Alert variant="warning">
            <WifiOff />
            <p>
                <span className="font-medium">These are this branch's sales only.</span> Other branches' sales live in the business database and this
                device could not reach it, so they could not be included. Reconnect to see sales across branches.
            </p>
        </Alert>
    );
}

function discountLabel(row: TransactionListRow, money: (value: number | string) => string): string {
    const amount = Number(row.discount);
    if (!row.discount_type || amount <= 0) {
        return '—';
    }
    return row.discount_type === 'percentage' ? `${amount}%` : money(row.discount);
}

interface Filters {
    search: string | null;
    payment_type: string | null;
    user: string | null;
    date_from: string | null;
    date_to: string | null;
}

export default function Sales({
    transactions,
    users,
    paymentTypes,
    filters,
    scope,
    canViewAll,
    branches,
    consolidatedOnline,
}: {
    transactions: Paginated<TransactionListRow>;
    users: Pick<User, 'id' | 'name' | 'username'>[];
    paymentTypes: PaymentType[];
    filters: Filters;
    scope: BranchScope;
    canViewAll: boolean;
    branches: RosterBranch[];
    consolidatedOnline: boolean | null;
}) {
    const { format: money } = useCurrency();

    const { apply } = useListFilters<Filters & { scope: string }>({
        url: route('sales'),
        values: { ...filters, scope },
        defaults: { payment_type: ALL, user: ALL, scope: LOCAL },
        // `branches` is deliberately out of this list: the roster costs an online
        // read, and typing in the search box must not pay for it.
        only: ['transactions', 'filters', 'scope', 'consolidatedOnline'],
    });

    // A wider scope names the branch on every row; in the local scope every row
    // is this device's by construction, and the receipt number already carries
    // the branch code, so the column would say the same thing twice.
    const showBranch = scope !== LOCAL;

    // Where a row's detail lives. Under a wider scope the sale is not on this
    // device, so the scope has to travel with the id or the page 404s.
    const detailHref = (id: string) => (showBranch ? route('sales.show', { transaction: id, scope }) : route('sales.show', id));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sales" />
            <PageShell>
                <FilterBar>
                    {/* Not debounced, unlike the other search boxes: a receipt
                        number is pasted or scanned rather than typed a letter at
                        a time, and the list is short enough that the round trip
                        is the feedback. */}
                    <SearchInput
                        value={filters.search ?? ''}
                        onChange={(value) => apply({ search: value || null })}
                        placeholder="Search receipt no."
                        label="Search sales"
                        className="w-52"
                    />
                    <Select value={filters.payment_type ?? ALL} onValueChange={(value) => apply({ payment_type: value })}>
                        <SelectTrigger className="w-40" aria-label="Payment type">
                            <SelectValue placeholder="All payments" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All payments</SelectItem>
                            {paymentTypes.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {paymentLabel(type)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select value={filters.user ?? ALL} onValueChange={(value) => apply({ user: value })}>
                        <SelectTrigger className="w-56" aria-label="Cashier">
                            <SelectValue placeholder="All cashiers" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All cashiers</SelectItem>
                            {users.map((user) => (
                                <SelectItem key={user.id} value={user.id}>
                                    {user.name ?? user.username}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <DateRangeFilter from={filters.date_from} to={filters.date_to} onChange={apply} label="Sale date" />
                    {canViewAll && (
                        <BranchScopeSelect scope={scope} branches={branches} onChange={(next) => apply({ scope: next })} label="Branches shown" />
                    )}
                </FilterBar>

                {consolidatedOnline === false && <OfflineSalesNotice />}

                <TableCard
                    isEmpty={transactions.data.length === 0}
                    empty={
                        <EmptyState
                            icon={ReceiptText}
                            title="No sales"
                            description="Completed transactions will appear here once you ring up a sale."
                        />
                    }
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Receipt No.</TableHead>
                                {showBranch && <TableHead>Branch</TableHead>}
                                <TableHead>When</TableHead>
                                <TableHead>Cashier</TableHead>
                                <TableHead>Payment</TableHead>
                                <TableHead className="text-right">Items</TableHead>
                                <TableHead className="text-right">Discount</TableHead>
                                <TableHead className="text-right">Total</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {transactions.data.map((row) => (
                                // The row onClick is a redundant mouse affordance; the receipt-number
                                // Link below is the keyboard-accessible path (a focusable row would
                                // double every tab stop for keyboard users).
                                <TableRow key={row.id} className="cursor-pointer" onClick={() => router.visit(detailHref(row.id))}>
                                    <TableCell className="font-medium tabular-nums">
                                        <Link href={detailHref(row.id)} onClick={(e) => e.stopPropagation()} className="hover:underline">
                                            {row.transaction_number}
                                        </Link>
                                    </TableCell>
                                    {showBranch && <TableCell className="text-muted-foreground">{row.branch ?? '—'}</TableCell>}
                                    <TableCell className="tabular-nums">{formatWhen(row.created_at)}</TableCell>
                                    <TableCell>{row.user?.name ?? row.user?.username ?? '—'}</TableCell>
                                    <TableCell>
                                        <Badge variant={paymentVariant(row.payment_type)}>{paymentLabel(row.payment_type)}</Badge>
                                    </TableCell>
                                    <NumericCell>{row.items_count}</NumericCell>
                                    <NumericCell className="text-muted-foreground">{discountLabel(row, money)}</NumericCell>
                                    <NumericCell className="font-medium">{money(row.total)}</NumericCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </TableCard>

                <Pagination links={transactions.links} />
            </PageShell>
        </AppLayout>
    );
}
