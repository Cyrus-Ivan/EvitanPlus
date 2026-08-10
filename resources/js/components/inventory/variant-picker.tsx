import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { BatchVariantOption } from '@/types';
import { useEffect, useState } from 'react';

/**
 * Pick one product, by typing.
 *
 * There is no combobox component in this codebase and no `cmdk` dependency, so
 * this is the same hand-rolled shape Stock History's variant filter uses: a
 * Radix Select with a search `Input` pinned inside its content. Two mechanics
 * there are load-bearing and easy to lose —
 *
 *  - the SELECTED option stays mounted whatever the search says, or Radix has no
 *    item matching the value and blanks the trigger; and
 *  - the input swallows every keystroke except the navigation keys, because
 *    Radix claims them for its own typeahead and Escape/Enter/arrows must still
 *    reach it.
 *
 * Unlike that one, the options here are FETCHED as you type rather than filtered
 * out of a prop. A batch dialog needs the whole catalog, and shipping the whole
 * catalog with every Inventory page render is a payload the size of the business
 * paid on a screen that mostly is not opening this dialog.
 */
export default function VariantPicker({
    value,
    onChange,
    branch,
    disabled,
    onOptionLoaded,
}: {
    value: string;
    onChange: (variantId: string) => void;
    /** Whose shelf the stock figures beside each option describe. */
    branch: string;
    disabled?: boolean;
    /** Fires when the picked option's details arrive, so the caller can show its stock. */
    onOptionLoaded?: (option: BatchVariantOption) => void;
}) {
    const [search, setSearch] = useState('');
    const [options, setOptions] = useState<BatchVariantOption[]>([]);
    const [loading, setLoading] = useState(false);
    // Kept out of `options` so it survives a search that no longer returns it.
    const [selected, setSelected] = useState<BatchVariantOption | null>(null);

    // Debounced, and re-run when the branch changes: the labels carry that
    // branch's stock, so the same list means something different for each one.
    useEffect(() => {
        let cancelled = false;
        setLoading(true);

        const timeout = setTimeout(() => {
            fetch(route('stock-batches.variants', { search: search || undefined, branch: branch || undefined }), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
                .then((response) => response.json())
                .then((payload: { variants?: BatchVariantOption[] }) => {
                    if (!cancelled) {
                        setOptions(payload.variants ?? []);
                    }
                })
                .catch(() => undefined)
                .finally(() => {
                    if (!cancelled) {
                        setLoading(false);
                    }
                });
        }, 250);

        return () => {
            cancelled = true;
            clearTimeout(timeout);
        };
    }, [search, branch]);

    // Re-report the selection whenever the branch changes, because its stock
    // figure just became a different number.
    useEffect(() => {
        if (value === '') {
            setSelected(null);
            return;
        }

        const match = options.find((option) => option.id === value);

        if (match) {
            setSelected(match);
            onOptionLoaded?.(match);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [value, options]);

    const shown = selected && !options.some((option) => option.id === selected.id) ? [selected, ...options] : options;

    return (
        <Select
            value={value || undefined}
            onValueChange={onChange}
            disabled={disabled}
            onOpenChange={(open) => {
                if (!open) {
                    setSearch('');
                }
            }}
        >
            <SelectTrigger className="w-full">
                <SelectValue placeholder="Search for a product" />
            </SelectTrigger>
            <SelectContent>
                <div className="bg-popover sticky top-0 z-10 mb-1 border-b p-1">
                    <Input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        onKeyDown={(event) => {
                            // Radix Select claims every keystroke for its own
                            // typeahead; let only the navigation keys through so
                            // Escape, Enter and the arrows still work.
                            if (!['Escape', 'Enter', 'ArrowDown', 'ArrowUp', 'Tab'].includes(event.key)) {
                                event.stopPropagation();
                            }
                        }}
                        placeholder="Search product, variant, SKU, barcode"
                        aria-label="Search products"
                        className="h-8"
                    />
                </div>
                {shown.map((option) => (
                    <SelectItem key={option.id} value={option.id}>
                        {option.item ?? 'Unknown product'}
                        {option.variant ? ` — ${option.variant}` : ''}
                        <span className="text-muted-foreground ml-2 text-xs">{option.sku}</span>
                    </SelectItem>
                ))}
                {shown.length === 0 && <div className="text-muted-foreground px-2 py-3 text-sm">{loading ? 'Searching…' : 'No products match.'}</div>}
            </SelectContent>
        </Select>
    );
}
