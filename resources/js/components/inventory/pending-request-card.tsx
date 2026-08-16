import InputError from '@/components/forms/input-error';
import SubmitButton from '@/components/forms/submit-button';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatWhen } from '@/lib/format';
import { requestStatusVariant } from '@/lib/request-status';
import { roleLabel } from '@/lib/roles';
import { cn } from '@/lib/utils';
import type { BranchRef, PendingRequest, RequestActor, RequestLeg } from '@/types';
import { useForm } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useState } from 'react';

const SOURCE: Record<string, string> = {
    manual_adjustment: 'Manual',
    csv_import: 'CSV import',
    transfer: 'Transfer',
};

function signed(quantity: number): string {
    return quantity > 0 ? `+${quantity}` : String(quantity);
}

/** "CODE — Name", or a dash where a branch could not be named. */
function BranchName({ branch }: { branch: BranchRef | null }) {
    if (branch === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <span>
            <span className="font-medium">{branch.code}</span>
            <span className="text-muted-foreground"> — {branch.name}</span>
        </span>
    );
}

/** A person and the role they hold, which is half of why a request is trusted. */
function Actor({ actor }: { actor: RequestActor }) {
    return (
        <span className="flex flex-wrap items-center gap-1.5">
            {actor.name}
            {actor.role && (
                <Badge variant="outline" className="font-normal">
                    {roleLabel(actor.role)}
                </Badge>
            )}
        </span>
    );
}

/** A labelled block, so every card reads down the same columns. */
function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="space-y-1">
            <p className="text-muted-foreground text-xs">{label}</p>
            <div className="text-sm">{children}</div>
        </div>
    );
}

/**
 * How far along a transfer is: asked for, sent, arrived.
 *
 * Three steps and not two, because the middle one is a claim somebody makes
 * rather than a consequence of the first — the request is that stock SHOULD
 * move, "Sent" is a person at the sending branch saying it left, and "Received"
 * is a different person at the other end saying it arrived.
 *
 * A refused step is drawn destructive and stops the line: nothing downstream of
 * it will ever happen.
 */
function TransferSteps({ send, receive }: { send: RequestLeg | undefined; receive: RequestLeg | undefined }) {
    const state = (leg: RequestLeg | undefined): 'done' | 'refused' | 'current' | 'waiting' => {
        if (leg === undefined) {
            // No receiving leg exists until the sender confirms. A refusal never
            // reaches here: it spawns no receiving leg AND leaves nothing
            // pending, so the whole request has already left the page.
            return 'waiting';
        }
        if (leg.status === 'rejected') {
            return 'refused';
        }
        if (leg.status === 'accepted') {
            return 'done';
        }

        return 'current';
    };

    const steps: Array<{ label: string; state: 'done' | 'refused' | 'current' | 'waiting' }> = [
        { label: 'Requested', state: 'done' },
        { label: 'Sent', state: state(send) },
        { label: 'Received', state: state(receive) },
    ];

    return (
        <ol className="flex flex-wrap items-center gap-x-2 gap-y-1" aria-label="Transfer progress">
            {steps.map((step, index) => (
                <li key={step.label} className="flex items-center gap-2">
                    {index > 0 && <span className="text-muted-foreground/50 text-xs">→</span>}
                    <span
                        className={cn(
                            'flex size-5 items-center justify-center rounded-full text-[10px] font-medium',
                            step.state === 'done' && 'bg-primary/20 text-primary',
                            step.state === 'refused' && 'bg-destructive text-white',
                            step.state === 'current' && 'bg-primary text-primary-foreground',
                            step.state === 'waiting' && 'bg-muted text-muted-foreground',
                        )}
                        aria-current={step.state === 'current' ? 'step' : undefined}
                    >
                        {/* A cross rather than a number: the step happened and the
                            answer was no, which a greyed-out number cannot say. */}
                        {step.state === 'refused' ? '✕' : index + 1}
                    </span>
                    <span className={cn('text-xs', step.state === 'waiting' ? 'text-muted-foreground' : 'text-foreground')}>{step.label}</span>
                </li>
            ))}
        </ol>
    );
}

