import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { MONTHS } from '@/lib/calendar';
import { ChevronLeft, ChevronRight } from 'lucide-react';

/**
 * One month, chosen — a step-back button, the month, the year, a step-forward
 * button.
 *
 * The window is a month rather than a free date range because what these screens
 * render is a calendar grid, and a grid cannot honour a range that is not one
 * month long. Offering a control the view cannot answer is worse than offering a
 * narrower one.
 *
 * Written out in full on `/login-history` and `/employee-performance`, and a
 * third time — already diverged — inside the employee report dialog, which had
 * gone as far as declaring its own `MONTHS` array rather than importing
 * `lib/calendar.ts`'s. Two lists of the names of the months, one import apart, is
 * how a screen ends up reporting on a different month than the one it names.
 *
 * The steps take their destination as a PROP rather than computing it. December
 * → January is a year change as well as a month change, and the server already
 * works out both when it decides which month it is rendering; deriving it a
 * second time on the client is a second chance to get the boundary wrong.
 */
export default function MonthYearPicker({
    month,
    year,
    years,
    onChange,
    previous,
    next,
}: {
    month: number;
    year: number;
    /** The years there is anything to show for, newest first. From the server. */
    years: number[];
    onChange: (next: { month?: number; year?: number }) => void;
    /** Where the back arrow goes. Omit to hide it. */
    previous?: { month: number; year: number };
    /** Where the forward arrow goes. Omit to hide it. */
    next?: { month: number; year: number };
}) {
    return (
        <div className="flex items-center gap-1">
            {previous && (
                <Button
                    variant="outline"
                    size="icon"
                    aria-label="Previous month"
                    onClick={() => onChange({ month: previous.month, year: previous.year })}
                >
                    <ChevronLeft className="size-4" />
                </Button>
            )}
            <Select value={String(month)} onValueChange={(value) => onChange({ month: Number(value) })}>
                <SelectTrigger className="w-36" aria-label="Month">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {MONTHS.map((label, index) => (
                        <SelectItem key={label} value={String(index + 1)}>
                            {label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <Select value={String(year)} onValueChange={(value) => onChange({ year: Number(value) })}>
                <SelectTrigger className="w-28" aria-label="Year">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {years.map((value) => (
                        <SelectItem key={value} value={String(value)}>
                            {value}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            {next && (
                <Button variant="outline" size="icon" aria-label="Next month" onClick={() => onChange({ month: next.month, year: next.year })}>
                    <ChevronRight className="size-4" />
                </Button>
            )}
        </div>
    );
}
