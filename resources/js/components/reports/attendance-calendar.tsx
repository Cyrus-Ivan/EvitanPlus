import { useCurrency } from '@/hooks/use-currency';
import type { EmployeeDay, EmployeeShift } from '@/types';

// Monday first, matching the ISO weekday the server sends and the day picker in
// the scheduled-report settings.
const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

function time(iso: string): string {
    const date = new Date(iso);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

/**
 * The month's days cut into calendar weeks, with the leading and trailing cells
 * of a partial week left null so the grid keeps its shape.
 */
function weeksOf(days: EmployeeDay[]): (EmployeeDay | null)[][] {
    if (days.length === 0) return [];

    const weeks: (EmployeeDay | null)[][] = [];
    // A month starting on a Thursday opens with three empty cells.
    let week: (EmployeeDay | null)[] = Array.from({ length: days[0].weekday - 1 }, () => null);

    for (const day of days) {
        week.push(day);
        if (week.length === 7) {
            weeks.push(week);
            week = [];
        }
    }

    if (week.length > 0) {
        weeks.push([...week, ...Array.from({ length: 7 - week.length }, () => null)]);
    }

    return weeks;
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

/**
 * A month of one employee's shifts and sales, as a calendar.
 *
 * Each cell carries the shifts that STARTED that day and the sales rung ON that
 * day. The two clocks do not agree for a night shift and are not made to — see
 * the note the page renders above this grid.
 */
export default function AttendanceCalendar({ days }: { days: EmployeeDay[] }) {
    const { format: money } = useCurrency();
    const weeks = weeksOf(days);

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
                                <div key={day.date} className="bg-background flex min-h-24 flex-col gap-0.5 p-2 text-xs">
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
                                </div>
                            ),
                        ),
                    )}
                </div>
            </div>
        </div>
    );
}
