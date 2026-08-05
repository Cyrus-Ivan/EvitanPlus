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

type Mode = 'set' | 'delta';

/** Move stock at one branch, or move it between two. */
type Kind = 'adjust' | 'transfer';

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
    from_branch_id?: string;
    to_branch_id?: string;
};

/** The sentinel for "this branch", which is the default and needs no id. */
const HERE = 'here';

/**
 * Manual stock adjustment dialog. Two modes:
 *  - "Add / Remove" (default): enter a signed change (+ add, − remove).
 *  - "Set to count": enter the true counted on-hand; the backend derives the delta.
 * Notes are optional except when setting a count, or when removing stock
 * (a negative add/remove) — both need an explanation for the ledger.
 *
 * ## Nothing here moves stock any more
 *
 * Every one of the three destinations writes a PENDING row and stops. Somebody
 * at the affected branch confirms it under Pending stock adjustments on Stock
 * History, and that is what moves the stock — including when the branch is this
 * one, because a recount is still a claim about a shelf someone has to look at.
 *
 *  - **This branch** → `stock.adjust`.
 *  - **Another branch** → `stock-updates.store`, for that branch to answer.
 *  - **Transfer** → `stock-transfers.store`, which is TWO answers: the sending
 *    branch confirms what left, and confirming it asks the receiving branch what
 *    arrived. Either can approve part of it, and a unit that never turns up is
 *    written off rather than bounced back.
 *
 * "Set to count" is hidden for a remote target on purpose. The only on-hand
 * figure available here would be a baseline that excludes the target's unsynced
 * sales, so a derived delta would be a guess presented as a count. It is hidden
 * for a transfer for a plainer reason: a transfer moves a quantity, and "set the
 * other branch to 25" is not a thing anyone means.
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
    const form = useForm<AdjustForm>({ mode: 'delta', value: '', notes: '' });
    const { data, setData, errors, processing, reset, clearErrors } = form;

    const [branches, setBranches] = useState<Branch[]>([]);
    const [target, setTarget] = useState<string>(HERE);
    const [kind, setKind] = useState<Kind>('adjust');
    const [destination, setDestination] = useState<string>('');

    // Reset on open so values/errors never leak between variants.
    useEffect(() => {
        if (open) {
            setData({ mode: 'delta', value: '', notes: '' });
            setTarget(HERE);
            setKind('adjust');
            setDestination('');
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

    const transferring = kind === 'transfer';
    const elsewhere = !transferring && target !== HERE;
    const current = variant?.current_stock ?? 0;
    const mode: Mode = elsewhere || transferring ? 'delta' : data.mode;

    // Notes are mandatory when setting a count or when removing stock. A
    // transfer needs none: both branches will say what actually happened when
    // they answer, which is the explanation that matters.
    const notesRequired = !transferring && (mode === 'set' || Number(data.value) < 0);

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
        if (transferring) {
            form.transform(() => ({
                from_branch_id: target === HERE ? thisBranch?.id : target,
                to_branch_id: destination,
                variant_id: variant.id,
                // Always positive: the operator says how many are moving, and
                // which way is the system's business (IssueTransferRequest).
                quantity: Math.abs(Number(data.value)),
                notes: data.notes,
            }));

            form.post(route('stock-transfers.store'), done);

            return;
        }

        if (elsewhere) {
            form.transform(() => ({
                branch_id: target,
                variant_id: variant.id,
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
    const resulting = hasValue && !elsewhere && !transferring ? (mode === 'set' ? parsed : current + parsed) : null;

    // In add/remove, zero is not a change: a positive value adds, a negative removes.
    const deltaIsZero = mode === 'delta' && hasValue && parsed === 0;
    const canSubmit = hasValue && !deltaIsZero && (!transferring || destination !== '');

    // Only worth showing the picker when there is somewhere else to send it.
    const others = branches.filter((branch) => !branch.is_this_branch);
    const thisBranch = branches.find((branch) => branch.is_this_branch);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>{transferring ? 'Transfer stock' : 'Adjust stock'}</DialogTitle>
                        <DialogDescription>
                            {variant ? (
                                <>
                                    {itemName ? `${itemName} — ` : ''}
                                    {variant.name}
                                    {elsewhere || transferring ? null : (
                                        <>
                                            {' '}
                                            · current on-hand <span className="text-foreground font-medium tabular-nums">{current}</span>
                                        </>
                                    )}
                                </>
                            ) : null}
                        </DialogDescription>
                    </DialogHeader>

                    {/* Only when there is somewhere to move stock TO. On a
                        single-branch business, or an offline device that could
                        not fetch the roster, a transfer is not a thing that can
                        be described. */}
                    {others.length > 0 && (
                        <div className="grid gap-2">
                            <Label>What is happening</Label>
                            <ToggleGroup
                                type="single"
                                value={kind}
                                onValueChange={(value) => value && setKind(value as Kind)}
                                className="justify-start"
                            >
                                <ToggleGroupItem value="adjust">Adjust stock</ToggleGroupItem>
                                <ToggleGroupItem value="transfer">Move to another branch</ToggleGroupItem>
                            </ToggleGroup>
                        </div>
                    )}

                    {others.length > 0 && (
                        <div className="grid gap-2">
                            <Label htmlFor="adjust-branch">{transferring ? 'From' : 'Branch'}</Label>
                            <Select value={target} onValueChange={setTarget}>
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
                            <InputError message={errors.branch_id ?? errors.from_branch_id} />
                        </div>
                    )}

                    {transferring && (
                        <div className="grid gap-2">
                            <Label htmlFor="adjust-destination">To</Label>
                            <Select value={destination} onValueChange={setDestination}>
                                <SelectTrigger id="adjust-destination">
                                    <SelectValue placeholder="Pick the receiving branch" />
                                </SelectTrigger>
                                <SelectContent>
                                    {branches
                                        .filter((branch) => (target === HERE ? !branch.is_this_branch : branch.id !== target))
                                        .map((branch) => (
                                            <SelectItem key={branch.id} value={branch.id}>
                                                {branch.code} — {branch.name}
                                            </SelectItem>
                                        ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.to_branch_id} />
                        </div>
                    )}

                    <p className="text-muted-foreground text-sm">
                        {transferring
                            ? 'Nothing moves yet. The sending branch confirms what actually left, and that asks the receiving branch what actually arrived — either can approve part of it.'
                            : elsewhere
                              ? 'This asks that branch to move the stock. Somebody there confirms it before anything changes, and can approve part of it or turn it down.'
                              : 'This asks for the stock to move. Confirm it under Pending stock adjustments on Stock History to apply it.'}
                    </p>

                    {!elsewhere && !transferring && (
                        <div className="grid gap-2">
                            <Label>Mode</Label>
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                value={data.mode}
                                onValueChange={(value) => value && setData('mode', value as Mode)}
                                className="justify-start"
                            >
                                <ToggleGroupItem value="delta">Add / Remove</ToggleGroupItem>
                                <ToggleGroupItem value="set">Set to count</ToggleGroupItem>
                            </ToggleGroup>
                        </div>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="adjust-value">
                            {transferring ? 'How many are moving' : mode === 'set' ? 'Counted quantity' : 'Change (+ add, − remove)'}
                        </Label>
                        <Input
                            id="adjust-value"
                            type="number"
                            inputMode="numeric"
                            step={1}
                            min={mode === 'set' ? 0 : undefined}
                            value={data.value}
                            onChange={(e) => setData('value', e.target.value)}
                            required
                            autoFocus
                            placeholder={mode === 'set' ? 'e.g. 37' : 'e.g. -3 or 10'}
                        />
                        {deltaIsZero ? (
                            <p className="text-destructive text-sm">Enter a positive number to add stock or a negative number to remove it.</p>
                        ) : (
                            resulting !== null && (
                                <p className="text-muted-foreground text-sm">
                                    New on-hand: <span className="text-foreground font-medium tabular-nums">{resulting}</span>
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
                            {transferring ? 'Request transfer' : 'Request adjustment'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
