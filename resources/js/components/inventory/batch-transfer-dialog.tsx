import Field from '@/components/forms/field';
import { FormDialog } from '@/components/forms/form-dialog';
import { BatchLines, BatchNotes, emptyLine, type Line } from '@/components/inventory/batch-lines';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import useBranchRoster from '@/hooks/use-branch-roster';
import { closeOnSuccess, useDialogForm } from '@/hooks/use-dialog-form';
import { FormEventHandler, useEffect } from 'react';

type TransferBatchForm = {
    from_branch_id: string;
    to_branch_id: string;
    notes: string;
    lines: Line[];
};

/**
 * A BATCH TRANSFER: many products moving between TWO branches as one act
 * (docs/glossary.md §4, M13).
 *
 * Moving twelve products between two branches used to be twelve trips through
 * the per-variant transfer dialog, producing twelve unrelated pending rows and
 * twelve copies of one note for the receiving branch to read. This asks once.
 * Every row it writes shares a `batch_id`, and Pending Stocks shows them as one
 * card at both ends.
 *
 * Adjustments are {@see BatchAdjustDialog}, and the two do not share a shape: a
 * transfer names two branches and no direction, an adjustment names one branch
 * and a direction, and the quantity on a line means a different thing in each —
 * how many are moving between two shelves, versus how many to add to or remove
 * from one. They were one dialog with a toggle for exactly one milestone.
 *
 * Both pickers list EVERY branch including this one: a transfer can be arranged
 * by a branch that is neither end of it.
 *
 * ## It asks; it does not move anything
 *
 * This writes only the SENDING legs, as pending rows. The sender confirms what
 * actually left, `AdjustmentResolver::secondLeg()` carries the batch onto each
 * receiving leg, and somebody at the other branch confirms what actually
 * arrived. A shortfall is written off rather than bounced back.
 */
export default function BatchTransferDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    const { branches, here } = useBranchRoster(open);

    const form = useDialogForm<TransferBatchForm>(open, () => ({
        from_branch_id: here?.id ?? '',
        to_branch_id: '',
        notes: '',
        lines: [emptyLine()],
    }));
    const { data, setData, errors, processing } = form;

    // Default the sender to this branch once the roster lands, so the common
    // case — a send from here — needs no picking. Only for the FIRST open, when
    // the fetch is still in flight and the initial values above had no `here` to
    // read; every reopen finds the roster already in state and seeds itself.
    // Without both halves the default was lost on reopen — `here` is a new object
    // per fetch but `here?.id` is the same string, so this effect does not re-run.
    useEffect(() => {
        if (here && data.from_branch_id === '') {
            setData('from_branch_id', here.id);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [here?.id]);

    // The stock figures on each line describe the shelf the goods are leaving.
    const remote = data.from_branch_id !== '' && data.from_branch_id !== here?.id;

    const chooseSender = (branchId: string) => {
        setData((current) => ({
            ...current,
            from_branch_id: branchId,
            // Every remembered figure belonged to the previous branch's shelf.
            lines: current.lines.map((line) => ({ ...line, stock: null })),
        }));
    };

    const filled = data.lines.filter((line) => line.variant_id !== '' && line.quantity !== '');
    const canSubmit = filled.length === data.lines.length && data.lines.length > 0;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.transform((current) => ({
            kind: 'transfer',
            from_branch_id: current.from_branch_id,
            to_branch_id: current.to_branch_id,
            notes: current.notes,
            // `stock` is display only and is not part of the request.
            lines: current.lines.map((line) => ({ variant_id: line.variant_id, quantity: Number(line.quantity) })),
        }));

        form.post(route('stock-batches.store'), closeOnSuccess(onOpenChange));
    };

    // Nested error keys are not on the typed form's shape, so they are read off a
    // widened view of the same object — the pattern `item-form.tsx` uses.
    const lineErrors = errors as Record<string, string | undefined>;

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Batch transfer"
            description="Ask for several products to move between two branches as one request. Both branches confirm their side before any stock moves."
            submitLabel={`Request ${data.lines.length} ${data.lines.length === 1 ? 'product' : 'products'}`}
            processing={processing}
            submitDisabled={!canSubmit}
            onSubmit={submit}
            className="max-h-[90vh] overflow-y-auto sm:max-w-3xl"
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="From" htmlFor="batch-from" error={errors.from_branch_id}>
                    <Select value={data.from_branch_id || undefined} onValueChange={chooseSender}>
                        <SelectTrigger id="batch-from">
                            <SelectValue placeholder="Sending branch" />
                        </SelectTrigger>
                        <SelectContent>
                            {branches.map((branch) => (
                                <SelectItem key={branch.id} value={branch.id}>
                                    {branch.code} — {branch.name}
                                    {branch.is_this_branch ? ' (this branch)' : ''}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
                <Field label="To" htmlFor="batch-to" error={errors.to_branch_id}>
                    <Select value={data.to_branch_id || undefined} onValueChange={(value) => setData('to_branch_id', value)}>
                        <SelectTrigger id="batch-to">
                            <SelectValue placeholder="Receiving branch" />
                        </SelectTrigger>
                        <SelectContent>
                            {branches
                                .filter((branch) => branch.id !== data.from_branch_id)
                                .map((branch) => (
                                    <SelectItem key={branch.id} value={branch.id}>
                                        {branch.code} — {branch.name}
                                        {branch.is_this_branch ? ' (this branch)' : ''}
                                    </SelectItem>
                                ))}
                        </SelectContent>
                    </Select>
                </Field>
            </div>

            <BatchLines
                lines={data.lines}
                branch={data.from_branch_id}
                remote={remote}
                quantityPlaceholder="qty"
                errors={lineErrors}
                linesError={errors.lines}
                onChange={(lines) => setData('lines', lines)}
            />

            <BatchNotes value={data.notes} onChange={(notes) => setData('notes', notes)} required={false} error={errors.notes} />
        </FormDialog>
    );
}
