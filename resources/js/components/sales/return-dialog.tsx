import Field from '@/components/forms/field';
import { FormDialog } from '@/components/forms/form-dialog';
import InputError from '@/components/forms/input-error';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { closeOnSuccess, useDialogForm } from '@/hooks/use-dialog-form';
import { PAYMENT, paymentLabel } from '@/lib/payment';
import type { PaymentType, SaleReturnType, TransactionDetail } from '@/types';
import { FormEventHandler, useEffect, useState } from 'react';

// The refund goes back by one of the ways money comes in, so the list is the
// payment types themselves rather than a fourth hand-written copy of them.
const REFUND_METHODS = Object.keys(PAYMENT) as PaymentType[];

const COPY: Record<SaleReturnType, { title: string; description: string; action: string }> = {
    return: {
        title: 'Process return',
        description: 'Select the quantities to return. Stock is restored and the customer is refunded at the original price.',
        action: 'Process return',
    },
    defect: {
        title: 'Record defect',
        description: 'Marks quantities as defective write-offs. Stock is NOT restored and no money is returned to the customer.',
        action: 'Record defect',
    },
};

/**
 * The after-sales modal (Phase 10). Drives return / defect off one form. Both
 * present a per-line quantity capped at what remains (sold − already returned).
 * A defect returns no money, so it hides the refund-method select.
 */
export default function ReturnDialog({
    open,
    onOpenChange,
    transaction,
    type,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    transaction: TransactionDetail;
    type: SaleReturnType;
}) {
    const copy = COPY[type];
    const isDefect = type === 'defect';

    // Lines with quantity still available to act on.
    const remainingLines = transaction.items
        .map((line) => ({ line, remaining: line.quantity - line.returned_quantity }))
        .filter((row) => row.remaining > 0);

    const form = useDialogForm<{ reason: string; refund_payment_type: PaymentType }>(open, () => ({
        reason: '',
        refund_payment_type: 'cash',
    }));
    const { data, setData, errors, processing, transform } = form;

    // The per-line quantities are local state rather than form data — they are
    // reshaped into `lines` by `transform` below — so they need clearing on open
    // of their own accord; `useDialogForm` only knows about the form.
    const [quantities, setQuantities] = useState<Record<string, number>>({});

    useEffect(() => {
        if (open) {
            setQuantities({});
        }
    }, [open]);

    const setQuantity = (id: string, value: string, max: number) => {
        const parsed = Math.max(0, Math.min(max, Math.floor(Number(value) || 0)));
        setQuantities((prev) => ({ ...prev, [id]: parsed }));
    };

    const selectedCount = Object.values(quantities).reduce((sum, q) => sum + q, 0);
    const canSubmit = selectedCount > 0;

    // Server-side line failures are keyed lines.N.quantity etc. (only reachable
    // on stale data, e.g. a concurrent return in another window), so surface the
    // first nested message rather than dropping it.
    const errorBag = errors as Record<string, string | undefined>;
    const lineError = errorBag.lines ?? Object.entries(errorBag).find(([key]) => key.startsWith('lines.'))?.[1];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        transform((current) => ({
            type,
            reason: current.reason,
            refund_payment_type: isDefect ? null : current.refund_payment_type,
            lines: remainingLines
                .map(({ line }) => ({ transaction_item_id: line.id, quantity: quantities[line.id] ?? 0 }))
                .filter((line) => line.quantity > 0),
        }));

        form.post(route('sales.returns.store', transaction.id), closeOnSuccess(onOpenChange));
    };

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={copy.title}
            description={copy.description}
            submitLabel={copy.action}
            processing={processing}
            submitDisabled={!canSubmit}
            onSubmit={submit}
            className="max-w-lg"
        >
            <div className="rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Product</TableHead>
                            <TableHead className="text-right">Remaining</TableHead>
                            <TableHead className="w-28 text-right">Qty</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {remainingLines.map(({ line, remaining }) => (
                            <TableRow key={line.id}>
                                <TableCell className="font-medium">{line.label}</TableCell>
                                <TableCell className="text-muted-foreground text-right tabular-nums">{remaining}</TableCell>
                                <TableCell className="text-right">
                                    <Input
                                        type="number"
                                        min={0}
                                        max={remaining}
                                        value={quantities[line.id] ?? 0}
                                        onChange={(e) => setQuantity(line.id, e.target.value, remaining)}
                                        aria-label={`Quantity to ${isDefect ? 'write off' : 'return'} — ${line.label}`}
                                        className="text-right tabular-nums"
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                        {remainingLines.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={3} className="text-muted-foreground text-center text-sm">
                                    Every line has already been fully returned.
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </div>

            <InputError message={lineError ?? errorBag.type} />

            {!isDefect && (
                <Field label="Refund method" htmlFor="refund-method" error={errors.refund_payment_type}>
                    <Select value={data.refund_payment_type} onValueChange={(value) => setData('refund_payment_type', value as PaymentType)}>
                        <SelectTrigger id="refund-method">
                            <SelectValue placeholder="Select a refund method" />
                        </SelectTrigger>
                        <SelectContent>
                            {REFUND_METHODS.map((method) => (
                                <SelectItem key={method} value={method}>
                                    {paymentLabel(method)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
            )}

            <Field label="Reason (optional)" htmlFor="return-reason" error={errors.reason}>
                <Textarea
                    id="return-reason"
                    value={data.reason}
                    onChange={(e) => setData('reason', e.target.value)}
                    maxLength={500}
                    rows={2}
                    placeholder="e.g. wrong size, damaged on arrival"
                />
            </Field>
        </FormDialog>
    );
}
