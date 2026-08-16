import Field from '@/components/forms/field';
import { FormDialog } from '@/components/forms/form-dialog';
import InputError from '@/components/forms/input-error';
import { BatchLines, BatchNotes, emptyLine, type Line } from '@/components/inventory/batch-lines';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import useBranchRoster from '@/hooks/use-branch-roster';
import { closeOnSuccess, useDialogForm } from '@/hooks/use-dialog-form';
import { FormEventHandler, useEffect } from 'react';

type Mode = 'add' | 'remove' | 'set';

type AdjustBatchForm = {
    mode: Mode;
    branch_id: string;
    notes: string;
    lines: Line[];
};

/**
 * A BATCH ADJUSTMENT: many products recounted, restocked or written down at ONE
 * branch as one act (docs/glossary.md §4, M13).
 *
 * Every row it writes shares a `batch_id`, and Pending Stocks shows them as one
 * card instead of a dozen unrelated rows carrying a dozen copies of one note.
 *
 * Transfers are {@see BatchTransferDialog}, and the two do not share a shape: an
 * adjustment names one branch and a direction, a transfer names two branches and
 * no direction, and the quantity on a line means a different thing in each — how
 * many to add to or remove from one shelf, versus how many are moving between
 * two. They were one dialog with a toggle for exactly one milestone.
 *
 * "Set to count" is offered only when the target is this branch, exactly as
 * {@see StockAdjustDialog} does per-variant: another branch's on-hand reads as a
 * baseline that excludes its unsynced sales, so a delta derived from it would be
 * a guess presented as a count.
 *
 * ## It asks; it does not move anything
 *
 * Like every other issuing path, this writes PENDING rows. Somebody at the
 * affected branch confirms them under Pending Stocks, and that is what moves the
 * stock — including when the branch is this one.
 */
export default function BatchAdjustDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    // Unlike the per-variant dialog, the picker lists EVERY branch including this
    // one: an adjustment aimed here is the common case.
    const { branches, here } = useBranchRoster(open);

    const form = useDialogForm<AdjustBatchForm>(open, () => ({
        mode: 'add',
        branch_id: here?.id ?? '',
        notes: '',
        lines: [emptyLine()],
    }));
    const { data, setData, errors, processing } = form;

    // Default to this branch once the roster lands, so a recount here needs no
    // picking. Only for the FIRST open, when the fetch is still in flight and the
    // initial values above had no `here` to read; every reopen finds the roster
    // already in state and seeds itself. Without both halves the default was lost
    // on reopen — `here` is a new object per fetch but `here?.id` is the same
    // string, so this effect does not re-run.
    useEffect(() => {
        if (here && data.branch_id === '') {
            setData('branch_id', here.id);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [here?.id]);

    const remote = data.branch_id !== '' && data.branch_id !== here?.id;

    // Aiming at another branch drops "Set to count", so a mode already on it has
    // to land somewhere. Normalised on change rather than derived at render, so
    // what the toggle shows and what gets posted are the same value.
    const chooseTargetBranch = (branchId: string) => {
        setData((current) => ({
            ...current,
            branch_id: branchId,
            mode: branchId !== here?.id && current.mode === 'set' ? 'add' : current.mode,
            // Every remembered figure belonged to the previous branch's shelf.
            lines: current.lines.map((line) => ({ ...line, stock: null })),
        }));
    };

    const notesRequired = data.mode === 'remove' || data.mode === 'set';

    const filled = data.lines.filter((line) => line.variant_id !== '' && line.quantity !== '');
    const canSubmit = filled.length === data.lines.length && data.lines.length > 0;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.transform((current) => ({
            kind: 'adjustment',
            branch_id: current.branch_id,
            mode: current.mode,
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
            title="Batch adjustment"
            description="Ask for several products on one branch's shelves to change as one request. Somebody at that branch confirms it before any stock moves."
            submitLabel={`Request ${data.lines.length} ${data.lines.length === 1 ? 'product' : 'products'}`}
            processing={processing}
            submitDisabled={!canSubmit}
            onSubmit={submit}
            className="max-h-[90vh] overflow-y-auto sm:max-w-3xl"
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Branch" htmlFor="batch-branch" error={errors.branch_id}>
                    <Select value={data.branch_id || undefined} onValueChange={chooseTargetBranch}>
                        <SelectTrigger id="batch-branch">
                            <SelectValue placeholder="Which branch" />
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
                {/* Not a <Field>: that names ONE control, and a `<label for>`
                    cannot name a group of them. The group carries its own
                    aria-label. */}
                <div className="grid gap-2">
                    <Label>How</Label>
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={data.mode}
                        onValueChange={(value) => value && setData('mode', value as Mode)}
                        aria-label="How"
                        className="justify-start"
                    >
                        <ToggleGroupItem value="add">Add</ToggleGroupItem>
                        <ToggleGroupItem value="remove">Remove</ToggleGroupItem>
                        {/* Hidden for another branch: deriving a delta would
                            need that branch's on-hand, which this device only
                            knows as a baseline missing its unsynced sales. */}
                        {!remote && <ToggleGroupItem value="set">Set to count</ToggleGroupItem>}
                    </ToggleGroup>
                    <InputError message={errors.mode} />
                </div>
            </div>

            <BatchLines
                lines={data.lines}
                branch={data.branch_id}
                remote={remote}
                quantityPlaceholder={data.mode === 'set' ? 'count' : 'qty'}
                errors={lineErrors}
                linesError={errors.lines}
                onChange={(lines) => setData('lines', lines)}
            />

            <BatchNotes value={data.notes} onChange={(notes) => setData('notes', notes)} required={notesRequired} error={errors.notes} />
        </FormDialog>
    );
}
