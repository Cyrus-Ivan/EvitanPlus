import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { BranchRef, PendingRequest, RequestActor, RequestLeg } from '@/types';
import { useForm } from '@inertiajs/react';
import { ArrowRight, LoaderCircle } from 'lucide-react';
import { useState } from 'react';

const SOURCE: Record<string, string> = {
    manual_adjustment: 'Manual',
    csv_import: 'CSV import',
    transfer: 'Transfer',
};

const ROLE: Record<string, string> = {
    owner: 'Owner',
    admin: 'Admin',
    cashier: 'Cashier',
};

function signed(quantity: number): string {
    return quantity > 0 ? `+${quantity}` : String(quantity);
}

function formatWhen(value: string | null): string {
    if (value === null) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
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
                    {ROLE[actor.role] ?? actor.role}
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
                <Badge variant={leg.status === 'rejected' ? 'destructive' : 'secondary'}>
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

/**
 * One pending stock movement, and every answer it is still waiting on.
 *
 * Read top to bottom it is the question a person standing in the shop has to
 * answer, in the order they need it: what kind of movement this is, who asked
 * for it and from where, which branches it moves stock between, what the other
 * branch has already said about it, and only then the product, the number, and
 * the buttons.
 */
export function PendingRequestCard({ request }: { request: PendingRequest }) {
    const transfer = request.kind === 'transfer';
    const send = request.legs.find((leg) => leg.role === 'send');
    const receive = request.legs.find((leg) => leg.role === 'receive');
    // An adjustment's single leg, or the transfer leg this device owes an answer
    // on. At most one leg of a card is ever actionable here: both ends of a
    // transfer are answered from different branches.
    const answerable = request.legs.find((leg) => leg.actionable);

    return (
        <Card>
            <CardContent className="space-y-4 p-4">
                <div className="flex flex-wrap items-center gap-2">
                    <Badge variant={transfer ? 'default' : 'secondary'}>{transfer ? 'Transfer' : 'Adjustment'}</Badge>
                    <span className="text-muted-foreground text-xs">{SOURCE[request.source] ?? request.source}</span>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
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

                {/* A transfer's receiving branch is answering for what the SENDER
                    said actually left, so what the sender said has to be on the
                    card. Only shown once there is an answer to show. */}
                {transfer && send && send.status !== 'pending' && (
                    <Field label={`Sent by ${send.branch?.code ?? 'the sending branch'}`}>
                        <Answer leg={send} />
                    </Field>
                )}

                <div className="space-y-3 border-t pt-3">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <div>
                            <p className="font-medium">
                                {request.item}
                                {request.variant ? <span className="text-muted-foreground"> · {request.variant}</span> : null}
                            </p>
                            {request.notes && <p className="text-muted-foreground text-sm">{request.notes}</p>}
                        </div>
                        <p className="text-lg font-semibold tabular-nums">{signed(answerable?.quantity ?? request.requested)}</p>
                    </div>

                    {transfer && <TransferSteps send={send} receive={receive} />}

                    {answerable ? (
                        <AnswerControls leg={answerable} />
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            {transfer && send?.status === 'pending'
                                ? `Waiting on ${send.branch?.code ?? 'the sending branch'} to confirm it left.`
                                : `Waiting on ${(receive ?? request.legs[0])?.branch?.code ?? 'another branch'} to confirm.`}
                        </p>
                    )}
                </div>
            </CardContent>
        </Card>
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
            <div className="flex flex-wrap gap-2">
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

            <Button size="sm" onClick={submit} disabled={processing}>
                {processing && <LoaderCircle className="mr-2 size-4 animate-spin" />}
                Save answer
            </Button>
        </div>
    );
}
