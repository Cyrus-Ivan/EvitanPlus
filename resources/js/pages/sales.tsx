import { BranchScopeSelect } from '@/components/branch-scope-select';
import EmptyState from '@/components/empty-state';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCurrency } from '@/hooks/use-currency';
import AppLayout from '@/layouts/app-layout';
import type { BranchScope, BreadcrumbItem, Paginated, PaymentType, RosterBranch, TransactionListRow, User } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ReceiptText, WifiOff } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Sales', href: '/sales' }];

// The "no filter" sentinel for the payment/cashier dropdowns. It collides in
// spelling with the branch scope's `all` and in nothing else — that one means
// "every branch", and it lives on its own control.
const ALL = 'all';

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
        <div role="alert" className="flex items-start gap-2 rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm">
            <WifiOff className="mt-0.5 size-4 shrink-0" />
            <p>
                <span className="font-medium">These are this branch's sales only.</span> Other branches' sales live in the business database and this
                device could not reach it, so they could not be included. Reconnect to see sales across branches.
            </p>
        </div>
    );
}

// Human labels + badge tone per payment type.
const PAYMENT: Record<PaymentType, { label: string; variant: 'default' | 'secondary' | 'outline' }> = {
    cash: { label: 'Cash', variant: 'default' },
    cashless: { label: 'Cashless', variant: 'secondary' },
    others: { label: 'Others', variant: 'outline' },
};

function formatWhen(value: string): string {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
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

    const applyFilters = (next: Partial<Filters> & { scope?: string }) => {
        const merged = { ...filters, ...next };
        router.get(
            route('sales'),
            {
                search: merged.search || undefined,
                payment_type: merged.payment_type && merged.payment_type !== ALL ? merged.payment_type : undefined,
                user: merged.user && merged.user !== ALL ? merged.user : undefined,
                date_from: merged.date_from || undefined,
                date_to: merged.date_to || undefined,
                // Carried through EVERY change, not just a scope change: the
                // search box calls this on each keystroke, and dropping the
                // scope there would silently walk the reader back to this branch.
                scope: (next.scope ?? scope) === LOCAL ? undefined : (next.scope ?? scope),
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                // `branches` is deliberately out of this list: the roster costs an
                // online read, and typing in the search box must not pay for it.
                only: ['transactions', 'filters', 'scope', 'consolidatedOnline'],
            },
        );
    };

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
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        type="search"
                        placeholder="Search receipt no."
                        defaultValue={filters.search ?? ''}
                        onChange={(e) => applyFilters({ search: e.target.value || null })}
                        className="w-52"
                    />
                    <Select value={filters.payment_type ?? ALL} onValueChange={(value) => applyFilters({ payment_type: value })}>
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="All payments" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All payments</SelectItem>
                            {paymentTypes.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {PAYMENT[type]?.label ?? type}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select value={filters.user ?? ALL} onValueChange={(value) => applyFilters({ user: value })}>
                        <SelectTrigger className="w-56">
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
                    {canViewAll && (
                        <BranchScopeSelect
                            scope={scope}
                            branches={branches}
                            onChange={(next) => applyFilters({ scope: next })}
                            label="Branches shown"
                        />
                    )}
                </div>

                {consolidatedOnline === false && <OfflineSalesNotice />}

                {transactions.data.length === 0 ? (
                    <EmptyState icon={ReceiptText} title="No sales" description="Completed transactions will appear here once you ring up a sale." />
                ) : (
                    <div className="rounded-xl border">
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
                                            <Badge variant={PAYMENT[row.payment_type]?.variant ?? 'outline'}>
                                                {PAYMENT[row.payment_type]?.label ?? row.payment_type}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{row.items_count}</TableCell>
                                        <TableCell className="text-muted-foreground text-right tabular-nums">{discountLabel(row, money)}</TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">{money(row.total)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                <Pagination links={transactions.links} />
            </div>
        </AppLayout>
    );
}
