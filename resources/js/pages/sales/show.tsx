import PageShell from '@/components/page/page-shell';
import ReturnDialog from '@/components/sales/return-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCurrency } from '@/hooks/use-currency';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { formatWhen } from '@/lib/format';
import { paymentLabel, paymentVariant } from '@/lib/payment';
import type { BreadcrumbItem, SaleReturnEvent, SaleReturnType, TransactionDetail } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Printer, RotateCcw, TriangleAlert } from 'lucide-react';
import { useState } from 'react';

const RETURN_TYPE_LABEL: Record<SaleReturnType, string> = {
    return: 'Return',
    defect: 'Defect',
};

const RETURN_TYPE_BADGE: Record<SaleReturnType, 'secondary' | 'destructive' | 'outline'> = {
    return: 'secondary',
    defect: 'outline',
};

type MoneyFormatter = (value: number | string) => string;

// Format a nullable money string, rendering null (a redacted cost) as a dash.
function pesoOrDash(value: string | null, money: MoneyFormatter): string {
    return value === null ? '—' : money(value);
}

// Sum of line totals before the transaction-level discount.
function subtotalOf(transaction: TransactionDetail): number {
    return transaction.items.reduce((sum, line) => sum + Number(line.total), 0);
}

function discountLabel(transaction: TransactionDetail, money: MoneyFormatter): string {
    const amount = Number(transaction.discount);
    if (!transaction.discount_type || amount <= 0) {
        return '—';
    }
    return transaction.discount_type === 'percentage' ? `${amount}%` : money(transaction.discount);
}

// A read-only label over its value. NOT `components/field.tsx`, which wraps a
// form control — this one has nothing to type into, hence the different name.
function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex flex-col gap-0.5">
            <span className="text-muted-foreground text-xs uppercase">{label}</span>
            <span className="text-sm tabular-nums">{value}</span>
        </div>
    );
}

