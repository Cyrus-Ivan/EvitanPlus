import { ReportPeriod } from '@/types';

/**
 * The [start, end] a period and an anchor date resolve to, and how to spell it.
 *
 * A deliberate small copy of App\Support\Reports\ReportPeriods::resolveWindow()
 * and ::label(). The server stays the authority — it re-derives the window from
 * the posted period and date and the document is built from ITS answer — but the
 * email dialog lets you pick a window without moving the page behind it, so
 * something on the client has to be able to say which window that is. The
 * alternative is a round trip, which would move the page.
 *
 * Weeks start on Monday, matching Carbon's default and therefore the server's.
 */
export function periodWindow(period: ReportPeriod, date: string): { from: Date; to: Date } {
    const anchor = new Date(`${date}T00:00:00`);

    if (Number.isNaN(anchor.getTime())) {
        return periodWindow(period, new Date().toISOString().slice(0, 10));
    }

    const from = new Date(anchor);
    const to = new Date(anchor);

    if (period === 'weekly') {
        // getDay() is Sunday = 0; shift so Monday is the start.
        from.setDate(from.getDate() - ((anchor.getDay() + 6) % 7));
        to.setTime(from.getTime());
        to.setDate(to.getDate() + 6);
    } else if (period === 'monthly') {
        from.setDate(1);
        to.setMonth(to.getMonth() + 1, 0);
    } else if (period === 'yearly') {
        from.setMonth(0, 1);
        to.setMonth(11, 31);
    }

    return { from, to };
}

/** e.g. "Aug 10 – Aug 16, 2026" for a weekly window. Mirrors ReportPeriods::label(). */
export function periodWindowLabel(period: ReportPeriod, date: string): string {
    const { from, to } = periodWindow(period, date);

    if (period === 'weekly') {
        const start = from.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
        const end = to.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });

        return `${start} – ${end}`;
    }

    if (period === 'monthly') {
        return from.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
    }

    if (period === 'yearly') {
        return String(from.getFullYear());
    }

    return from.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' });
}
