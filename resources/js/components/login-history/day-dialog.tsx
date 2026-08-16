import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { fullDate, timeOfDay } from '@/lib/calendar';
import { EVENT, eventLabel } from '@/lib/login-events';
import type { LoginDay } from '@/types';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { Fragment, useState } from 'react';

/**
 * One day of login history, in full.
 *
 * The calendar cell is a glance — three lines and a count. This is the day
 * behind it, in the columns the screen used to page through, because the columns
 * were never the problem: a table of the whole month was. Here the table is
 * bounded by the day somebody asked about, so it needs no pagination and never
 * grows past what one dialog can hold.
 *
 * ## Why the note is a disclosure and not a column
 *
 * `notes` is prose, and only the two ABNORMAL events ever carry any — an unclean
 * exit and a forced logout, both written as full sentences by
 * `App\Support\Auth\SessionManager`. As a column it was therefore a dash on most
 * rows and a paragraph on a few, which is the worst of both: it widened the
 * table past the dialog on every row to serve a minority of them, and the
 * horizontal scrollbar it earned hid the Event column that every row does use.
 *
 * So it moved behind a chevron on the row itself, in the pattern `/audit-logs`
 * established for the same reason: rows that have something to expand say so and
 * are clickable, rows that do not are inert rather than a row-shaped button that
 * does nothing. Keyed off the note being PRESENT rather than off the event type,
 * because a consolidated read renders whatever another branch's build wrote.
 *
 * The rows come from the page prop that drew the cell — no fetch. That is what
 * makes it open instantly and what makes it work offline in the `local` scope,
 * where a second request would have nothing to ask.
 *
 * Read-only throughout, like the screen: `login_history` is append-only and
 * nothing on this dialog links anywhere.
 */
export default function LoginDayDialog({
    day,
    canViewAll,
    open,
    onOpenChange,
}: {
    day: LoginDay | null;
    canViewAll: boolean;
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
                className="focus:outline-hidden sm:max-w-3xl"
                onOpenAutoFocus={(event) => {
                    event.preventDefault();
                    (event.currentTarget as HTMLElement | null)?.focus();
                }}
            >
                {day && <DayBody day={day} canViewAll={canViewAll} />}
            </DialogContent>
        </Dialog>
    );
}

function DayBody({ day, canViewAll }: { day: LoginDay; canViewAll: boolean }) {
    const [expanded, setExpanded] = useState<string | null>(null);
    const count = day.entries.length;

    // The chevron column, plus When, Branch, Event, and User for those who see
    // everyone's. What an opened note spans.
    const columns = canViewAll ? 5 : 4;

    return (
        <>
            <DialogHeader>
                <DialogTitle>{fullDate(day.date)}</DialogTitle>
                <DialogDescription>
                    {count === 0 ? 'No login events were recorded on this day.' : `${count} ${count === 1 ? 'event' : 'events'}, oldest first.`}
                </DialogDescription>
            </DialogHeader>

            {count > 0 && (
                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-8" />
                                <TableHead>When</TableHead>
                                {/* The branch CODE — the identifier that prefixes
                                    every receipt this till prints, and the one a
                                    branch is called by. */}
                                <TableHead>Branch</TableHead>
                                {canViewAll && <TableHead>User</TableHead>}
                                <TableHead>Event</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {day.entries.map((entry) => {
                                const isOpen = expanded === entry.id;
                                const hasNote = entry.notes !== null && entry.notes !== '';

                                return (
                                    <Fragment key={entry.id}>
                                        <TableRow
                                            className={hasNote ? 'cursor-pointer' : undefined}
                                            onClick={hasNote ? () => setExpanded(isOpen ? null : entry.id) : undefined}
                                        >
                                            <TableCell className="text-muted-foreground">
                                                {hasNote ? isOpen ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" /> : null}
                                            </TableCell>
                                            {/* The clock time only: the date is
                                                the dialog's title, and repeating
                                                it on every row is the column
                                                doing no work. */}
                                            <TableCell className="whitespace-nowrap tabular-nums">
                                                {timeOfDay(entry.occurred_at ?? entry.created_at)}
                                            </TableCell>
                                            <TableCell className="font-medium">{entry.branch ?? '—'}</TableCell>
                                            {canViewAll && <TableCell>{entry.user?.name ?? entry.user?.username ?? '—'}</TableCell>}
                                            <TableCell>
                                                <Badge variant={EVENT[entry.event_type]?.variant ?? 'outline'}>{eventLabel(entry.event_type)}</Badge>
                                            </TableCell>
                                        </TableRow>
                                        {isOpen && hasNote && (
                                            <TableRow>
                                                <TableCell colSpan={columns} className="bg-muted/30">
                                                    <p className="text-muted-foreground p-2 text-sm">{entry.notes}</p>
                                                </TableCell>
                                            </TableRow>
                                        )}
                                    </Fragment>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            )}
        </>
    );
}
