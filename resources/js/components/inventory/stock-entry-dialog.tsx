import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { movementLabel, movementVariant } from '@/lib/stock-movement';
import type { MovementRequest, StockLedgerEntry } from '@/types';
import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';

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

const ROLE: Record<string, string> = {
    owner: 'Owner',
    admin: 'Admin',
    cashier: 'Cashier',
};

function formatWhen(value: string | null): string {
    if (value === null) {
        return '—';
    }

    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
}

function signed(quantity: number): string {
    return quantity > 0 ? `+${quantity}` : String(quantity);
}

/** "Name (Role)", for the two people a request involves. */
function actor(person: { name: string; role: string | null } | null): string {
    if (person === null) {
        return '—';
    }

    return person.role ? `${person.name} (${ROLE[person.role] ?? person.role})` : person.name;
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
                                <Badge variant={movementVariant(entry)}>{movementLabel(entry)}</Badge>
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

                        <RequestDetails entry={entry} />
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

/**
 * What was ASKED for behind this movement, and who answered (M11.8).
 *
 * The ledger row says what moved; only the queue row it came from says what was
 * requested, by whom, from which branch, and — for a refusal, whose ledger row
 * is a movement of zero — why nothing did. The two share an id, so this is a
 * lookup and not a join.
 *
 * Fetched when the dialog opens rather than carried in the page props: it lives
 * in Postgres, and Stock History is a Cashier-level screen that has to render
 * offline. A failed fetch is not an error state — the row's own notes already
 * carry the sentence version of all of this, which is why they are written that
 * way.
 */
function RequestDetails({ entry }: { entry: StockLedgerEntry }) {
    const [request, setRequest] = useState<MovementRequest | null>(null);

    useEffect(() => {
        // Sales, returns and defects never went through the queue.
        if (entry.type !== 'stock_adjustment') {
            return;
        }

        let cancelled = false;
        setRequest(null);

        fetch(route('stock-history.request', entry.id), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => response.json())
            .then((payload: { request?: MovementRequest | null }) => {
                if (!cancelled) {
                    setRequest(payload.request ?? null);
                }
            })
            .catch(() => undefined);

        return () => {
            cancelled = true;
        };
    }, [entry.id, entry.type]);

    if (request === null) {
        return null;
    }

    const partial = request.approved !== null && request.approved !== request.requested;

    return (
        <div className="space-y-3 border-t pt-3">
            <div className="flex flex-wrap items-center gap-2">
                <p className="text-muted-foreground text-xs">What was asked for</p>
                <Badge variant={request.status === 'rejected' ? 'destructive' : 'secondary'}>
                    {request.status === 'rejected' ? 'Turned down' : partial ? 'Partly approved' : 'Approved'}
                </Badge>
            </div>

            <dl className="grid grid-cols-2 gap-4">
                <Field label="Requested">
                    <span className="tabular-nums">{signed(request.requested)}</span>
                    {request.approved !== null && request.approved !== request.requested && (
                        <span className="text-muted-foreground"> · {signed(request.approved)} agreed</span>
                    )}
                </Field>
                <Field label="Asked by">
                    <span>{actor(request.issued_by)}</span>
                    {request.issued_by.branch && <p className="text-muted-foreground text-xs">{request.issued_by.branch.code}</p>}
                </Field>
                <Field label="Answered by">
                    <span>{actor(request.resolved_by)}</span>
                    <p className="text-muted-foreground text-xs">{formatWhen(request.resolved_at)}</p>
                </Field>
                <Field label="Stock changes at">{request.branch?.code ?? '—'}</Field>
            </dl>

            {request.resolution_reason && (
                <div className="space-y-0.5">
                    <p className="text-muted-foreground text-xs">Why</p>
                    <p className="text-sm whitespace-pre-wrap">{request.resolution_reason}</p>
                </div>
            )}

            {request.can_reissue && (
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => router.post(route('stock-updates.reissue', request.id), {}, { preserveScroll: true })}
                >
                    Ask again
                </Button>
            )}
        </div>
    );
}
