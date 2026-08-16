import EmployeeDayDialog from '@/components/reports/employee-day-dialog';
import { useCurrency } from '@/hooks/use-currency';
import { WEEKDAYS, weeksOf } from '@/lib/calendar';
import type { EmployeeDay, EmployeeOption, EmployeeShift } from '@/types';
import { useState } from 'react';

function time(iso: string): string {
    const date = new Date(iso);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

/**
 * One shift, as a line in a day cell.
 *
 * An open shift says what is missing rather than guessing at it: the app records
 * an unclean exit on the NEXT launch, so its timestamp says the shift ended and
 * says nothing trustworthy about when.
 */
function ShiftLine({ shift }: { shift: EmployeeShift }) {
    // Where they signed in. The report covers the whole business, so a month
    // split across branches has to say which day was which.
    const branch = shift.branch && <span className="text-muted-foreground"> · {shift.branch}</span>;

    if (shift.open) {
        return (
            <div className="text-muted-foreground italic">
                {time(shift.start)} → <span className="not-italic">no sign-out</span>
                {branch}
            </div>
        );
    }

    return (
        <div>
            {time(shift.start)} → {time(shift.end!)}
            {/* A night shift is one entry on the day it started; the marker is
                how the cell admits it ended on the next one. */}
            {shift.crosses_midnight && <span className="text-muted-foreground"> +1d</span>}
            {branch}
        </div>
    );
}

/** What a cell announces to a screen reader, since its contents are a glance. */
function cellLabel(day: EmployeeDay): string {
    const shifts = `${day.shifts.length} ${day.shifts.length === 1 ? 'shift' : 'shifts'}`;
    const sales = `${day.transactions} ${day.transactions === 1 ? 'sale' : 'sales'}`;

    return `${day.date}: ${shifts}, ${sales}`;
}

/**
 * A month of one employee's shifts and sales, as a calendar.
 *
 * Each cell carries the shifts that STARTED that day and the sales rung ON that
 * day. The two clocks do not agree for a night shift and are not made to — see
 * the note the page renders above this grid.
 *
 * Every cell is a button, EVERY day of the month included: a day with no shift
 * and no sale is still an answer ("did not come in, sold nothing"), and a grid
 * where some cells respond and others do not is a grid nobody can predict. What
 * opens is one day in full — see {@see EmployeeDayDialog}.
 */
export default function AttendanceCalendar({ days, employee }: { days: EmployeeDay[]; employee: EmployeeOption }) {
    const { format: money } = useCurrency();
    const [selected, setSelected] = useState<EmployeeDay | null>(null);
    const [open, setOpen] = useState(false);
    const weeks = weeksOf(days);

    const openDay = (day: EmployeeDay) => {
        setSelected(day);
        setOpen(true);
    };

    return (
        <div className="overflow-x-auto">
            <div className="min-w-[44rem]">
                <div className="text-muted-foreground grid grid-cols-7 gap-px text-xs font-medium tracking-wide uppercase">
                    {WEEKDAYS.map((weekday) => (
                        <div key={weekday} className="px-2 py-1.5">
                            {weekday}
                        </div>
                    ))}
                </div>
                <div className="bg-border grid grid-cols-7 gap-px overflow-hidden rounded-xl border">
                    {weeks.map((week, index) =>
                        week.map((day, cell) =>
                            day === null ? (
                                <div key={`${index}-${cell}`} className="bg-muted/40 min-h-24" />
                            ) : (
                                <button
                                    key={day.date}
                                    type="button"
                                    aria-label={cellLabel(day)}
                                    onClick={() => openDay(day)}
                                    className="bg-background hover:bg-muted/50 focus-visible:ring-ring flex min-h-24 flex-col gap-0.5 p-2 text-left text-xs focus-visible:ring-2 focus-visible:outline-hidden focus-visible:ring-inset"
                                >
                                    <div className="font-semibold tabular-nums">{day.day}</div>
                                    {day.shifts.map((shift, i) => (
                                        <ShiftLine key={i} shift={shift} />
                                    ))}
                                    {day.hours !== null && <div className="text-muted-foreground">{day.hours.toFixed(2)}h</div>}
                                    {day.transactions > 0 && (
                                        <div className="mt-auto pt-1 tabular-nums">
                                            <span className="text-muted-foreground">{day.transactions} ·</span> {money(day.net_sales)}
                                        </div>
                                    )}
                                </button>
                            ),
                        ),
                    )}
                </div>
            </div>

            {/* The chosen day deliberately outlives the close: clearing it with
                the same click would empty the panel while it is still animating
                out. The next click replaces it. */}
            <EmployeeDayDialog day={selected} employee={employee} open={open} onOpenChange={setOpen} />
        </div>
    );
}
