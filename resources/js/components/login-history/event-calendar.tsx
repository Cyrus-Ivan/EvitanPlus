import MonthGrid from '@/components/data/month-grid';
import LoginDayDialog from '@/components/login-history/day-dialog';
import { timeOfDay } from '@/lib/calendar';
import { EVENT_DOT, eventLabel } from '@/lib/login-events';
import type { LoginDay, LoginHistoryEntry } from '@/types';
import { useState } from 'react';

/**
 * How many events a cell prints before it stops counting them out.
 *
 * Three, because a cell is a glance and a day with eleven sign-ins is not
 * eleven times as informative as one with three. What is cut is always the END
 * of the day — the entries are chronological, so the cell shows how the day
 * opened and says how much more there was.
 */
const CELL_LIMIT = 3;

/** The person a row is about, as a cell has room to name them. */
function actor(entry: LoginHistoryEntry): string {
    return entry.user?.name ?? entry.user?.username ?? 'Unknown user';
}

/**
 * One event, as a line in a day cell.
 *
 * A dot rather than a Badge: a busy day would otherwise be a wall of pills, and
 * what has to survive the shrink is that an unexpected exit looks different from
 * a sign-in.
 *
 * The dot is never the ONLY thing carrying that difference, though — colour
 * alone is unreadable to a good share of the people who use this screen, and to
 * anyone reading a printout. So the line always names something in words, and
 * which word depends on what the reader does not already know:
 *
 *  - An **abnormal** event (crash, forced logout) always says so. That is the
 *    thing anybody is scanning a month for, and it outranks the name.
 *  - Otherwise, an Admin or the Owner gets the **person** — they are looking
 *    across a whole branch, and "who" is the question.
 *  - A **Cashier** sees only their own events, so their own name on every line
 *    says nothing; they get the **event** instead, which is what distinguishes
 *    one of their lines from the next.
 */
function EventLine({ entry, showUser }: { entry: LoginHistoryEntry; showUser: boolean }) {
    const abnormal = entry.event_type === 'application_crash' || entry.event_type === 'forced_logout';

    return (
        <div className="flex items-baseline gap-1.5 truncate">
            <span className={`size-1.5 shrink-0 rounded-full ${EVENT_DOT[entry.event_type] ?? 'bg-muted-foreground'}`} aria-hidden="true" />
            <span className="tabular-nums">{timeOfDay(entry.occurred_at ?? entry.created_at)}</span>
            <span className={`truncate ${abnormal ? 'text-destructive' : 'text-muted-foreground'}`}>
                {abnormal ? eventLabel(entry.event_type) : showUser ? actor(entry) : eventLabel(entry.event_type)}
            </span>
        </div>
    );
}

/** What a cell announces to a screen reader, since its contents are a glance. */
function cellLabel(day: LoginDay): string {
    const count = day.entries.length;

    return `${day.date}: ${count} ${count === 1 ? 'event' : 'events'}`;
}

/**
 * A month of login events, as a calendar.
 *
 * Each cell carries the events recorded on that day, oldest first — the day read
 * forward, as it happened. A day is bucketed by the same
 * `occurred_at ?? created_at` the row displays and the month filters on, so a
 * row that matched the month always has a cell to land in.
 *
 * Every cell is a button, EVERY day of the month included: a day with no events
 * is still an answer ("nobody signed in"), and a grid where some cells respond
 * and others do not is a grid nobody can predict. What opens is one day in full
 * — see {@see LoginDayDialog}.
 */
export default function LoginEventCalendar({ days, canViewAll }: { days: LoginDay[]; canViewAll: boolean }) {
    const [selected, setSelected] = useState<LoginDay | null>(null);
    const [open, setOpen] = useState(false);

    const openDay = (day: LoginDay) => {
        setSelected(day);
        setOpen(true);
    };

    return (
        <div>
            <MonthGrid
                days={days}
                onSelect={openDay}
                cellLabel={cellLabel}
                renderCell={(day) => (
                    <>
                        {day.entries.slice(0, CELL_LIMIT).map((entry) => (
                            <EventLine key={entry.id} entry={entry} showUser={canViewAll} />
                        ))}
                        {/* The cut is stated, never implied: a cell that silently
                            stopped at three would read as a quiet day. */}
                        {day.entries.length > CELL_LIMIT && (
                            <div className="text-muted-foreground mt-auto pt-1">+{day.entries.length - CELL_LIMIT} more</div>
                        )}
                    </>
                )}
            />

            {/* The chosen day deliberately outlives the close: clearing it with
                the same click would empty the panel while it is still animating
                out. The next click replaces it. */}
            <LoginDayDialog day={selected} canViewAll={canViewAll} open={open} onOpenChange={setOpen} />
        </div>
    );
}
