import InputError from '@/components/forms/input-error';
import VariantPicker from '@/components/inventory/variant-picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { BatchVariantOption } from '@/types';
import { Plus, X } from 'lucide-react';

/**
 * One row of a batch's products section.
 *
 * The index signature is Inertia's requirement, not ours: `useForm` only accepts
 * a shape it can serialise, and a nested array of plain interfaces does not
 * satisfy it. `stock` never leaves the browser — each dialog's `transform()`
 * strips it at submit, because what is on the shelf is something the server
 * reads for itself.
 */
export interface Line {
    [key: string]: string | number | null | undefined;
    variant_id: string;
    quantity: string;
    stock: number | null;
}

export const emptyLine = (): Line => ({ variant_id: '', quantity: '', stock: null });

/**
 * The products a batch covers — the half {@see BatchAdjustDialog} and
 * {@see BatchTransferDialog} have in common.
 *
 * What differs between the two is above this block, not in it: an adjustment
 * names one branch and a direction, a transfer names two branches and no
 * direction. A line is the same thing either way — a product and a number — so
 * it lives here once.
 *
 * `branch` is whose shelf the on-hand figures describe: the branch being
 * adjusted, or the branch a transfer is leaving. `errors` is the widened view of
 * the form's error bag, because the nested `lines.N.quantity` keys are not on
 * either dialog's typed shape.
 */
export function BatchLines({
    lines,
    branch,
    remote,
    quantityPlaceholder,
    errors,
    linesError,
    onChange,
}: {
    lines: Line[];
    branch: string;
    remote: boolean;
    quantityPlaceholder: string;
    errors: Record<string, string | undefined>;
    linesError?: string;
    onChange: (lines: Line[]) => void;
}) {
    const setLine = (index: number, patch: Partial<Line>) => onChange(lines.map((line, i) => (i === index ? { ...line, ...patch } : line)));

    const addLine = () => onChange([...lines, emptyLine()]);

    const removeLine = (index: number) => onChange(lines.filter((_, i) => i !== index));

    return (
        <div className="grid gap-2">
            <div className="flex items-baseline justify-between">
                <Label>Products</Label>
                {remote && <span className="text-muted-foreground text-xs">Stock shown is that branch's last synced figure.</span>}
            </div>

            <div className="space-y-2">
                {lines.map((line, index) => (
                    <div key={index} className="flex items-start gap-2">
                        <div className="min-w-0 flex-1">
                            <VariantPicker
                                value={line.variant_id}
                                branch={branch}
                                onChange={(variantId) => setLine(index, { variant_id: variantId })}
                                onOptionLoaded={(option: BatchVariantOption) => {
                                    if (option.id === line.variant_id && option.stock !== line.stock) {
                                        setLine(index, { stock: option.stock });
                                    }
                                }}
                            />
                            <InputError message={errors[`lines.${index}.variant_id`]} />
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
                                placeholder={quantityPlaceholder}
                                aria-label={`Quantity for row ${index + 1}`}
                            />
                            <InputError message={errors[`lines.${index}.quantity`]} />
                        </div>

                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="mt-0.5 shrink-0"
                            aria-label={`Remove row ${index + 1}`}
                            disabled={lines.length === 1}
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
            <InputError message={linesError} />
        </div>
    );
}

/**
 * The one note a whole batch carries.
 *
 * `required` is the caller's to decide: an adjustment that removes stock or
 * overwrites a count must say why, exactly as it must for one variant — a batch
 * does not make the reason less necessary — and a transfer never has to.
 */
export function BatchNotes({
    value,
    onChange,
    required,
    error,
}: {
    value: string;
    onChange: (value: string) => void;
    required: boolean;
    error?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor="batch-notes">
                Notes{' '}
                {required ? <span className="text-muted-foreground">(required)</span> : <span className="text-muted-foreground">(optional)</span>}
            </Label>
            <Textarea
                id="batch-notes"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                required={required}
                rows={2}
                placeholder="e.g. Weekly restock for the new shelf"
            />
            <p className="text-muted-foreground text-xs">This note is attached to every product in the batch.</p>
            <InputError message={error} />
        </div>
    );
}
