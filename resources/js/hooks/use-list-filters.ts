import type { FormDataConvertible } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef } from 'react';

type FilterValue = FormDataConvertible | null | undefined;

/**
 * The filter machinery every list page in this app had written for itself: merge
 * the change into the filters already applied, drop the ones that are not
 * filtering anything, and re-request the page without losing the reader's place.
 *
 * Seven pages had their own copy of that function. They agreed on the hard parts
 * — `preserveState`, `preserveScroll`, `replace` — and the reason they had to is
 * worth keeping in one place:
 *
 *  - `preserveState` keeps the local React state (an open row, a half-typed
 *    search) across the visit.
 *  - `preserveScroll` keeps the reader where they were. Without it, changing a
 *    filter partway down a long list throws them back to the top.
 *  - `replace` keeps a hundred keystrokes out of the back button's history.
 *
 * Two things are the CALLER'S and are never guessed here:
 *
 * `only` — the partial-reload keys. They differ per page and they are load
 * bearing in both directions: too few and a prop the page renders goes stale;
 * too many and every keystroke pays for a prop it did not need. `/sales` and
 * `/inventory/stock-history` both deliberately leave `branches` out, because the
 * branch roster costs an ONLINE READ and typing in a search box must not.
 *
 * `defaults` — what a filter's "not filtering" value is. It is `'all'` for a
 * sentinel select (see `lib/filters.ts`), `'active'` for a status filter,
 * `'local'` for a branch scope. The server fills these in itself, so sending
 * them writes noise into the URL for no change in the result.
 *
 * Branch scope is passed in `values` like any other filter, and that is what
 * fixes the bug the old copies kept warning each other about in comments: scope
 * is carried through EVERY change, not just a scope change. The search box calls
 * `apply` on each keystroke, and a version that only forwarded scope when scope
 * was what changed would silently walk the reader back to their own branch
 * mid-sentence.
 */
// Constrained to `object` rather than to `Record<string, FilterValue>`: every
// page declares its filters as an `interface`, and TypeScript gives implicit
// index signatures to type aliases but not to interfaces. The narrower
// constraint would have meant rewriting ten declarations to satisfy a check that
// the loop below performs at runtime anyway.
export function useListFilters<TValues extends object>({
    url,
    values,
    defaults,
    only,
}: {
    /** The already-resolved route, e.g. `route('sales')`. */
    url: string;
    /**
     * Every filter currently in force, including branch scope where the page has
     * one — `{ ...filters, scope }`. Composed by the caller rather than read off
     * a prop, because `scope` arrives as its own prop on the four pages that
     * offer it.
     */
    values: TValues;
    /** Per-key value meaning "not filtering". Omitted keys drop only when empty. */
    defaults?: Partial<Record<keyof TValues, FilterValue>>;
    /**
     * Props to re-request. Never defaulted — see above.
     *
     * An EMPTY array means a full reload, and is passed through as no `only` at
     * all: Inertia reads `only: []` as "send me nothing", which would answer with
     * an empty page rather than a whole one. `/inventory/stock-updates` is the
     * one page that wants this — it has few props and every one of them changes
     * with the filters.
     */
    only: string[];
}) {
    // Held in a ref so `apply` keeps a stable identity: it is a dependency of the
    // debounce effect below, and a new function every render would restart the
    // timer on every keystroke — which is every render.
    const latest = useRef({ url, values, defaults, only });
    latest.current = { url, values, defaults, only };

    const apply = useCallback((next: Partial<TValues>) => {
        const { url, values, defaults, only } = latest.current;
        const merged = { ...values, ...next };
        const query: Record<string, FormDataConvertible> = {};

        for (const [key, value] of Object.entries(merged)) {
            // An empty box and an unmade choice are both "no filter". `== null`
            // catches null and undefined together and leaves `0` and `false`
            // alone, which `!value` would not.
            if (value === '' || value == null) continue;
            if (defaults && key in defaults && value === defaults[key as keyof TValues]) continue;

            query[key] = value as FormDataConvertible;
        }

        router.get(url, query, { preserveState: true, preserveScroll: true, replace: true, ...(only.length > 0 ? { only } : {}) });
    }, []);

    return { apply };
}

/**
 * Runs `onSettled` once the value has stopped changing — the search box, so a
 * request does not go out per keystroke.
 *
 * What it compares against is the value the list was LAST ASKED FOR, held in a
 * ref rather than read back off the filter prop. `/inventory/stock-history`
 * found out why the hard way: the prop only catches up when the response lands,
 * and its stock-entry dialog sets the box and filters in one go, so a guard
 * waiting on the prop fired a second identical request 300 ms later whenever
 * that read took longer than the debounce — which is exactly when it is a
 * cross-branch one.
 *
 * The ref starting at the initial value is also what keeps the hook quiet on
 * first render, where the three older copies each needed an `isFirstRender`
 * flag of their own.
 *
 * Normalize before passing in, not after: `/inventory/stock-history` compares
 * `search.trim() || null` so that an emptied box differs from a live term, which
 * `''` vs `''` would not.
 */
export function useDebouncedFilter<T>(value: T, onSettled: (value: T) => void, delay = 300) {
    const applied = useRef(value);
    const callback = useRef(onSettled);
    callback.current = onSettled;

    useEffect(() => {
        if (value === applied.current) return;

        const timeout = setTimeout(() => {
            applied.current = value;
            callback.current(value);
        }, delay);

        return () => clearTimeout(timeout);
    }, [value, delay]);

    return {
        /**
         * "The list already has this term; don't ask again."
         *
         * For the case where something OTHER than typing sets the box —
         * `/inventory/stock-history`'s stock-entry dialog links to a batch, which
         * fills the search and filters in one go. Without this the value would
         * look new to the effect above and fire a second, identical request 300 ms
         * after the first. Call it before setting the value.
         */
        markApplied: (next: T) => {
            applied.current = next;
        },
    };
}