/** What a branch answered, once it has. */
function Answer({ leg }: { leg: RequestLeg }) {
    if (leg.status === 'pending') {
        return <span className="text-muted-foreground text-sm">Waiting on this branch.</span>;
    }

    const partial = leg.status === 'accepted' && leg.approved_quantity !== null && leg.approved_quantity !== leg.quantity;

    return (
        <div className="space-y-1">
            <div className="flex flex-wrap items-center gap-2">
                <Badge variant={requestStatusVariant(leg.status)}>
                    {leg.status === 'rejected'
                        ? 'Turned down'
                        : partial
                          ? `Partly approved (${signed(leg.approved_quantity as number)} of ${signed(leg.quantity)})`
                          : `Approved (${signed(leg.quantity)})`}
                </Badge>
                {leg.resolved_by && <Actor actor={leg.resolved_by} />}
                <span className="text-muted-foreground text-xs">{formatWhen(leg.resolved_at)}</span>
            </div>
            {leg.resolution_reason && <p className="text-muted-foreground text-sm">{leg.resolution_reason}</p>}
        </div>
    );
}

/** One product's worth of a request: its legs, paired. */
interface Line {
    id: string;
    item: string;
    variant: string | null;
    requested: number;
    send: RequestLeg | undefined;
    receive: RequestLeg | undefined;
    /** The leg THIS device owes an answer on, if any. Never more than one. */
    answerable: RequestLeg | undefined;
    /** An adjustment's single leg, or a transfer's sending leg. */
    first: RequestLeg;
}

/**
 * Pair a request's legs up by the product they are about.
 *
 * One line for an adjustment, one for each product in a batch, and within a line
 * a transfer's send and receive. The pairing key comes off the wire (`leg.line`)
 * rather than being inferred from the order — a batch interleaves nothing, but a
 * card that quietly mispairs two products' legs would show the wrong branch's
 * answer against the wrong shelf.
 */
function toLines(legs: RequestLeg[]): Line[] {
    const byLine = new Map<string, RequestLeg[]>();

    for (const leg of legs) {
        byLine.set(leg.line, [...(byLine.get(leg.line) ?? []), leg]);
    }

    return [...byLine.entries()].map(([id, group]) => {
        const send = group.find((leg) => leg.role === 'send');
        const receive = group.find((leg) => leg.role === 'receive');
        const first = send ?? group[0];

        return {
            id,
            item: first.item,
            variant: first.variant,
            requested: first.requested,
            send,
            receive,
            answerable: group.find((leg) => leg.actionable),
            first,
        };
    });
}

/**
 * One pending stock request, and every answer it is still waiting on.
 *
 * Read top to bottom it is the question a person standing in the shop has to
 * answer, in the order they need it: what kind of movement this is, who asked
 * for it and from where, which branches it moves stock between, what the other
 * branch has already said about it, and only then the products, the numbers, and
 * the buttons.
 *
 * ## A card is an ACT, not a row (M13)
 *
 * A stock batch is many products asked for at once, and this shows it as one
 * card with one note and one branch pair, listing its products underneath. Twelve
 * cards repeating one note is the page failing to say that one delivery left one
 * shop. A request issued on its own is the same card with a single line, which is
 * why there is no separate layout for it.
 */
