import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type { Branch, Variant } from '@/types';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
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
 * different things wearing one form. An adjustment is a claim about one shelf and
 * is signed — plus adds, minus removes. A transfer is a quantity and a direction,
 * and the direction is the two branch pickers, not the number. Sharing a form
 * meant the number field carried adjust-mode affordances into a transfer: no lower
 * bound, a placeholder reading `e.g. -3 or 10`, and a submit that quietly
 * `Math.abs`'d whatever was typed. Somebody entering −3 to mean "send 3 away" got
 * a 3-unit transfer and no indication their minus sign had been ignored.
 *
 * So the quantity here is **positive and means "how many are moving"**. The signs
 * are the system's business: `InventoryQueueController::transfer()` writes the
 * sending leg negative and `AdjustmentResolver::secondLeg()` writes the receiving
 * leg positive, and `IssueTransferRequest` enforces `min:1` so those two can rely
 * on each other.
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
    const form = useForm<TransferForm>({ value: '', notes: '' });
    const { data, setData, errors, processing, reset, clearErrors } = form;

    const [branches, setBranches] = useState<Branch[]>([]);
    const [source, setSource] = useState<string>(HERE);
    const [destination, setDestination] = useState<string>('');
    const [loading, setLoading] = useState(true);

    // Reset on open so values/errors never leak between variants.
    useEffect(() => {
        if (open) {
            setData({ value: '', notes: '' });
            setSource(HERE);
            setDestination('');
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, variant?.id]);

    // The roster, on open. A failure is not an error state: it leaves no branches
    // to send to, which is what the dialog then says.
    useEffect(() => {
        if (!open) {
            return;
        }

        let cancelled = false;

        setLoading(true);

        fetch(route('stock-updates.branches'), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => response.json())
            .then((payload: { branches?: Branch[] }) => {
                if (!cancelled) {
                    setBranches(payload.branches ?? []);
                }
            })
            .catch(() => undefined)
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [open]);

    const thisBranch = branches.find((branch) => branch.is_this_branch);
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

        form.post(route('stock-transfers.store'), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Transfer stock</DialogTitle>
                        <DialogDescription>
                            {variant ? (
                                <>
                                    {itemName ? `${itemName} — ` : ''}
                                    {variant.name || itemName}
                                </>
                            ) : null}
                        </DialogDescription>
                    </DialogHeader>

                    {nowhereToSend ? (
                        <p className="text-muted-foreground text-sm">
                            There is no other branch to send stock to. Either this business has one branch, or this device cannot reach the business
                            database right now — a transfer has to be recorded there.
                        </p>
                    ) : (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="transfer-source">From</Label>
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
                                <InputError message={errors.from_branch_id} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="transfer-destination">To</Label>
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
                                <InputError message={errors.to_branch_id} />
                            </div>

                            <p className="text-muted-foreground text-sm">
                                Nothing moves yet. The sending branch confirms what actually left, and that asks the receiving branch what actually
                                arrived — either can approve part of it.
                            </p>

                            <div className="grid gap-2">
                                <Label htmlFor="transfer-quantity">How many are moving</Label>
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
                                <InputError message={errors.quantity} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="transfer-notes">
                                    Notes <span className="text-muted-foreground">(optional)</span>
                                </Label>
                                <Textarea
                                    id="transfer-notes"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    rows={2}
                                    placeholder="e.g. Restocking the branch ahead of the weekend."
                                />
                                <InputError message={errors.notes} />
                            </div>
                        </>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            {nowhereToSend ? 'Close' : 'Cancel'}
                        </Button>
                        {!nowhereToSend && (
                            <Button disabled={processing || !canSubmit}>
                                {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                Request transfer
                            </Button>
                        )}
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
