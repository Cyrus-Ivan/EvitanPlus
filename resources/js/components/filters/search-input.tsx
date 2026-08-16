import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/**
 * The search box on a list page.
 *
 * Five had grown apart: three widths, two of them typed `search` and three not,
 * one with an `aria-label` and four without. The differences were all accidents
 * of which page was copied from which.
 *
 * `type="search"` is the one that is not cosmetic — it is what gives the box its
 * clear button, and on a till a filter you cannot clear in one action is a
 * filter people work around by reloading the page.
 *
 * Debouncing is NOT done here. It belongs to whoever owns the request (see
 * `useDebouncedFilter`), and a box that debounced internally would be unusable
 * for the one screen that filters a local array as you type.
 */
export default function SearchInput({
    value,
    onChange,
    placeholder,
    label,
    className,
}: {
    value: string;
    onChange: (value: string) => void;
    placeholder: string;
    /**
     * What the box searches, for screen readers — "Search sales", "Search batch".
     * The placeholder is not a substitute: it disappears as soon as anybody types.
     */
    label: string;
    className?: string;
}) {
    return (
        <Input
            type="search"
            value={value}
            onChange={(e) => onChange(e.target.value)}
            placeholder={placeholder}
            aria-label={label}
            className={cn('w-64', className)}
        />
    );
}
