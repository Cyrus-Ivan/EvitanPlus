import { DetailDialog } from '@/components/forms/form-dialog';
import { Badge } from '@/components/ui/badge';
import { DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { formatWhen } from '@/lib/format';
import { roleLabel } from '@/lib/roles';
import { movementLabel, movementVariant } from '@/lib/stock-movement';
import { cn } from '@/lib/utils';
import type { BranchRef, MovementRequest, RequestActor, StockLedgerEntry } from '@/types';
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

function signed(quantity: number): string {
    return quantity > 0 ? `+${quantity}` : String(quantity);
}

/** "Name (Role)", for the two people a request involves. */
function actor(person: { name: string; role: string | null } | null): string {
    if (person === null) {
        return '—';
    }

    return person.role ? `${person.name} (${roleLabel(person.role)})` : person.name;
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
    onFilterBatch,
}: {
    entry: StockLedgerEntry | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    // Show the rest of the batch this movement was one line of. The page owns
    // it, because filtering the history is the page's job and this dialog knows
    // nothing about the other filters already set.
    onFilterBatch: (reference: string) => void;
}) {
    return (
        // The header is inside EntryBody, not passed as a prop: `entry` is null
        // while the dialog animates out, and the title is made of it. The panel
        // takes the opening focus rather than the one control in here (the batch
        // link) — see DetailDialog.
        <DetailDialog open={open} onOpenChange={onOpenChange}>
            {entry && <EntryBody entry={entry} onFilterBatch={onFilterBatch} />}
        </DetailDialog>
    );
}

/**
 * Split out so the request lookup has an entry to hang off.
 *
 * The hook cannot live in {@see StockEntryDialog}, which renders with `entry`
 * null between openings.
 */
function EntryBody({ entry, onFilterBatch }: { entry: StockLedgerEntry; onFilterBatch: (reference: string) => void }) {
    const request = useMovementRequest(entry);
    // Bound once so the click handler below keeps the narrowing.
    const batch = entry.batch_reference;

    return (
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
                {/* The one act this movement was a line of, by name. Read off the
                    ROW rather than the request below, so it survives with no
                    connection — and clickable, because a batch name is only worth
                    printing if it can answer "what else moved with this?". */}
                {batch && (
                    <Field label="Batch">
                        <button
                            type="button"
                            onClick={() => onFilterBatch(batch)}
                            className="text-primary ring-offset-background focus-visible:ring-ring rounded-sm tabular-nums underline decoration-dotted underline-offset-4 hover:decoration-solid focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden"
                        >
                            {batch}
                        </button>
                    </Field>
                )}
            </dl>

            {/* One or the other, never both. The blocks are a superset of the
                row's own notes — `AdjustmentResolver::notes()` composes that
                string out of exactly these pieces — so showing the two together
                prints every sentence twice. The notes are the fallback: offline,
                and on any movement that never had a request — a sale, return or
                defect, or an opening-stock import, which writes the ledger
                directly because there was no shelf history to confirm against. */}
            {request === null ? (
                <div className="space-y-0.5">
                    <p className="text-muted-foreground text-xs">Notes</p>
                    <p className="text-sm whitespace-pre-wrap">{entry.notes ?? '—'}</p>
                </div>
            ) : (
                <RequestStory request={request} />
            )}
        </>
    );
}

/**
 * What was ASKED for behind this movement, and who answered (M11.8).
 *
 * The ledger row says what moved; only the queue rows it came from say what was
 * requested, by whom, from which branch, and — for a refusal, whose ledger row is
 * a movement of zero — why nothing did. The entry and its request share an id, so
 * this is a lookup and not a join.
 *
 * Fetched when the dialog opens rather than carried in the page props: it lives
 * in Postgres, and Stock History is a Cashier-level screen that has to render
 * offline. A failed fetch is not an error state — the row's own notes carry the
 * sentence version of all of this, which is why they are written that way.
 */
function useMovementRequest(entry: StockLedgerEntry): MovementRequest | null {
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

    return request;
}

/**
 * One block per person who spoke, in the order they spoke.
 *
 * Up to three of them, and they are at up to three different branches: somebody
 * asks for stock to move, somebody at the sending branch confirms it left, and
 * somebody at the receiving branch confirms it arrived. Each block carries that
 * person's name, role, branch, figure and own words — which is the whole reason
 * this replaced a single run-together paragraph, because "one was damaged
 * loading" and "all present and correct" are two people disagreeing about the
 * same van and prose cannot say which is which.
 */
function RequestStory({ request }: { request: MovementRequest }) {
    const parent = request.parent;
    const transfer = request.kind === 'transfer';

    // A transfer's figures are magnitudes. The sending leg is negative and the
    // receiving leg positive, so "-3 requested" above "+2 received" reads as a
    // contradiction rather than as one van; the block labels carry the direction.
    // An adjustment has no direction but its sign, so it keeps it.
    const figure = (quantity: number): string => (transfer ? String(Math.abs(quantity)) : signed(quantity));

    const amount = (agreed: number | null, asked: number): string =>
        agreed === null || agreed === asked ? figure(asked) : `${figure(agreed)} of ${figure(asked)}`;

    return (
        <div className="space-y-3 border-t pt-3">
            {/* The original ask comes off the PARENT where there is one: a
                receiving leg's own issuer is the person who confirmed the stock
                left, not whoever asked for it, and it carries no branch. */}
            <Voice
                label="Requested"
                person={parent?.issued_by ?? request.issued_by}
                branch={(parent ?? request).issued_by.branch}
                at={(parent ?? request).issued_by.at}
                amount={figure((parent ?? request).requested)}
                words={(parent ?? request).notes}
            />

            {parent && (
                <Voice
                    label="Sent"
                    person={parent.resolved_by}
                    branch={parent.branch}
                    at={parent.resolved_at}
                    amount={amount(parent.approved, parent.requested)}
                    words={parent.resolution_reason}
                />
            )}

            <Voice
                label={transfer ? (parent ? 'Received' : 'Sent') : 'Answered'}
                person={request.resolved_by}
                branch={request.branch}
                at={request.resolved_at}
                amount={request.status === 'rejected' ? 'None of it' : amount(request.approved, request.requested)}
                refused={request.status === 'rejected'}
                words={request.resolution_reason}
                pending={request.status === 'pending'}
            />
        </div>
    );
}

/** One person, at one branch, and what they said about one figure. */
function Voice({
    label,
    person,
    branch,
    at,
    amount,
    words,
    refused = false,
    pending = false,
}: {
    label: string;
    person: RequestActor | null;
    branch: BranchRef | null;
    at: string | null;
    amount: string;
    words: string | null;
    refused?: boolean;
    pending?: boolean;
}) {
    return (
        <div className="space-y-1">
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <div className="flex flex-wrap items-baseline gap-x-2">
                    <span className="text-muted-foreground text-xs">{label}</span>
                    <span className="text-sm">{pending ? 'Not answered yet' : actor(person)}</span>
                    {branch && <span className="text-muted-foreground text-xs">· {branch.code}</span>}
                </div>
                <span className={cn('text-sm tabular-nums', refused && 'text-destructive')}>{amount}</span>
            </div>
            {words && <p className="text-muted-foreground text-sm whitespace-pre-wrap">{words}</p>}
            {!pending && at && <p className="text-muted-foreground text-xs">{formatWhen(at)}</p>}
        </div>
    );
}
