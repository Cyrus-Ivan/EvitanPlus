import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useCurrency } from '@/hooks/use-currency';
import type { EmployeeDay, EmployeeDaySale, EmployeeOption, EmployeeShift } from '@/types';
import { useEffect, useState } from 'react';

// Human labels per payment type, matching /sales.
const PAYMENT: Record<string, string> = {
    cash: 'Cash',
    cashless: 'Cashless',
    others: 'Others',
};

const ROLE: Record<string, string> = {
    owner: 'Owner',
    admin: 'Admin',
    cashier: 'Cashier',
};

function time(iso: string): string {
    const date = new Date(iso);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

/** "Monday, 3 August 2026", from the day's own `YYYY-MM-DD`. */
function fullDate(date: string): string {
    // Parsed as local midnight rather than through `new Date(date)`, which reads
    // a bare date as UTC and lands the label on the previous day west of it.
    const [year, month, day] = date.split('-').map(Number);
    const parsed = new Date(year, month - 1, day);

    return Number.isNaN(parsed.getTime()) ? date : parsed.toLocaleDateString(undefined, { dateStyle: 'full' });
}

function Figure({ label, value, hint }: { label: string; value: string; hint?: string }) {
    return (
        <div className="space-y-0.5">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-sm tabular-nums">{value}</dd>
            {hint && <dd className="text-muted-foreground text-xs">{hint}</dd>}
        </div>
    );
}

/**
 * One shift, in full: when they signed in, when they signed out, where, and for
 * how long.
 *
 * The calendar cell prints the same two times on one line because a cell is a
 * glance. Here they are labelled, because the question this dialog answers is
 * "what did this day actually look like" — and a day with three sign-ins is
 * three blocks, which is the shape of the answer.
 */
function ShiftBlock({ shift, index }: { shift: EmployeeShift; index: number }) {
    return (
        <div className="rounded-md border p-3">
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <span className="text-muted-foreground text-xs">Shift {index + 1}</span>
                {shift.branch ? (
                    <Badge variant="outline">{shift.branch}</Badge>
                ) : (
                    // Upstream every sign-in carries the branch it happened at, so
                    // this is a row written before that branch existed — not a
                    // reason to hide the shift.
                    <span className="text-muted-foreground text-xs">Branch not recorded</span>
                )}
            </div>

            <dl className="mt-2 grid grid-cols-3 gap-3">
                <Figure label="Time in" value={time(shift.start)} />
                <Figure label="Time out" value={shift.open ? '—' : time(shift.end!)} hint={shift.crosses_midnight ? 'Next day' : undefined} />
                <Figure label="Hours" value={shift.open ? '—' : `${shift.hours?.toFixed(2) ?? '—'}h`} />
            </dl>

            {/* An open shift says what is missing rather than guessing at it: the
                app records an unclean exit on the NEXT launch, so its timestamp
                says the shift ended and says nothing trustworthy about when. */}
            {shift.open && (
                <p className="text-muted-foreground mt-2 text-xs">
                    No sign-out was recorded, so this shift contributes no hours to the day or the month.
                </p>
            )}
        </div>
    );
}

/**
 * One day of one employee's month, in full.
 *
 * The calendar cell is a glance — shift lines, hours, a transaction count and a
 * net figure. This is the day behind it: every shift with its own times, branch
 * and hours; the whole sales breakdown rather than just the net; and the
 * individual receipts, which are the one thing a cell cannot hold and the only
 * way to tell one large sale from fourteen small ones.
 *
 * Read-only throughout. Every figure on it is recomputed from the append-only
 * source tables, and a sale another branch rang is not this device's to reprint
 * or return — which is why no row here links anywhere.
 */
export default function EmployeeDayDialog({
    day,
    employee,
    open,
    onOpenChange,
}: {
    day: EmployeeDay | null;
    employee: EmployeeOption;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            {/* Focus the panel rather than what is inside it: this is a read-only
                record with no controls, and Radix would otherwise draw a focus
                ring around the first thing it finds. Focus stays inside the
                dialog, so the trap, Escape and tabbing are unaffected. */}
            <DialogContent
                className="focus:outline-hidden sm:max-w-2xl"
                onOpenAutoFocus={(event) => {
                    event.preventDefault();
                    (event.currentTarget as HTMLElement | null)?.focus();
                }}
            >
                {day && <DayBody day={day} employee={employee} />}
            </DialogContent>
        </Dialog>
    );
}

/**
 * Split out so the sales lookup has a day to hang off — the hook cannot live in
 * the dialog, which renders with `day` null between openings.
 */
function DayBody({ day, employee }: { day: EmployeeDay; employee: EmployeeOption }) {
    const { format: money } = useCurrency();
    const { sales, failed } = useDaySales(employee.id, day);
    const branches = [...new Set(day.shifts.map((shift) => shift.branch).filter((branch): branch is string => branch !== null))];

    return (
        <>
            <DialogHeader>
                <DialogTitle>{fullDate(day.date)}</DialogTitle>
                <DialogDescription>
                    {employee.name} · {ROLE[employee.role] ?? employee.role}
                    {branches.length > 0 && ` · ${branches.join(', ')}`}
                </DialogDescription>
            </DialogHeader>

            <section className="space-y-2">
                <h3 className="text-sm font-medium">Attendance</h3>

                {day.shifts.length === 0 ? (
                    <p className="text-muted-foreground text-sm">No sign-in was recorded on this day.</p>
                ) : (
                    <>
                        <div className="space-y-2">
                            {day.shifts.map((shift, index) => (
                                <ShiftBlock key={index} shift={shift} index={index} />
                            ))}
                        </div>
                        <p className="text-muted-foreground text-sm">
                            {day.shifts.length} {day.shifts.length === 1 ? 'shift' : 'shifts'}
                            {day.hours !== null && ` · ${day.hours.toFixed(2)}h`}
                        </p>
                    </>
                )}

                {/* The seam the page states above the calendar, restated where it
                    bites: a shift is on the day it STARTED, a sale on the moment
                    it was rung, and the two do not agree across midnight. */}
                {day.shifts.some((shift) => shift.crosses_midnight) && (
                    <p className="text-muted-foreground text-xs">
                        A shift that ran past midnight is listed on the day it started; the sales it rang after midnight are on the next day.
                    </p>
                )}
            </section>

            <section className="space-y-2 border-t pt-4">
                <h3 className="text-sm font-medium">Sales</h3>

                <dl className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    <Figure label="Transactions" value={String(day.transactions)} />
                    <Figure label="Gross" value={money(day.gross_sales)} />
                    <Figure label="Returns" value={money(day.returns)} />
                    <Figure label="Defects" value={money(day.defects)} />
                    <Figure label="Net" value={money(day.net_sales)} />
                </dl>

                {/* Returns and defects reduce the day they HAPPENED on, so this
                    day's may well be against sales rung weeks ago — and are
                    therefore not a subtraction anybody can find in the list
                    below. Said once, here, rather than per row. */}
                {(Number(day.returns) > 0 || Number(day.defects) > 0) && (
                    <p className="text-muted-foreground text-xs">
                        Returns and defects count on the day they happened, which may not be the day of the sale they refund.
                    </p>
                )}

                <DaySales sales={sales} failed={failed} expected={day.transactions} />
            </section>
        </>
    );
}

/** The day's receipts, in the order they were rung. */
function DaySales({ sales, failed, expected }: { sales: EmployeeDaySale[] | null; failed: boolean; expected: number }) {
    const { format: money } = useCurrency();

    if (expected === 0) {
        return <p className="text-muted-foreground text-sm">No sales were rung on this day.</p>;
    }

    if (failed) {
        return <p className="text-muted-foreground text-sm">The individual sales could not be read from the business database.</p>;
    }

    if (sales === null) {
        return <p className="text-muted-foreground text-sm">Loading sales…</p>;
    }

    return (
        <div className="space-y-2">
            <ul className="divide-y rounded-md border">
                {sales.map((sale) => (
                    <li key={sale.id} className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 p-2 text-sm">
                        <span className="flex flex-wrap items-baseline gap-x-2">
                            <span className="text-muted-foreground tabular-nums">{time(sale.at)}</span>
                            <span className="font-medium tabular-nums">{sale.transaction_number}</span>
                            <span className="text-muted-foreground text-xs">
                                {PAYMENT[sale.payment_type] ?? sale.payment_type}
                                {sale.branch && ` · ${sale.branch}`}
                            </span>
                        </span>
                        <span className="flex items-baseline gap-2 tabular-nums">
                            {/* What has since been refunded against this sale.
                                A fact about the receipt, not about this day. */}
                            {Number(sale.returned) > 0 && <span className="text-muted-foreground text-xs">−{money(sale.returned)} returned</span>}
                            {Number(sale.defected) > 0 && <span className="text-muted-foreground text-xs">−{money(sale.defected)} defect</span>}
                            {money(sale.total)}
                        </span>
                    </li>
                ))}
            </ul>

            {/* The count on the calendar stays the true one; this says the list
                under it has been cut. */}
            {sales.length < expected && (
                <p className="text-muted-foreground text-xs">
                    Showing the first {sales.length} of {expected} sales.
                </p>
            )}
        </div>
    );
}

/**
 * The day's receipts, fetched when the dialog opens.
 *
 * A request of its own rather than a page prop: a month has thirty-one days and
 * nobody opens more than a few, so carrying every day's sales into every render
 * would multiply the payload for rows that are mostly never looked at. Skipped
 * entirely for a day that rang nothing — there is no list to ask for.
 *
 * A failed fetch is stated rather than swallowed. The screen is online-only, so
 * the figures above came from the same database moments ago; if the list cannot
 * follow them, an empty list would read as "sold nothing", which is the one
 * wrong answer available here.
 */
function useDaySales(employeeId: string, day: EmployeeDay): { sales: EmployeeDaySale[] | null; failed: boolean } {
    const [sales, setSales] = useState<EmployeeDaySale[] | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (day.transactions === 0) {
            setSales([]);
            setFailed(false);

            return;
        }

        let cancelled = false;
        setSales(null);
        setFailed(false);

        fetch(route('employee-performance.day', { user: employeeId, date: day.date }), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))))
            .then((payload: { sales?: EmployeeDaySale[] | null }) => {
                if (cancelled) {
                    return;
                }

                // Null is the server saying it could not reach the business
                // database — not an empty day.
                if (payload.sales == null) {
                    setFailed(true);

                    return;
                }

                setSales(payload.sales);
            })
            .catch(() => {
                if (!cancelled) {
                    setFailed(true);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [employeeId, day.date, day.transactions]);

    return { sales, failed };
}
