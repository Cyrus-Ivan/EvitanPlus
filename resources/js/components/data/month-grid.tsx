import { WEEKDAYS, weeksOf } from '@/lib/calendar';
import type { ReactNode } from 'react';

/** The least a day must carry to be laid out: where it falls, and what to call it. */
type CalendarDay = { weekday: number; date: string; day: number };

/**
 * A month as a calendar grid — the frame the two calendars in this app share.
 *
 * `lib/calendar.ts` already held the pieces that compute a month (`weeksOf`,
 * `WEEKDAYS`); what stayed duplicated was the 45 lines of markup around them,
 * byte-for-byte identical between the attendance calendar and the login event
 * calendar. The two differ only in what a cell says.
 *
 * Every cell is a button, EVERY day of the month included — a day with nothing
 * in it is still an answer ("nobody signed in", "did not come in, sold nothing"),
 * and a grid where some cells respond and others do not is a grid nobody can
 * predict. That rule was written twice in identical words before it lived here.
 *
 * No date is derived in this component or in `weeksOf`: the server sends every
 * day of the month and the grid lays out what it is given, which is what keeps
 * it honest about a month the page holds no rows for.
 */
export default function MonthGrid<T extends CalendarDay>({
    days,
    onSelect,
    cellLabel,
    renderCell,
}: {
    days: T[];
    onSelect: (day: T) => void;
    /** What a cell announces to a screen reader, since its contents are a glance. */
    cellLabel: (day: T) => string;
    /** A cell's contents, under the day number the grid prints itself. */
    renderCell: (day: T) => ReactNode;
}) {
    const weeks = weeksOf(days);

    return (
        // A month never compresses below seven readable columns; below that it
        // scrolls sideways rather than shrinking a cell past legibility.
        <div className="overflow-x-auto">
            <div className="min-w-[44rem]">
                <div className="text-muted-foreground grid grid-cols-7 gap-px text-xs font-medium tracking-wide uppercase">
                    {WEEKDAYS.map((weekday) => (
                        <div key={weekday} className="px-2 py-1.5">
                            {weekday}
                        </div>
                    ))}
                </div>
                {/* The 1px gap is the bordered background showing through, which is
                    what draws the grid lines without a border per cell. */}
                <div className="bg-border grid grid-cols-7 gap-px overflow-hidden rounded-xl border">
                    {weeks.map((week, index) =>
                        week.map((day, cell) =>
                            day === null ? (
                                // The padding either side of a partial week. Muted
                                // rather than empty, so the month's shape reads.
                                <div key={`${index}-${cell}`} className="bg-muted/40 min-h-24" />
                            ) : (
                                <button
                                    key={day.date}
                                    type="button"
                                    aria-label={cellLabel(day)}
                                    onClick={() => onSelect(day)}
                                    className="bg-background hover:bg-muted/50 focus-visible:ring-ring flex min-h-24 flex-col gap-0.5 p-2 text-left text-xs focus-visible:ring-2 focus-visible:outline-hidden focus-visible:ring-inset"
                                >
                                    <div className="font-semibold tabular-nums">{day.day}</div>
                                    {renderCell(day)}
                                </button>
                            ),
                        ),
                    )}
                </div>
            </div>
        </div>
    );
}
