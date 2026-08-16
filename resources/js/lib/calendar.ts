// Shared month-grid pieces, used by the two calendars in the app — employee
// performance's attendance month and login history's event month — and by the
// month/year pickers above both.

// Monday first, matching the ISO weekday the server sends and the day picker in
// the scheduled-report settings.
export const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

export const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

/** "Monday, 3 August 2026", from a day's own `YYYY-MM-DD`. */
export function fullDate(date: string): string {
    // Parsed as local midnight rather than through `new Date(date)`, which reads
    // a bare date as UTC and lands the label on the previous day west of it.
    const [year, month, day] = date.split('-').map(Number);
    const parsed = new Date(year, month - 1, day);

    return Number.isNaN(parsed.getTime()) ? date : parsed.toLocaleDateString(undefined, { dateStyle: 'full' });
}

/** A timestamp as a clock time, e.g. "8:02 AM". */
export function timeOfDay(iso: string | null): string {
    if (!iso) return '—';

    const date = new Date(iso);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

/**
 * The month's days cut into calendar weeks, with the leading and trailing cells
 * of a partial week left null so the grid keeps its shape.
 *
 * Generic over the day, because the two calendars carry entirely different
 * payloads and share only the ISO `weekday` the padding is computed from. No
 * date is ever derived here: the server sends every day of the month, which is
 * what keeps the grid honest about a month it holds no rows for.
 */
export function weeksOf<T extends { weekday: number }>(days: T[]): (T | null)[][] {
    if (days.length === 0) return [];

    const weeks: (T | null)[][] = [];
    // A month starting on a Thursday opens with three empty cells.
    let week: (T | null)[] = Array.from({ length: days[0].weekday - 1 }, () => null);

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
