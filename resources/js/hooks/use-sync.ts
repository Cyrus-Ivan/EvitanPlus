import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { useCallback, useRef, useState } from 'react';

/**
 * Laravel's XSRF-TOKEN cookie, which it sets on every response. Inertia's own
 * requests carry it automatically; a bare `fetch` has to say so itself.
 */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * The branch's sync state, and the one way to ask for a cycle (M6).
 *
 * The state itself is a shared Inertia prop rather than local state, so every
 * mount of this hook — the header indicator, the POS page's post-checkout
 * trigger — reads the same value. After a cycle it is refreshed with a partial
 * reload of that one prop, which is what makes a sync fired from the POS screen
 * show up in the header without either component knowing about the other.
 *
 * `run()` never rejects. A cycle already records every outcome server-side, and
 * this is called from a page that has just completed a sale: nothing about a
 * failed sync may surface as an error over a transaction that is committed,
 * printed and paid for.
 */
export function useSync() {
    const { sync } = usePage<SharedData>().props;
    const inFlight = useRef(false);
    const [busy, setBusy] = useState(false);

    const run = useCallback(async () => {
        // The backstop timer (M7) and a checkout can land together; one cycle at
        // a time is enough, and a second would only contend for the same rows.
        if (inFlight.current) {
            return;
        }

        inFlight.current = true;
        setBusy(true);

        try {
            await fetch(route('sync'), {
                method: 'POST',
                headers: { 'X-XSRF-TOKEN': xsrfToken(), Accept: 'application/json' },
                credentials: 'same-origin',
            });
        } catch {
            // The device went offline mid-request. The next cycle will say so;
            // there is nothing to report here and nowhere useful to report it.
        } finally {
            inFlight.current = false;
            setBusy(false);

            // Only the shared sync prop comes back, so the page the cashier is
            // looking at — cart included — is left exactly as it was.
            router.reload({ only: ['sync'] });
        }
    }, []);

    return { sync, busy, run };
}
