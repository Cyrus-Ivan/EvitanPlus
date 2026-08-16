/**
 * Display formatting for the values money and dates are not.
 *
 * Money already has a home (`lib/money.ts` + `hooks/use-currency.ts`, which know
 * the business currency symbol) and calendar-cell dates have theirs
 * (`lib/calendar.ts`). What was left over is the timestamp on a list row, and it
 * had been written out longhand in six files — five of which forgot the null
 * guard the sixth had, so an audit row with no `occurred_at` rendered
 * "Invalid Date" on five screens and "—" on one.
 */

/**
 * A timestamp as a full local date and time, e.g. "16/08/2026, 8:02:14 AM".
 *
 * Locale-formatted rather than fixed: the app ships to one country today, but
 * the reader's own conventions are the right default for a value with no
 * business meaning attached to its shape (unlike a receipt serial, which is
 * `docs/glossary.md`-defined and formatted by the server).
 *
 * Null, empty and unparseable all render as an em dash. A row that has no
 * timestamp and a row whose timestamp is corrupt are the same to a reader: the
 * screen cannot say when, and pretending otherwise with "Invalid Date" tells
 * them nothing they can act on.
 */
export function formatWhen(value: string | null | undefined): string {
    if (!value) return '—';

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
}

/**
 * A duration in hours, e.g. "7.50h".
 *
 * Two decimal places because the underlying figure is a difference of two
 * timestamps and rounding it to whole hours would make a 25-minute shift
 * disappear. Null is an OPEN shift, not a zero-length one — the app records an
 * unclean exit on the next launch, so it knows the shift ended and knows nothing
 * trustworthy about when.
 */
export function formatHours(value: number | null | undefined): string {
    return value === null || value === undefined || !Number.isFinite(value) ? '—' : `${value.toFixed(2)}h`;
}
