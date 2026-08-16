import { Input } from '@/components/ui/input';

/**
 * A From/To window over a list — `/sales`, `/audit-logs` and
 * `/inventory/stock-history`, which had the same eighteen lines each.
 *
 * The bounds cross-bind: `From` caps at whatever `To` holds and `To` floors at
 * `From`, so the picker itself refuses to offer a window that runs backwards.
 * That is the whole client-side validation, and it is enough — the server
 * re-checks, and an unenterable range needs no error message.
 *
 * Native `<input type="date">` rather than a calendar component. The app runs in
 * a Chromium shell, so the native picker is already keyboard-accessible,
 * localised to the reader's own date format, and typeable by someone who would
 * rather not open a calendar at all. A `react-day-picker` here would be a new
 * dependency in a desktop bundle to arrive at the same place.
 *
 * The labels are the fix this extraction carries: all three copies used a bare
 * `<span>From</span>` beside the box, which is a label to a person looking at it
 * and nothing at all to a screen reader — on toolbars where every neighbouring
 * `Select` was correctly labelled.
 */
export default function DateRangeFilter({
    from,
    to,
    onChange,
    label = 'Date',
}: {
    from: string | null;
    to: string | null;
    onChange: (next: { date_from?: string | null; date_to?: string | null }) => void;
    /** What the window is over, e.g. "Sale date". Prefixes both boxes' labels. */
    label?: string;
}) {
    return (
        <div className="flex items-center gap-2">
            <span className="text-muted-foreground text-sm" aria-hidden="true">
                From
            </span>
            <Input
                type="date"
                value={from ?? ''}
                max={to ?? undefined}
                onChange={(e) => onChange({ date_from: e.target.value || null })}
                aria-label={`${label} from`}
                className="w-40"
            />
            <span className="text-muted-foreground text-sm" aria-hidden="true">
                To
            </span>
            <Input
                type="date"
                value={to ?? ''}
                min={from ?? undefined}
                onChange={(e) => onChange({ date_to: e.target.value || null })}
                aria-label={`${label} to`}
                className="w-40"
            />
        </div>
    );
}
