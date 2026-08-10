import InputError from '@/components/input-error';
import VariantPicker from '@/components/inventory/variant-picker';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { BatchVariantOption, Branch } from '@/types';
import { useForm } from '@inertiajs/react';
import { LoaderCircle, Plus, X } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

type Kind = 'adjustment' | 'transfer';
type Mode = 'add' | 'remove' | 'set';

/**
 * One row of the products section.
 *
 * The index signature is Inertia's requirement, not ours: `useForm` only accepts
 * a shape it can serialise, and a nested array of plain interfaces does not
 * satisfy it. `stock` never leaves the browser — `transform()` strips it at
 * submit, because what is on the shelf is something the server reads for itself.
 */
interface Line {
    [key: string]: string | number | null | undefined;
    variant_id: string;
    quantity: string;
    stock: number | null;
}

type BatchForm = {
    kind: Kind;
    mode: Mode;
    branch_id: string;
    from_branch_id: string;
    to_branch_id: string;
    notes: string;
    lines: Line[];
};

const emptyLine = (): Line => ({ variant_id: '', quantity: '', stock: null });

/**
 * A STOCK BATCH: many movements asked for as one act (docs/glossary.md §4, M13).
 *
 * Moving twelve products between two branches used to be twelve trips through
 * the transfer dialog, producing twelve unrelated pending rows and twelve copies
 * of one note for the receiving branch to read. This asks once. Every row it
 * writes shares a `batch_id`, and Pending Stocks shows them as one card.
 *
 * ## One shape or the other, never both
 *
 * `kind` swaps the branch row: an ADJUSTMENT names one branch and a direction, a
 * TRANSFER names two and no direction. They are not combinable because the
 * quantity on a line means a different thing in each — how many to add to or
 * remove from one shelf, versus how many are moving between two.
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
export default function StockBatchDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    const form = useForm<BatchForm>({
        kind: 'transfer',
        mode: 'add',
        branch_id: '',
        from_branch_id: '',
        to_branch_id: '',
        notes: '',
        lines: [emptyLine()],
    });
    const { data, setData, errors, processing, reset, clearErrors } = form;

    const [branches, setBranches] = useState<Branch[]>([]);

    // Unlike the per-variant dialogs, both pickers list EVERY branch including
    // this one: an adjustment aimed here is the common case, and a transfer can
    // be arranged by a branch that is neither end of it.
    const here = branches.find((branch) => branch.is_this_branch) ?? null;

    useEffect(() => {
        if (open) {
            reset();
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    // The roster, on open — the same fetch the per-variant dialogs make, and for
    // the same reason: it lives in Postgres, and reading it on every Inventory
    // render would put a database connection behind a screen a cashier uses all
    // day.
    useEffect(() => {
        if (!open) {
            return;
        }

        let cancelled = false;

        fetch(route('stock-updates.branches'), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => response.json())
            .then((payload: { branches?: Branch[] }) => {
                if (!cancelled) {
                    setBranches(payload.branches ?? []);
                }
            })
            .catch(() => undefined);

        return () => {
            cancelled = true;
        };
    }, [open]);

    // Default both branch fields to this one once the roster lands, so the
    // common case — a recount here, or a send from here — needs no picking.
    useEffect(() => {
        if (here && data.branch_id === '') {
            setData((current) => ({ ...current, branch_id: here.id, from_branch_id: current.from_branch_id || here.id }));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [here?.id]);

    const isTransfer = data.kind === 'transfer';

    // Whose shelf the picker's stock figures describe: the branch being adjusted,
    // or the branch a transfer is leaving.
    const stockBranch = isTransfer ? data.from_branch_id : data.branch_id;
    const remote = stockBranch !== '' && stockBranch !== here?.id;

    // Aiming at another branch drops "Set to count", so a mode already on it has
    // to land somewhere. Normalised on change rather than derived at render, so
    // what the toggle shows and what gets posted are the same value.
    const chooseKind = (kind: Kind) => {
        setData((current) => ({ ...current, kind, mode: kind === 'transfer' ? 'add' : current.mode }));
    };

    const chooseTargetBranch = (branchId: string) => {
        setData((current) => ({
            ...current,
            branch_id: branchId,
            mode: branchId !== here?.id && current.mode === 'set' ? 'add' : current.mode,
            // Every remembered figure belonged to the previous branch's shelf.
            lines: current.lines.map((line) => ({ ...line, stock: null })),
        }));
    };

    const chooseSender = (branchId: string) => {
        setData((current) => ({
            ...current,
            from_branch_id: branchId,
            lines: current.lines.map((line) => ({ ...line, stock: null })),
        }));
    };

    const setLine = (index: number, patch: Partial<Line>) => {
        setData(
            'lines',
            data.lines.map((line, i) => (i === index ? { ...line, ...patch } : line)),
        );
    };

    const addLine = () => setData('lines', [...data.lines, emptyLine()]);

    const removeLine = (index: number) =>
        setData(
            'lines',
            data.lines.filter((_, i) => i !== index),
        );

    // Notes are mandatory when removing stock or overwriting a count, exactly as
    // they are for one variant. A batch does not make the reason less necessary.
    const notesRequired = !isTransfer && (data.mode === 'remove' || data.mode === 'set');

    const filled = data.lines.filter((line) => line.variant_id !== '' && line.quantity !== '');
    const canSubmit = filled.length === data.lines.length && data.lines.length > 0;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.transform((current) => ({
            kind: current.kind,
            ...(current.kind === 'transfer'
                ? { from_branch_id: current.from_branch_id, to_branch_id: current.to_branch_id }
                : { branch_id: current.branch_id, mode: current.mode }),
            notes: current.notes,
            // `stock` is display only and is not part of the request.
            lines: current.lines.map((line) => ({ variant_id: line.variant_id, quantity: Number(line.quantity) })),
        }));

        form.post(route('stock-batches.store'), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onOpenChange(false);
            },
        });
    };

    // Nested error keys are not on the typed form's shape, so they are read off a
    // widened view of the same object — the pattern `item-form.tsx` uses.
    const lineErrors = errors as Record<string, string | undefined>;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>New stock batch</DialogTitle>
                        <DialogDescription>
                            Ask for several products to move as one request. Somebody at the affected branch confirms it before any stock changes.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label>What is happening</Label>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            value={data.kind}
                            onValueChange={(value) => value && chooseKind(value as Kind)}
                            className="justify-start"
                        >
                            <ToggleGroupItem value="transfer">Transfer</ToggleGroupItem>
                            <ToggleGroupItem value="adjustment">Stock adjustment</ToggleGroupItem>
                        </ToggleGroup>
                        <InputError message={errors.kind} />
                    </div>

                    {isTransfer ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="batch-from">From</Label>
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
                                <InputError message={errors.from_branch_id} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="batch-to">To</Label>
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
                                <InputError message={errors.to_branch_id} />
                            </div>
                        </div>
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="batch-branch">Branch</Label>
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
                                <InputError message={errors.branch_id} />
                            </div>
                            <div className="grid gap-2">
                                <Label>How</Label>
                                <ToggleGroup
                                    type="single"
                                    variant="outline"
                                    value={data.mode}
                                    onValueChange={(value) => value && setData('mode', value as Mode)}
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
                    )}

                    <div className="grid gap-2">
                        <div className="flex items-baseline justify-between">
                            <Label>Products</Label>
                            {remote && <span className="text-muted-foreground text-xs">Stock shown is that branch's last synced figure.</span>}
                        </div>

                        <div className="space-y-2">
                            {data.lines.map((line, index) => (
                                <div key={index} className="flex items-start gap-2">
                                    <div className="min-w-0 flex-1">
                                        <VariantPicker
                                            value={line.variant_id}
                                            branch={stockBranch}
                                            onChange={(variantId) => setLine(index, { variant_id: variantId })}
                                            onOptionLoaded={(option: BatchVariantOption) => {
                                                if (option.id === line.variant_id && option.stock !== line.stock) {
                                                    setLine(index, { stock: option.stock });
                                                }
                                            }}
                                        />
                                        <InputError message={lineErrors[`lines.${index}.variant_id`]} />
                                    </div>

                                    <div className="text-muted-foreground w-24 shrink-0 pt-2 text-right text-sm tabular-nums">
                                        {line.variant_id === '' ? '' : line.stock === null ? 'stock ?' : `on hand ${line.stock}`}
                                    </div>

                                    <div className="w-28 shrink-0">
                                        <Input
                                            type="number"
                                            inputMode="numeric"
                                            step={1}
                                            min={1}
                                            value={line.quantity}
                                            onChange={(event) => setLine(index, { quantity: event.target.value })}
                                            placeholder={data.mode === 'set' && !isTransfer ? 'count' : 'qty'}
                                            aria-label={`Quantity for row ${index + 1}`}
                                        />
                                        <InputError message={lineErrors[`lines.${index}.quantity`]} />
                                    </div>

                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="mt-0.5 shrink-0"
                                        aria-label={`Remove row ${index + 1}`}
                                        disabled={data.lines.length === 1}
                                        onClick={() => removeLine(index)}
                                    >
                                        <X className="size-4" />
                                    </Button>
                                </div>
                            ))}
                        </div>

                        <div>
                            <Button type="button" variant="outline" size="sm" onClick={addLine}>
                                <Plus className="size-4" /> Add more items
                            </Button>
                        </div>
                        <InputError message={errors.lines} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="batch-notes">
                            Notes{' '}
                            {notesRequired ? (
                                <span className="text-muted-foreground">(required)</span>
                            ) : (
                                <span className="text-muted-foreground">(optional)</span>
                            )}
                        </Label>
                        <Textarea
                            id="batch-notes"
                            value={data.notes}
                            onChange={(event) => setData('notes', event.target.value)}
                            required={notesRequired}
                            rows={2}
                            placeholder="e.g. Weekly restock for the new shelf"
                        />
                        <p className="text-muted-foreground text-xs">This note is attached to every product in the batch.</p>
                        <InputError message={errors.notes} />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button disabled={processing || !canSubmit}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Request {data.lines.length} {data.lines.length === 1 ? 'product' : 'products'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
