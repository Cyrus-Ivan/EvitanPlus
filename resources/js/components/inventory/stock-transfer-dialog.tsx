import Field from '@/components/forms/field';
import { FormDialog } from '@/components/forms/form-dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import useBranchRoster from '@/hooks/use-branch-roster';
import { closeOnSuccess, useDialogForm } from '@/hooks/use-dialog-form';
import type { Variant } from '@/types';
import { FormEventHandler, useEffect, useState } from 'react';

/**
 * The local fields, plus the four the queued form is validated on — declared here
 * only so `errors.quantity` / `errors.from_branch_id` are reachable, since Inertia
 * keys errors by the form's own shape. They are never `setData`'d; `transform()`
 * builds the payload at submit time.
 */
type TransferForm = {
    value: string;
    notes: string;
    from_branch_id?: string;
    to_branch_id?: string;
    variant_id?: string;
    quantity?: number;
};

/** The sentinel for "this branch", which is the default and needs no id. */
const HERE = 'here';

/**
 * Moving stock from one branch to another.
 *
 * This was a mode of {@see StockAdjustDialog} until the two turned out to be
 * different things wearing one form. An adjustment is one claim about one shelf,
 * answered by one person; a transfer is two claims about two shelves, and the
 * direction is the two branch pickers rather than anything about the number.
 * Sharing a form meant the number field carried adjust-mode affordances into a
 * transfer: no lower bound, a placeholder reading `e.g. -3 or 10`, and a submit
 * that quietly `Math.abs`'d whatever was typed. Somebody entering −3 to mean "send
 * 3 away" got a 3-unit transfer and no indication their minus sign had been
 * ignored.
 *
 * So the quantity here is **positive and means "how many are moving"**. The signs
 * are the system's business: `InventoryQueueController::transfer()` writes the
 * sending leg negative and `AdjustmentResolver::secondLeg()` writes the receiving
 * leg positive, and `IssueTransferRequest` enforces `min:1` so those two can rely
 * on each other. The adjust dialog has since adopted the same rule — a positive
 * number and a separate Add/Remove — which is why the two look alike again; what
 * still separates them is one answer versus two.
 *
 * ## Nothing here moves stock
 *
 * A transfer is TWO answers. This writes one pending row at the sending branch;
 * somebody there confirms what actually left, and confirming it asks the receiving
 * branch what actually arrived. Either can approve part of it, and a unit that
 * never turns up is written off rather than bounced back.
 *
 * The roster is fetched when the dialog opens rather than passed in as a prop: it
 * lives in Postgres, and reading it on every inventory page render would put a
 * database connection behind the screen a cashier uses all day. An offline device
 * gets an empty roster, which is the honest answer — there is no branch to send to
 * and no connection to record it with.
 */
export default function StockTransferDialog({
    open,
    onOpenChange,
    variant,
    itemName,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    variant: Variant | null;
    itemName?: string;
}) {
    const form = useDialogForm<TransferForm>(open, () => ({ value: '', notes: '' }), [variant?.id]);
    const { data, setData, errors, processing } = form;

    const [source, setSource] = useState<string>(HERE);
    const [destination, setDestination] = useState<string>('');
    const { branches, here: thisBranch, loading } = useBranchRoster(open);

    // The two branch pickers are local state rather than form data (the payload is
    // built by `transform` at submit), so they clear on open of their own accord.
    useEffect(() => {
        if (open) {
            setSource(HERE);
            setDestination('');
        }
    }, [open]);

    const others = branches.filter((branch) => !branch.is_this_branch);
    const nowhereToSend = !loading && others.length === 0;

    const parsed = Number(data.value);
    const hasValue = data.value !== '' && Number.isFinite(parsed);
    // Positive only. Mirrors IssueTransferRequest's `min:1` so the field says what
    // the rule already is, rather than reinterpreting what was typed.
    const quantityInvalid = hasValue && parsed < 1;
    const canSubmit = hasValue && !quantityInvalid && destination !== '' && !nowhereToSend;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (!variant || !canSubmit) {
            return;
        }

        form.transform(() => ({
            from_branch_id: source === HERE ? thisBranch?.id : source,
            to_branch_id: destination,
            variant_id: variant.id,
            quantity: Number(data.value),
            notes: data.notes,
        }));

        form.post(route('stock-transfers.store'), closeOnSuccess(onOpenChange));
    };

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Transfer stock"
            description={
                variant ? (
                    <>
                        {itemName ? `${itemName} — ` : ''}
                        {variant.name || itemName}
                    </>
                ) : null
            }
            // With nowhere to send, the dialog exists only to say so: no submit at
            // all rather than a disabled one, and the cancel becomes the way out
            // rather than a way to abandon something.
            submitLabel={nowhereToSend ? null : 'Request transfer'}
            cancelLabel={nowhereToSend ? 'Close' : 'Cancel'}
            processing={processing}
            submitDisabled={!canSubmit}
            onSubmit={submit}
        >
            {nowhereToSend ? (
                <p className="text-muted-foreground text-sm">
                    There is no other branch to send stock to. Either this business has one branch, or this device cannot reach the business database
                    right now — a transfer has to be recorded there.
                </p>
            ) : (
                <>
                    <Field label="From" htmlFor="transfer-source" error={errors.from_branch_id}>
                        <Select value={source} onValueChange={setSource}>
                            <SelectTrigger id="transfer-source">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={HERE}>This branch</SelectItem>
                                {others.map((branch) => (
                                    <SelectItem key={branch.id} value={branch.id}>
                                        {branch.code} — {branch.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>

                    <Field label="To" htmlFor="transfer-destination" error={errors.to_branch_id}>
                        <Select value={destination} onValueChange={setDestination}>
                            <SelectTrigger id="transfer-destination">
                                <SelectValue placeholder="Pick the receiving branch" />
                            </SelectTrigger>
                            <SelectContent>
                                {branches
                                    .filter((branch) => (source === HERE ? !branch.is_this_branch : branch.id !== source))
                                    .map((branch) => (
                                        <SelectItem key={branch.id} value={branch.id}>
                                            {branch.code} — {branch.name}
                                        </SelectItem>
                                    ))}
                            </SelectContent>
                        </Select>
                    </Field>

                    <p className="text-muted-foreground text-sm">
                        Nothing moves yet. The sending branch confirms what actually left, and that asks the receiving branch what actually arrived —
                        either can approve part of it.
                    </p>

                    <Field label="How many are moving" htmlFor="transfer-quantity" error={errors.quantity}>
                        <Input
                            id="transfer-quantity"
                            type="number"
                            inputMode="numeric"
                            step={1}
                            min={1}
                            value={data.value}
                            onChange={(e) => setData('value', e.target.value)}
                            required
                            autoFocus
                            placeholder="e.g. 10"
                        />
                        {quantityInvalid ? (
                            <p className="text-destructive text-sm">Enter how many are being moved.</p>
                        ) : (
                            <p className="text-muted-foreground text-sm">
                                Which way the stock goes is the From and To above — this is just how many.
                            </p>
                        )}
                    </Field>

                    <Field
                        label={
                            <>
                                Notes <span className="text-muted-foreground">(optional)</span>
                            </>
                        }
                        htmlFor="transfer-notes"
                        error={errors.notes}
                    >
                        <Textarea
                            id="transfer-notes"
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                            rows={2}
                            placeholder="e.g. Restocking the branch ahead of the weekend."
                        />
                    </Field>
                </>
            )}
        </FormDialog>
    );
}
