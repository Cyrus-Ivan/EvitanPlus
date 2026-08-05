import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { StockLedgerEntry } from '@/types';
import type { ReactNode } from 'react';

// Where a movement came from. `reference_type` says what kind of record
// `reference_id` points at, which is the closest thing the ledger has to "how
// this was asked for" (docs/glossary.md §4).
const REFERENCE_LABEL: Record<string, string> = {
    transaction: 'Sale at the till',
    manual_adjustment: 'Manual stock adjustment',
    csv_import: 'CSV import',
    return: 'Return or defect',
    transfer: 'Branch transfer',
};

const TYPE_LABEL: Record<string, string> = {
    sale: 'Sale',
    stock_adjustment: 'Adjustment',
};

const SALE_TYPE_LABEL: Record<string, string> = {
    sale: 'Sale',
    return: 'Return',
    defect: 'Defect',
};

function movementLabel(entry: StockLedgerEntry): string {
    if (entry.type === 'sale' && entry.sale_type) {
        return SALE_TYPE_LABEL[entry.sale_type] ?? entry.sale_type;
    }
    return TYPE_LABEL[entry.type] ?? entry.type;
}

function formatWhen(value: string): string {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="space-y-0.5">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-sm">{children}</dd>
        </div>
    );
}

/**
 * One ledger row, in full.
 *
 * The history table carries the five columns somebody scanning for a movement
 * needs — when, what, which way, how much, what was left. Everything else about a
 * row is worth having but not worth a column: who did it, what they wrote, how it
 * was asked for. Those are questions you ask about ONE row once you have found
 * it, so they live here rather than making every row three lines tall.
 *
 * Read-only by construction. The ledger is append-only and immutable on both
 * sides, enforced by a BEFORE UPDATE trigger; a correction is a new compensating
 * entry, never an edit to this one.
 */
export function StockEntryDialog({
    entry,
    open,
    onOpenChange,
}: {
    entry: StockLedgerEntry | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                {entry && (
                    <>
                        <DialogHeader>
                            <DialogTitle>
                                {entry.variant?.item ?? 'Unknown product'}
                                {entry.variant?.name ? <span className="text-muted-foreground font-normal"> · {entry.variant.name}</span> : null}
                            </DialogTitle>
                            <DialogDescription>{formatWhen(entry.created_at)}</DialogDescription>
                        </DialogHeader>

                        <dl className="grid grid-cols-2 gap-4">
                            <Field label="Movement">
                                <Badge variant={entry.type === 'stock_adjustment' ? 'secondary' : 'outline'}>{movementLabel(entry)}</Badge>
                            </Field>
                            <Field label="How it was asked for">{REFERENCE_LABEL[entry.reference_type] ?? entry.reference_type}</Field>
                            <Field label="Change">
                                <span
                                    className={`tabular-nums ${
                                        entry.quantity > 0 ? 'text-emerald-600 dark:text-emerald-400' : entry.quantity < 0 ? 'text-destructive' : ''
                                    }`}
                                >
                                    {entry.quantity > 0 ? `+${entry.quantity}` : entry.quantity}
                                </span>
                            </Field>
                            <Field label="On-hand after">
                                <span className="tabular-nums">{entry.resulting_snapshot}</span>
                            </Field>
                            <Field label="By">{entry.performed_by ?? '—'}</Field>
                            {/* Only when another branch was involved: who asked
                                for this, or the far end of a transfer. Read from
                                the row itself, so it renders offline. */}
                            {entry.counterparty_branch_code && (
                                <Field label={entry.quantity < 0 ? 'Sent to' : 'Came from'}>{entry.counterparty_branch_code}</Field>
                            )}
                        </dl>

                        <div className="space-y-0.5">
                            <p className="text-muted-foreground text-xs">Notes</p>
                            <p className="text-sm whitespace-pre-wrap">{entry.notes ?? '—'}</p>
                        </div>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
