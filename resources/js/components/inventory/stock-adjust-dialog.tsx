import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { Branch, Variant } from '@/types';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

type Mode = 'add' | 'remove' | 'set';

/**
 * The local fields, plus the three the queued form is validated on — declared
 * here only so `errors.branch_id` / `errors.quantity` are reachable, since
 * Inertia keys errors by the form's own shape. They are never `setData`'d;
 * `transform()` builds the remote payload at submit time.
 */
type AdjustForm = {
    mode: Mode;
    value: string;
    notes: string;
    branch_id?: string;
    variant_id?: string;
    quantity?: number;
};

/** The sentinel for "this branch", which is the default and needs no id. */
const HERE = 'here';

/**
 * Manual stock adjustment dialog. Three modes:
 *  - "Add" (default): how many are arriving.
 *  - "Remove": how many are leaving.
 *  - "Set to count": the true counted on-hand; the backend derives the delta.
 * Notes are optional except when removing stock or setting a count — both need an
 * explanation for the ledger.
 *
 * ## The number is positive; the direction is the mode
 *
 * Add and Remove were one "Add / Remove" mode over a SIGNED field, where a minus
 * sign meant a removal. That put the direction of a real stock movement in a
 * character somebody had to remember to type, and omitting it does the exact
 * opposite of what was meant — silently, since 3 is as valid an entry as −3.
 * So the sign is now the backend's business ({@see StockAdjustmentRequest},
 * `min:1`), which is what {@see StockTransferDialog} already did.
 *
 * ## Adjusting only. Transfers are {@see StockTransferDialog}
 *
 * This form used to carry a third destination — move it to another branch — and
 * the two do not share a shape. An adjustment is one claim about one shelf,
 * answered by one person; a transfer is two claims about two shelves, answered by
 * two. That they now take the same kind of number is not a reason to recombine
 * them: the old shared field is what let a transfer quietly `Math.abs()` a typed
 * `-3` into a 3-unit move.
 *
 * ## Nothing here moves stock any more
 *
 * Both destinations write a PENDING row and stop. Somebody at the affected branch
 * confirms it under Pending stock adjustments on Stock History, and that is what
 * moves the stock — including when the branch is this one, because a recount is
 * still a claim about a shelf someone has to look at.
 *
 *  - **This branch** → `stock.adjust`.
 *  - **Another branch** → `stock-updates.store`, for that branch to answer.
 *
 * "Set to count" is hidden for a remote target on purpose. The only on-hand figure
 * available here would be a baseline that excludes the target's unsynced sales, so
 * a derived delta would be a guess presented as a count.
 *
 * The roster is fetched when the dialog opens rather than passed in as a prop:
 * it lives in Postgres, and reading it on every inventory page render would put
 * a database connection behind the screen a cashier uses all day.
 */
