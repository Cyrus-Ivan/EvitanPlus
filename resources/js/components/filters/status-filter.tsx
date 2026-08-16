import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ALL } from '@/lib/filters';

/**
 * Active / Archived / All — the filter on every screen that lists records which
 * are archived rather than deleted.
 *
 * Which is every screen that lists records at all: "never delete business
 * records" is a non-negotiable invariant, so archived rows are always there to
 * be asked for, and every list needs the same three-way choice. Four pages had
 * it written out identically, none of them with a label.
 *
 * `active` is the default and is stripped from the URL by `useListFilters`, so
 * the plain address of a list is the live records — the answer somebody typing
 * `/items` wants.
 */
export default function StatusFilter({ value, onChange, label = 'Status' }: { value: string; onChange: (value: string) => void; label?: string }) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger className="w-36" aria-label={label}>
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="active">Active</SelectItem>
                <SelectItem value="archived">Archived</SelectItem>
                <SelectItem value={ALL}>All</SelectItem>
            </SelectContent>
        </Select>
    );
}