export default function SalesShow({
    transaction,
    canViewCost,
    readOnly,
    branch,
}: {
    transaction: TransactionDetail;
    canViewCost: boolean;
    // This sale belongs to another branch and was read live from the business
    // database (M12). Nothing on this page may act on it: the receipt is not
    // this branch's to reprint, and the stock a return would restore is not on
    // this branch's shelf.
    readOnly: boolean;
    // The branch CODE this sale was rung up at; null in the local scope, where
    // the answer is "here".
    branch: string | null;
}) {
    const { hasRoleAtLeast } = usePermissions();
    const { format: money } = useCurrency();
    const [dialogType, setDialogType] = useState<SaleReturnType | null>(null);
    const [printing, setPrinting] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales', href: '/sales' },
        { title: transaction.transaction_number, href: `/sales/${transaction.id}` },
    ];

    // Any line with quantity still available to return/defect.
    const hasReturnable = transaction.items.some((line) => line.quantity - line.returned_quantity > 0);
    const canManageInventory = hasRoleAtLeast('admin');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Receipt ${transaction.transaction_number}`} />
            <PageShell className="mx-auto w-full max-w-3xl">
                <Link
                    href={readOnly ? route('sales', { scope: 'all' }) : route('sales')}
                    className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1.5 text-sm"
                >
                    <ArrowLeft className="size-4" />
                    Back to Sales
                </Link>

                <Card>
                    <CardHeader className="flex flex-row items-start justify-between gap-2">
                        <div>
                            <CardTitle className="tabular-nums">Receipt {transaction.transaction_number}</CardTitle>
                            <p className="text-muted-foreground mt-1 text-sm">{formatWhen(transaction.created_at)}</p>
                            {readOnly && (
                                <p className="text-muted-foreground mt-1 text-sm">
                                    Read-only — this sale was rung up at another branch, so it cannot be reprinted or returned here.
                                </p>
                            )}
                        </div>
                        <div className="flex items-center gap-2">
                            {readOnly && <Badge variant="outline">{branch ?? 'Another branch'}</Badge>}
                            {/* Toned by payment type, matching the sales list —
                                this had been hardcoded `secondary`, so one sale
                                wore two different badges depending on which screen
                                you opened it from. */}
                            <Badge variant={paymentVariant(transaction.payment_type)}>{paymentLabel(transaction.payment_type)}</Badge>
                        </div>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-6">
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                            <Detail label="Cashier" value={transaction.user?.name ?? transaction.user?.username ?? '—'} />
                            <Detail label="Payment" value={paymentLabel(transaction.payment_type)} />
                            {transaction.comment && <Detail label="Comment" value={transaction.comment} />}
                        </div>

                        <div className="rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Product</TableHead>
                                        <TableHead className="text-right">Qty</TableHead>
                                        <TableHead className="text-right">Unit Price</TableHead>
                                        {canViewCost && <TableHead className="text-right">Unit Cost</TableHead>}
                                        <TableHead className="text-right">Line Total</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {transaction.items.map((line) => (
                                        <TableRow key={line.id}>
                                            <TableCell className="font-medium">{line.label}</TableCell>
                                            <TableCell className="text-right tabular-nums">{line.quantity}</TableCell>
                                            <TableCell className="text-right tabular-nums">{money(line.selling_price)}</TableCell>
                                            {canViewCost && (
                                                <TableCell className="text-muted-foreground text-right tabular-nums">
                                                    {pesoOrDash(line.cost ?? null, money)}
                                                </TableCell>
                                            )}
                                            <TableCell className="text-right tabular-nums">{money(line.total)}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                                <TableFooter>
                                    <TableRow>
                                        <TableCell colSpan={canViewCost ? 4 : 3} className="text-muted-foreground text-right">
                                            Subtotal
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{money(subtotalOf(transaction).toFixed(2))}</TableCell>
                                    </TableRow>
                                    <TableRow>
                                        <TableCell colSpan={canViewCost ? 4 : 3} className="text-muted-foreground text-right">
                                            Discount
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{discountLabel(transaction, money)}</TableCell>
                                    </TableRow>
                                    <TableRow>
                                        <TableCell colSpan={canViewCost ? 4 : 3} className="text-right font-semibold">
                                            Total
                                        </TableCell>
                                        <TableCell className="text-right font-semibold tabular-nums">{money(transaction.total)}</TableCell>
                                    </TableRow>
                                    {transaction.payment_type === 'cash' && (
                                        <>
                                            <TableRow>
                                                <TableCell colSpan={canViewCost ? 4 : 3} className="text-muted-foreground text-right">
                                                    Cash Received
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {pesoOrDash(transaction.cash_received, money)}
                                                </TableCell>
                                            </TableRow>
                                            <TableRow>
                                                <TableCell colSpan={canViewCost ? 4 : 3} className="text-muted-foreground text-right">
                                                    Change
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">{pesoOrDash(transaction.change, money)}</TableCell>
                                            </TableRow>
                                        </>
                                    )}
                                </TableFooter>
                            </Table>
                        </div>

                        {/* Hidden, not disabled, under a wider scope: a greyed-out
                            Print button reads as "try again later", and there is no
                            later — the sale is not this branch's to act on. */}
                        {!readOnly && (
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={printing}
                                    onClick={() =>
                                        router.post(
                                            route('sales.print', transaction.id),
                                            {},
                                            {
                                                preserveScroll: true,
                                                onStart: () => setPrinting(true),
                                                onFinish: () => setPrinting(false),
                                            },
                                        )
                                    }
                                >
                                    <Printer className="size-4" />
                                    {printing ? 'Printing…' : 'Print receipt'}
                                </Button>
                                <Button variant="outline" size="sm" disabled={!hasReturnable} onClick={() => setDialogType('return')}>
                                    <RotateCcw className="size-4" />
                                    Return
                                </Button>
                                {canManageInventory && (
                                    <Button variant="outline" size="sm" disabled={!hasReturnable} onClick={() => setDialogType('defect')}>
                                        <TriangleAlert className="size-4" />
                                        Defect
                                    </Button>
                                )}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {transaction.returns.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Returns &amp; adjustments</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            {transaction.returns.map((event) => (
                                <ReturnEventCard key={event.id} event={event} canViewCost={canViewCost} />
                            ))}
                        </CardContent>
                    </Card>
                )}
            </PageShell>

            {dialogType && (
                <ReturnDialog
                    open={dialogType !== null}
                    onOpenChange={(open) => !open && setDialogType(null)}
                    transaction={transaction}
                    type={dialogType}
                />
            )}
        </AppLayout>
    );
}

function ReturnEventCard({ event, canViewCost }: { event: SaleReturnEvent; canViewCost: boolean }) {
    const { format: money } = useCurrency();

    return (
        <div className="rounded-lg border">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b px-3 py-2">
                <div className="flex items-center gap-2">
                    <Badge variant={RETURN_TYPE_BADGE[event.type]}>{RETURN_TYPE_LABEL[event.type]}</Badge>
                    <span className="text-sm font-medium tabular-nums">{event.return_number}</span>
                </div>
                <span className="text-muted-foreground text-xs">{formatWhen(event.created_at)}</span>
            </div>
            <div className="px-3 py-2">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Product</TableHead>
                            <TableHead className="text-right">Qty</TableHead>
                            {canViewCost && <TableHead className="text-right">Unit Cost</TableHead>}
                            <TableHead className="text-right">Refund</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {event.items.map((line) => (
                            <TableRow key={line.id}>
                                <TableCell className="font-medium">{line.label}</TableCell>
                                <TableCell className="text-right tabular-nums">{line.quantity}</TableCell>
                                {canViewCost && (
                                    <TableCell className="text-muted-foreground text-right tabular-nums">
                                        {pesoOrDash(line.cost ?? null, money)}
                                    </TableCell>
                                )}
                                <TableCell className="text-right tabular-nums">{money(line.refund_amount)}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                    <TableFooter>
                        <TableRow>
                            <TableCell colSpan={canViewCost ? 3 : 2} className="text-right font-semibold">
                                {event.type === 'defect' ? 'Written off' : 'Total refund'}
                            </TableCell>
                            <TableCell className="text-right font-semibold tabular-nums">{money(event.total_refund)}</TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
                <div className="text-muted-foreground mt-1 flex flex-wrap justify-between gap-2 text-xs">
                    <span>By {event.performed_by?.name ?? event.performed_by?.username ?? '—'}</span>
                    {event.refund_payment_type && <span>via {paymentLabel(event.refund_payment_type)}</span>}
                </div>
                {event.reason && <p className="text-muted-foreground mt-1 text-xs">Reason: {event.reason}</p>}
            </div>
        </div>
    );
}