export default function StockAdjustDialog({
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
    const form = useForm<AdjustForm>({ mode: 'add', value: '', notes: '' });
    const { data, setData, errors, processing, reset, clearErrors } = form;

    const [branches, setBranches] = useState<Branch[]>([]);
    const [target, setTarget] = useState<string>(HERE);

    // Reset on open so values/errors never leak between variants.
    useEffect(() => {
        if (open) {
            setData({ mode: 'add', value: '', notes: '' });
            setTarget(HERE);
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, variant?.id]);

    // The roster, on open. A failure is not an error state: an offline device
    // simply has no other branches to offer, and adjusting this one still works.
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

    const elsewhere = target !== HERE;
    const current = variant?.current_stock ?? 0;
    const mode: Mode = data.mode;

    // Aiming at another branch drops "Set to count", so a mode already on it has
    // to land somewhere. Normalised here rather than derived at render, so what
    // the toggle shows and what gets posted are the same value.
    const chooseTarget = (value: string) => {
        setTarget(value);

        if (value !== HERE && data.mode === 'set') {
            setData('mode', 'add');
        }
    };

    // Notes are mandatory when removing stock or setting a count.
    const notesRequired = mode === 'remove' || mode === 'set';

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (!variant) {
            return;
        }

        const done = {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onOpenChange(false);
            },
        };

        // Re-declared on every submit, so switching the target after one attempt
        // cannot leave the previous transform in place.
        if (elsewhere) {
            form.transform(() => ({
                branch_id: target,
                variant_id: variant.id,
                // Positive, with `mode` carrying the direction — the queue column
                // is signed but the request is not, and the controller bridges
                // them. See IssueStockAdjustmentRequest.
                mode: data.mode,
                quantity: Number(data.value),
                notes: data.notes,
            }));

            form.post(route('stock-updates.store'), done);

            return;
        }

        form.transform(({ mode, value, notes }) => ({ mode, value, notes }));

        form.post(route('stock.adjust', variant.id), done);
    };

    // Preview the resulting on-hand so the user sees the effect before saving.
    // Only for this branch: another branch's on-hand is not known here.
    const parsed = Number(data.value);
    const hasValue = data.value !== '' && Number.isFinite(parsed);
    const resulting = hasValue && !elsewhere ? (mode === 'set' ? parsed : mode === 'remove' ? current - parsed : current + parsed) : null;

    // Positive only, mirroring the `min:1` / `min:0` the requests enforce — a
    // count of zero is a real answer, a movement of zero is not. Nothing here
    // reinterprets what was typed; it just refuses to send it.
    const quantityInvalid = hasValue && (mode === 'set' ? parsed < 0 : parsed < 1);
    const canSubmit = hasValue && !quantityInvalid;

    // Only worth showing the picker when there is somewhere else to aim this.
    const others = branches.filter((branch) => !branch.is_this_branch);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Adjust stock</DialogTitle>
                        <DialogDescription>
                            {variant ? (
                                <>
                                    {itemName ? `${itemName} — ` : ''}
                                    {variant.name}
                                    {elsewhere ? null : (
                                        <>
                                            {' '}
                                            · current on-hand <span className="text-foreground font-medium tabular-nums">{current}</span>
                                        </>
                                    )}
                                </>
                            ) : null}
                        </DialogDescription>
                    </DialogHeader>

                    {others.length > 0 && (
                        <div className="grid gap-2">
                            <Label htmlFor="adjust-branch">Branch</Label>
                            <Select value={target} onValueChange={chooseTarget}>
                                <SelectTrigger id="adjust-branch">
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
                            <InputError message={errors.branch_id} />
                        </div>
                    )}

                    <p className="text-muted-foreground text-sm">
                        {elsewhere
                            ? 'This asks that branch to move the stock. Somebody there confirms it before anything changes, and can approve part of it or turn it down.'
                            : 'This asks for the stock to move. Confirm it under Pending stock adjustments on Stock History to apply it.'}
                    </p>

                    <div className="grid gap-2">
                        <Label>Mode</Label>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            value={data.mode}
                            onValueChange={(value) => value && setData('mode', value as Mode)}
                            className="justify-start"
                        >
                            <ToggleGroupItem value="add">Add</ToggleGroupItem>
                            <ToggleGroupItem value="remove">Remove</ToggleGroupItem>
                            {/* Hidden for another branch: deriving a delta would need
                                that branch's on-hand, which this device only knows as
                                a baseline missing its unsynced sales. */}
                            {!elsewhere && <ToggleGroupItem value="set">Set to count</ToggleGroupItem>}
                        </ToggleGroup>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="adjust-value">
                            {mode === 'set' ? 'Counted quantity' : mode === 'remove' ? 'How many are leaving' : 'How many are arriving'}
                        </Label>
                        <Input
                            id="adjust-value"
                            type="number"
                            inputMode="numeric"
                            step={1}
                            min={mode === 'set' ? 0 : 1}
                            value={data.value}
                            onChange={(e) => setData('value', e.target.value)}
                            required
                            autoFocus
                            placeholder={mode === 'set' ? 'e.g. 37' : 'e.g. 10'}
                        />
                        {quantityInvalid ? (
                            <p className="text-destructive text-sm">
                                {mode === 'set' ? 'A counted quantity cannot be negative.' : 'Enter how many are being added or removed.'}
                            </p>
                        ) : (
                            resulting !== null && (
                                <p className="text-muted-foreground text-sm">
                                    New on-hand: <span className="text-foreground font-medium tabular-nums">{resulting}</span>
                                    {/* Shown rather than blocked: asking for more than
                                        is on the shelf is allowed, and whether it fits
                                        is a question for whoever confirms it — by which
                                        time a delivery may have arrived. */}
                                    {resulting < 0 ? ' — more than there is, so this can only be partly approved.' : ''}
                                </p>
                            )
                        )}
                        <InputError message={errors.value ?? errors.quantity} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="adjust-notes">
                            Notes{' '}
                            {notesRequired ? (
                                <span className="text-muted-foreground">(required)</span>
                            ) : (
                                <span className="text-muted-foreground">(optional)</span>
                            )}
                        </Label>
                        <Textarea
                            id="adjust-notes"
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                            required={notesRequired}
                            rows={2}
                            placeholder="e.g. Physical recount, damaged goods…"
                        />
                        <InputError message={errors.notes} />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button disabled={processing || !canSubmit}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Request adjustment
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