export function PendingRequestCard({ request }: { request: PendingRequest }) {
    const transfer = request.kind === 'transfer';
    const lines = toLines(request.legs);
    const single = lines.length === 1;
    // The sending leg whose answer the receiving branch is reading. Only
    // meaningful on a single-line card; a batch shows each line's own.
    const send = single ? lines[0].send : undefined;
    const answerable = request.legs.filter((leg) => leg.actionable);

    return (
        <Card>
            <CardContent className="p-4">
                {/* Two columns wide enough to hold them: the context on the left,
                    the thing being answered on the right. Below lg it is one
                    stack and the divider goes back to being a top border. */}
                <div className="flex flex-col gap-4 lg:flex-row lg:gap-6">
                    <div className="min-w-0 flex-1 space-y-4">
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge variant={transfer ? 'default' : 'secondary'}>{transfer ? 'Transfer' : 'Adjustment'}</Badge>
                            {/* The batch's own name, so the person answering this
                                card and the person who issued it are talking
                                about the same delivery. Null on a batch issued
                                before schema 24, which reads as it always did. */}
                            {request.batch && (
                                <Badge variant="outline" className="font-normal">
                                    {request.batch.reference ? <span className="font-medium tabular-nums">{request.batch.reference}</span> : 'Batch'}
                                    <span className="text-muted-foreground">
                                        {' · '}
                                        {request.batch.size} {request.batch.size === 1 ? 'product' : 'products'}
                                    </span>
                                </Badge>
                            )}
                            <span className="text-muted-foreground text-xs">{SOURCE[request.source] ?? request.source}</span>
                        </div>

                        {/* Capped: past this the two fields drift so far apart on a
                            wide monitor that they stop reading as one block. */}
                        <div className="grid max-w-2xl gap-4 sm:grid-cols-2">
                            <Field label="Requested by">
                                <div className="space-y-0.5">
                                    <BranchName branch={request.issued_by.branch} />
                                    <Actor actor={request.issued_by} />
                                    <p className="text-muted-foreground text-xs">{formatWhen(request.issued_by.at)}</p>
                                </div>
                            </Field>

                            {transfer ? (
                                <Field label="Moving between">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <BranchName branch={request.sender} />
                                        <ArrowRight className="text-muted-foreground size-4 shrink-0" aria-label="to" />
                                        <BranchName branch={request.receiver} />
                                    </div>
                                </Field>
                            ) : (
                                <Field label="Stock changes at">
                                    <BranchName branch={request.target} />
                                </Field>
                            )}
                        </div>

                        {request.notes && <Field label="Note">{request.notes}</Field>}

                        {/* A transfer's receiving branch is answering for what the SENDER
                            said actually left, so what the sender said has to be on the
                            card. Only shown once there is an answer to show, and only on
                            a single-line card — a batch shows it per product. */}
                        {transfer && send && send.status !== 'pending' && (
                            <Field label={`Sent by ${send.branch?.code ?? 'the sending branch'}`}>
                                <Answer leg={send} />
                            </Field>
                        )}
                    </div>

                    {/* An equal share of the width, so the divider lands down the
                        middle of every card in the list however much context the
                        left side happens to carry. */}
                    <div className="flex w-full min-w-0 flex-1 flex-col gap-3 border-t pt-3 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-6">
                        {!single && answerable.length > 1 && <ApproveAll legs={answerable} />}

                        <div className={single ? undefined : 'divide-y'}>
                            {lines.map((line) => (
                                <LineBlock key={line.id} line={line} transfer={transfer} single={single} />
                            ))}
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

/**
 * One product on a card: what was asked, how far along it is, and the buttons.
 *
 * A single-line card gets the roomier layout the page has always had; a batch's
 * lines are the same information stacked tighter, because the context they share
 * is already stated once on the left.
 */
function LineBlock({ line, transfer, single }: { line: Line; transfer: boolean; single: boolean }) {
    const { send, receive, answerable } = line;

    return (
        <div className={single ? 'flex flex-1 flex-col gap-3' : 'space-y-2 py-3 first:pt-0 last:pb-0'}>
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className={single ? 'font-medium' : 'text-sm font-medium'}>
                    {line.item}
                    {line.variant ? <span className="text-muted-foreground"> · {line.variant}</span> : null}
                </p>
                <p className={single ? 'text-lg font-semibold tabular-nums' : 'font-semibold tabular-nums'}>
                    {signed(answerable?.quantity ?? line.requested)}
                </p>
            </div>

            {transfer && <TransferSteps send={send} receive={receive} />}

            {/* On a batch the sender's answer belongs beside the product it is
                about, because the quantities differ line by line. */}
            {!single && transfer && send && send.status !== 'pending' && <Answer leg={send} />}

            {/* Pushed to the foot of the column on a single-line card: the
                buttons are the last thing in reading order, and on a tall card
                they stay put rather than floating up against the product line. */}
            <div className={single ? 'mt-auto' : undefined}>
                {answerable ? (
                    <AnswerControls leg={answerable} />
                ) : (
                    <p className="text-muted-foreground text-sm">
                        {transfer && send?.status === 'pending'
                            ? `Waiting on ${send.branch?.code ?? 'the sending branch'} to confirm it left.`
                            : `Waiting on ${(receive ?? line.first)?.branch?.code ?? 'another branch'} to confirm.`}
                    </p>
                )}
            </div>
        </div>
    );
}

/**
 * Approve every line on this card that this branch owes an answer on.
 *
 * Posts them as an ordinary batch of decisions to the same route one Approve
 * uses — the resolver re-reads each id from Postgres filtered to this branch
 * before writing anything, so there is nothing a bulk path could usefully skip.
 * It says how many rather than "all", because "all" on a filtered page has meant
 * two different things before.
 */
function ApproveAll({ legs }: { legs: RequestLeg[] }) {
    const form = useForm({});

    const approve = () => {
        form.transform(() => ({ adjustments: legs.map((leg) => ({ id: leg.id, outcome: 'approve' })) }));
        form.post(route('stock-updates.resolve'), { preserveScroll: true });
    };

    return (
        <div className="flex justify-end">
            <SubmitButton type="button" size="sm" onClick={approve} processing={form.processing}>
                Approve all {legs.length}
            </SubmitButton>
        </div>
    );
}

/**
 * Approve outright, or open the form for the two answers that need a reason.
 *
 * Neither path reloads anything by hand: every write here redirects `back()`, so
 * the page re-renders from the queue as it now stands — and a fully answered
 * request simply is not in the next render.
 */
function AnswerControls({ leg }: { leg: RequestLeg }) {
    const [expanded, setExpanded] = useState(false);
    const form = useForm({});

    const approve = () => {
        form.transform(() => ({ adjustments: [{ id: leg.id, outcome: 'approve' }] }));
        form.post(route('stock-updates.resolve'), { preserveScroll: true });
    };

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap justify-end gap-2">
                <Button size="sm" onClick={approve} disabled={form.processing}>
                    Approve
                </Button>
                <Button variant="outline" size="sm" onClick={() => setExpanded(!expanded)}>
                    {expanded ? 'Cancel' : 'Something else'}
                </Button>
            </div>

            {expanded && <AnswerForm leg={leg} onDone={() => setExpanded(false)} />}
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
function AnswerForm({ leg, onDone }: { leg: RequestLeg; onDone: () => void }) {
    const [outcome, setOutcome] = useState<'partial' | 'reject'>('partial');
    const form = useForm<{ quantity: string; reason: string }>({
        // Defaults to one short of what was asked for, in the right direction,
        // because that is overwhelmingly the case somebody is here to record.
        quantity: String(leg.quantity > 0 ? leg.quantity - 1 : leg.quantity + 1),
        reason: '',
    });

    const { data, setData, errors, processing } = form;
    const error = errors.quantity ?? errors.reason ?? Object.entries(errors).find(([key]) => key.startsWith('adjustments'))?.[1];

    const submit = () => {
        form.transform((current) => ({
            adjustments: [
                {
                    id: leg.id,
                    outcome,
                    quantity: outcome === 'partial' ? Number(current.quantity) : null,
                    reason: current.reason,
                },
            ],
        }));

        form.post(route('stock-updates.resolve'), { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <div className="bg-muted/30 space-y-3 rounded-md p-3">
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
                        <Label htmlFor={`quantity-${leg.id}`}>How much actually moved</Label>
                        <Input
                            id={`quantity-${leg.id}`}
                            type="number"
                            className="w-40"
                            value={data.quantity}
                            onChange={(e) => setData('quantity', e.target.value)}
                        />
                        <p className="text-muted-foreground text-xs">{signed(leg.quantity)} was asked for.</p>
                    </div>
                )}
            </div>

            <div className="space-y-1">
                <Label htmlFor={`reason-${leg.id}`}>Why</Label>
                <Textarea
                    id={`reason-${leg.id}`}
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
                    The branch that asked for this reads your answer in their Stock History — it is the only record of what happened.
                </p>
            </div>

            <div className="flex justify-end">
                <SubmitButton type="button" size="sm" onClick={submit} processing={processing}>
                    Save answer
                </SubmitButton>
            </div>
        </div>
    );
}
