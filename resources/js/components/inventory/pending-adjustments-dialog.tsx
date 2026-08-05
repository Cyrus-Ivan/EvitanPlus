import { useConfirm } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { OfflineNotice } from '@/components/offline-notice';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import type { PendingAdjustments, QueuedAdjustment } from '@/types';
import { router, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { Fragment, useState } from 'react';

const STATUS: Record<QueuedAdjustment['status'], { label: string; variant: 'secondary' | 'outline' | 'destructive'; hint: string }> = {
    pending: { label: 'Pending', variant: 'secondary', hint: 'Waiting for that branch to answer.' },
    accepted: { label: 'Applied', variant: 'outline', hint: 'That branch confirmed it and the stock moved.' },
    rejected: { label: 'Turned down', variant: 'destructive', hint: 'That branch says it did not happen.' },
};

const SOURCE: Record<string, string> = {
    manual_adjustment: 'Manual',
    csv_import: 'CSV import',
    transfer: 'Transfer',
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

/** "Applied" is a lie when only part of it was. */
function statusLabel(row: QueuedAdjustment): string {
    if (row.status === 'accepted' && row.approved_quantity !== null && row.approved_quantity !== row.quantity) {
        return `Partly applied (${signed(row.approved_quantity)})`;
    }

    return STATUS[row.status].label;
}

/**
 * Pending stock adjustments, as a dialog on Stock History.
 *
 * This was `/inventory/stock-updates` until the two screens turned out to be the
 * same screen: a queue row is a stock movement that has not happened yet, and a
 * ledger row is one that has, so they belong in one place with the pending ones
 * on top of the history they are about to join.
 *
 * ## Why there are two sections
 *
 * A queue row means opposite things depending on which end you are standing at,
 * and the old page only showed one of them:
 *
 * - **Awaiting this branch** — somebody asked for stock here to move. Nothing has
 *   happened yet; a person at this branch has to say whether it did. Every role
 *   can, deliberately: the person who knows whether the delivery arrived is
 *   whoever is in the shop.
 * - **Sent to other branches** — what has been asked of everyone else, and what
 *   came of it. This is the only place a turned-down adjustment is ever visible:
 *   a rejection writes no ledger row, and could not travel to the branch that
 *   issued it if it did, because the pull filters the ledger to one branch.
 *
 * Answered rows leave the first section — their movement is in the history behind
 * this dialog — and the toggle brings them back when somebody asks what happened.
 *
 * Everything here is read live from the business database and none of it is
 * stored on this device, so with no connection there is genuinely nothing to
 * show. That is the same precondition as issuing one in the first place.
 */
export function PendingAdjustmentsDialog({
    open,
    onOpenChange,
    data,
    loading,
    resolved,
    onResolvedChange,
    onReload,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    data: PendingAdjustments | null;
    loading: boolean;
    resolved: boolean;
    onResolvedChange: (resolved: boolean) => void;
    onReload: () => void;
}) {
    const confirm = useConfirm();
    const [approvingAll, setApprovingAll] = useState(false);

    const inbox = data?.inbox ?? [];
    const elsewhere = data?.elsewhere ?? [];
    const offline = data !== null && !data.online;
    const actionable = !resolved && inbox.length > 0;

    const approveAll = async () => {
        const agreed = await confirm({
            title: `Approve all ${inbox.length} pending adjustment(s)?`,
            description:
                'This moves the stock for every one of them at once, and each becomes a permanent ledger entry. ' +
                'Anything that would take a product below zero stops the whole batch, so nothing is half-applied.',
            confirmLabel: 'Approve all',
        });

        if (!agreed) {
            return;
        }

        setApprovingAll(true);
        router.post(
            route('stock-updates.resolve-all'),
            {},
            {
                preserveScroll: true,
                onSuccess: onReload,
                onFinish: () => setApprovingAll(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-5xl">
                <DialogHeader>
                    <DialogTitle>Pending stock adjustments</DialogTitle>
                    <DialogDescription>
                        Stock changes that have been asked for but have not moved yet. Nothing here is on the ledger until somebody confirms it
                        happened.
                    </DialogDescription>
                </DialogHeader>

                {offline && <OfflineNotice what="Pending stock adjustments" />}

                {loading && (
                    <p className="text-muted-foreground flex items-center gap-2 py-6 text-sm">
                        <LoaderCircle className="size-4 animate-spin" />
                        Loading…
                    </p>
                )}

                {!loading && !offline && (
                    <div className="space-y-6">
                        <section className="space-y-2">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h3 className="text-sm font-semibold">{resolved ? 'Answered by this branch' : 'Awaiting this branch'}</h3>
                                <div className="flex items-center gap-2">
                                    {actionable && (
                                        <Button size="sm" onClick={approveAll} disabled={approvingAll}>
                                            {approvingAll && <LoaderCircle className="mr-2 size-4 animate-spin" />}
                                            Approve all ({inbox.length})
                                        </Button>
                                    )}
                                    <Button variant="ghost" size="sm" onClick={() => onResolvedChange(!resolved)}>
                                        {resolved ? 'Show pending' : 'Show answered'}
                                    </Button>
                                </div>
                            </div>
                            {inbox.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    {resolved ? 'Nothing has been answered here yet.' : 'Nothing is waiting on this branch.'}
                                </p>
                            ) : (
                                <AdjustmentTable rows={inbox} showBranch={false} answerable={actionable} onReload={onReload} />
                            )}
                        </section>

                        <section className="space-y-2">
                            <h3 className="text-sm font-semibold">Sent to other branches</h3>
                            {elsewhere.length === 0 ? (
                                <p className="text-muted-foreground text-sm">Nothing has been sent to another branch.</p>
                            ) : (
                                <AdjustmentTable rows={elsewhere} showBranch answerable={false} onReload={onReload} />
                            )}
                        </section>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

function AdjustmentTable({
    rows,
    showBranch,
    answerable,
    onReload,
}: {
    rows: QueuedAdjustment[];
    showBranch: boolean;
    answerable: boolean;
    onReload: () => void;
}) {
    const [answering, setAnswering] = useState<string | null>(null);
    const columnCount = (showBranch ? 1 : 0) + 7;

    return (
        <div className="overflow-x-auto rounded-md border">
            <Table>
                <TableHeader>
                    <TableRow>
                        {showBranch && <TableHead>Branch</TableHead>}
                        <TableHead>Product</TableHead>
                        <TableHead className="text-right">Change</TableHead>
                        <TableHead>Source</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Asked by</TableHead>
                        <TableHead>Asked</TableHead>
                        <TableHead />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.map((row) => (
                        <Fragment key={row.id}>
                            <TableRow>
                                {showBranch && <TableCell className="font-medium">{row.branch}</TableCell>}
                                {/* `whitespace-normal`, because TableCell is nowrap by default
                                    and a resolution reason is a sentence. Without it the table
                                    grows past the viewport and pushes the actions out of reach. */}
                                <TableCell className="max-w-72 whitespace-normal">
                                    {row.item}
                                    {row.variant ? <span className="text-muted-foreground"> · {row.variant}</span> : null}
                                    {row.notes ? <p className="text-muted-foreground text-xs">{row.notes}</p> : null}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">{signed(row.quantity)}</TableCell>
                                <TableCell className="text-muted-foreground text-xs">
                                    {SOURCE[row.reference_type] ?? row.reference_type}
                                    {row.counterparty_branch ? <p>{row.counterparty_branch}</p> : null}
                                </TableCell>
                                <TableCell className="max-w-xs whitespace-normal">
                                    <Badge variant={STATUS[row.status].variant}>{statusLabel(row)}</Badge>
                                    <p className="text-muted-foreground mt-1 text-xs">{row.resolution_reason ?? STATUS[row.status].hint}</p>
                                </TableCell>
                                <TableCell>{row.issued_by}</TableCell>
                                <TableCell className="text-muted-foreground text-xs">{formatWhen(row.created_at)}</TableCell>
                                <TableCell className="text-right whitespace-nowrap">
                                    {answerable && row.status === 'pending' && (
                                        <AnswerButtons
                                            row={row}
                                            expanded={answering === row.id}
                                            onExpand={() => setAnswering(answering === row.id ? null : row.id)}
                                            onReload={onReload}
                                        />
                                    )}
                                    {row.status === 'rejected' && !answerable && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                router.post(route('stock-updates.reissue', row.id), {}, { preserveScroll: true, onSuccess: onReload })
                                            }
                                        >
                                            Ask again
                                        </Button>
                                    )}
                                </TableCell>
                            </TableRow>
                            {answering === row.id && (
                                <TableRow>
                                    <TableCell colSpan={columnCount} className="bg-muted/30">
                                        <AnswerForm
                                            row={row}
                                            onDone={() => {
                                                setAnswering(null);
                                                onReload();
                                            }}
                                        />
                                    </TableCell>
                                </TableRow>
                            )}
                        </Fragment>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

/** Approve outright, or open the form for the two answers that need a reason. */
function AnswerButtons({
    row,
    expanded,
    onExpand,
    onReload,
}: {
    row: QueuedAdjustment;
    expanded: boolean;
    onExpand: () => void;
    onReload: () => void;
}) {
    const form = useForm({});

    const approve = () => {
        form.transform(() => ({ adjustments: [{ id: row.id, outcome: 'approve' }] }));
        form.post(route('stock-updates.resolve'), { preserveScroll: true, onSuccess: onReload });
    };

    return (
        <div className="flex justify-end gap-2">
            <Button size="sm" onClick={approve} disabled={form.processing}>
                Approve
            </Button>
            <Button variant="outline" size="sm" onClick={onExpand}>
                {expanded ? 'Cancel' : 'Something else'}
            </Button>
        </div>
    );
}

/**
 * The two answers that contradict what somebody asked for, and so need saying.
 *
 * A partial approval is the common case and the reason this form exists: three
 * were sent, two arrived intact, and the third is written off in transit. The
 * business is genuinely one unit down and the reason is the only record of why.
 */
function AnswerForm({ row, onDone }: { row: QueuedAdjustment; onDone: () => void }) {
    const [outcome, setOutcome] = useState<'partial' | 'reject'>('partial');
    const form = useForm<{ quantity: string; reason: string }>({
        // Defaults to one short of what was asked for, in the right direction,
        // because that is overwhelmingly the case somebody is here to record.
        quantity: String(row.quantity > 0 ? row.quantity - 1 : row.quantity + 1),
        reason: '',
    });

    const { data, setData, errors, processing } = form;
    const error = errors.quantity ?? errors.reason ?? Object.entries(errors).find(([key]) => key.startsWith('adjustments'))?.[1];

    const submit = () => {
        form.transform((current) => ({
            adjustments: [
                {
                    id: row.id,
                    outcome,
                    quantity: outcome === 'partial' ? Number(current.quantity) : null,
                    reason: current.reason,
                },
            ],
        }));

        form.post(route('stock-updates.resolve'), { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <div className="space-y-3 p-2">
            <div className="flex flex-wrap items-end gap-3">
                <div className="space-y-1">
                    <Label>Answer</Label>
                    <div className="flex gap-2">
                        <Button variant={outcome === 'partial' ? 'default' : 'outline'} size="sm" onClick={() => setOutcome('partial')}>
                            Only some arrived
                        </Button>
                        <Button variant={outcome === 'reject' ? 'destructive' : 'outline'} size="sm" onClick={() => setOutcome('reject')}>
                            None of it did
                        </Button>
                    </div>
                </div>

                {outcome === 'partial' && (
                    <div className="space-y-1">
                        <Label htmlFor={`quantity-${row.id}`}>How much actually moved</Label>
                        <Input
                            id={`quantity-${row.id}`}
                            type="number"
                            className="w-40"
                            value={data.quantity}
                            onChange={(e) => setData('quantity', e.target.value)}
                        />
                        <p className="text-muted-foreground text-xs">{signed(row.quantity)} was asked for.</p>
                    </div>
                )}
            </div>

            <div className="space-y-1">
                <Label htmlFor={`reason-${row.id}`}>Why</Label>
                <Textarea
                    id={`reason-${row.id}`}
                    value={data.reason}
                    onChange={(e) => setData('reason', e.target.value)}
                    placeholder={
                        outcome === 'partial'
                            ? 'e.g. 2 of the 3 arrived intact, the third was crushed in transit.'
                            : 'e.g. no delivery came to this branch.'
                    }
                    rows={2}
                />
                <InputError message={error} />
                <p className="text-muted-foreground text-xs">
                    The branch that asked for this reads your answer here — it is the only record of what happened.
                </p>
            </div>

            <Button size="sm" onClick={submit} disabled={processing}>
                {processing && <LoaderCircle className="mr-2 size-4 animate-spin" />}
                Save answer
            </Button>
        </div>
    );
}
