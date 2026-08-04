import { type SharedData, type SyncSnapshot } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * The backstop interval, hard-coded and the same online and offline (§11.3).
 *
 * Five minutes, and the number is doing a different job than the spec first
 * assigned it. §11.3 sized this timer to guarantee an offline till is "never
 * stuck offline indefinitely"; from M7 it is also the ONLY thing that makes an
 * already-online till pull, because nothing local signals that another branch
 * changed a price. So it is the freshness bound on shared data, not just a
 * safety net — a branch opening in the morning would otherwise trade all day on
 * yesterday's catalog.
 *
 * Still one interval with no knob. §11.3 argues at length against a tuning
 * surface nobody will ever tune on a product with one maintainer, and that
 * reasoning is untouched by the value changing.
 */
const BACKSTOP_MS = 5 * 60 * 1000;

/**
 * Trigger bookkeeping, at MODULE scope rather than in a ref — and this is not an
 * optimisation, it is the difference between the triggers working and firing on
 * every click.
 *
 * This app sets no persistent Inertia layout (no `Page.layout`), so a navigation
 * swaps the whole tree: the header, and with it {@link SyncIndicator} and every
 * hook below, UNMOUNTS AND REMOUNTS. Anything kept in a ref resets each time. A
 * mount-triggered cycle would therefore fire on every page the cashier opens, a
 * backstop interval would restart before it ever elapsed on a till anyone was
 * using, and the "already tried this backlog" guard would forget between clicks —
 * turning a listener that fires on new work into one that fires on navigation.
 *
 * Module scope outlives the page components and is reset only by a real reload,
 * which is exactly the lifetime these three want.
 */
let inFlight = false;
let lastCycleAt = 0;
let attemptedBacklog: number | null = null;

/**
 * States in which asking for another cycle cannot help.
 *
 * A revoked credential, a build below the schema's floor and a refused payload
 * are each fixed by a person, not by trying again — and `offline` would burn a
 * probe on every page navigation for as long as the connection is down. Without
 * this the outbox listener below would fire on every render for a backlog that
 * is never going to clear.
 */
function stuck(sync: SyncSnapshot): boolean {
    return sync.state !== 'online' || sync.error_kind === 'conflict';
}

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
 * ## Why a PERSON asking is a different case from a timer asking
 *
 * `refreshPage` re-runs the current page's controller as well, and only the
 * button passes it. A cycle is not the only way a screen goes stale, and for
 * some screens it is not a way at all: `/login-history` and `/reports` at a
 * scope wider than this branch read Postgres LIVE inside their controller, so
 * another branch's rows arrive by the controller running again and by nothing
 * else — no amount of pulling puts them in SQLite. Before this flag existed,
 * pressing a button labelled "Sync" updated the button and left the table
 * beneath it untouched, and the only way to see another branch's activity was
 * to reload the browser.
 *
 * It is deliberately NOT conditional on the cycle having moved anything. The
 * response carries `pushed`/`pulled` counts and gating on them looks free, but
 * `login_history` is not in the puller: the case above would report `pulled: 0`
 * and skip the refresh that is the entire point.
 *
 * `run()` never rejects. A cycle already records every outcome server-side, and
 * this is called from a page that has just completed a sale: nothing about a
 * failed sync may surface as an error over a transaction that is committed,
 * printed and paid for.
 */
export function useSync() {
    const { sync } = usePage<SharedData>().props;
    const [busy, setBusy] = useState(false);

    const run = useCallback(async (refreshPage = false) => {
        // The backstop timer, the outbox listener and a reconnect can all land
        // together; one cycle at a time is enough, and a second would only
        // contend for the same rows. Module-scoped, so a navigation landing
        // mid-cycle cannot start a second one.
        if (inFlight) {
            return;
        }

        inFlight = true;
        lastCycleAt = Date.now();
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
            inFlight = false;
            setBusy(false);

            // What comes back: the indicator's prop alone, or the whole screen.
            //
            // Nothing is LOST either way, and the reason this used to say
            // otherwise was wrong. Inertia's reload() spreads
            // `preserveState`/`preserveScroll` AFTER the caller's options, so a
            // caller cannot switch them off: the page component is never
            // remounted, and the POS cart, every half-typed useForm field and
            // even the validation errors on screen all survive a full reload.
            //
            // The narrowing is about MOVEMENT, not loss. A full reload replaces
            // `products`, so a background cycle could re-sort or grey out a POS
            // tile — and shift rows between pages of any paginated table — under
            // someone mid-task. A person who just pressed Sync is expecting the
            // screen to change; a cashier three items into a sale is not, which
            // is why only the button asks for it.
            router.reload(refreshPage ? {} : { only: ['sync'] });
        }
    }, []);

    return { sync, busy, run };
}

/**
 * The four things that ask for a sync cycle (M7, §11.3).
 *
 * Mount this in EXACTLY ONE PLACE AT A TIME — {@link SyncIndicator}, rendered by
 * the app header on every authenticated screen and by the auth layout on the
 * login screen (M11.5). Those two are mutually exclusive, so one indicator is
 * ever mounted; two would mean two live backstop intervals, which is the actual
 * failure this rule guards against. It is a separate hook rather than part of
 * {@link useSync} for the same reason: a second component reading sync state
 * must not silently start a second timer.
 *
 * ## Why a listener on the outbox instead of polling for the backlog
 *
 * Every server response already carries `unsynced_count` in the shared `sync`
 * prop, read straight from the outbox. So the client learns the backlog grew
 * without anything being built to tell it — a sale, a return, a login or an
 * audit row produces a response, the count moves, and a cycle fires within
 * seconds. Polling would ask the same question on a clock and get "nothing to
 * do" almost every time.
 *
 * The listener cannot see the other direction. Branch A editing a price writes
 * nothing at all on Branch B, so B's outbox stays empty however much changes
 * upstream — which is why the timer survives, and why its job is now the pull
 * rather than the push.
 *
 * All four are the AUTOMATIC kind, so none of them passes `refreshPage`: they
 * fire on a clock or on an event, at a moment nobody chose, and the screen a
 * person is working on may not move underneath them. The button in
 * {@link SyncIndicator} is the only caller that does — see {@link useSync}.
 */
export function useSyncTriggers() {
    const { sync, run } = useSync();

    const runRef = useRef(run);
    runRef.current = run;

    // A cycle only if one is DUE. The time-based triggers go through this and
    // the event-based ones do not, which is what makes the two kinds behave
    // correctly under remounting: a till someone is clicking through remounts
    // constantly and gets at most one cycle per interval, while an idle till
    // keeps a live timer and gets one on the same cadence. Either way the
    // machine syncs about every five minutes and never per click.
    const runIfDue = useCallback(() => {
        if (Date.now() - lastCycleAt >= BACKSTOP_MS) {
            void runRef.current();
        }
    }, []);

    // 1. On mount, if due. An app launched while already online never receives
    //    an `online` event, so without this the first cycle waits a full
    //    interval — and `lastCycleAt` starts at 0, so the genuine first mount
    //    after a launch always qualifies.
    useEffect(() => {
        runIfDue();
    }, [runIfDue]);

    // 2. The outbox listener — event-based, so NOT throttled: new work should go
    //    up in seconds, which is the entire point of listening rather than
    //    polling.
    useEffect(() => {
        if (!sync || sync.unsynced_count === 0) {
            attemptedBacklog = null;

            return;
        }

        if (stuck(sync) || !navigator.onLine || attemptedBacklog === sync.unsynced_count) {
            return;
        }

        // Keyed on the count, at module scope: a backlog that cannot clear costs
        // one retry per NEW write rather than one per page the cashier opens.
        attemptedBacklog = sync.unsynced_count;

        void runRef.current();
    }, [sync]);

    // 3. Reconnect, and 4. the backstop.
    //
    //    Reconnect is not throttled — it is the near-instant trigger of §11.3 and
    //    the whole reason the webview event is listened to at all. Neither
    //    decides anything: the cycle's own probe confirms reachability, because
    //    navigator.onLine is optimistic and a captive portal reports online.
    useEffect(() => {
        const reconnected = () => void runRef.current();

        window.addEventListener('online', reconnected);

        const timer = window.setInterval(runIfDue, BACKSTOP_MS);

        return () => {
            window.removeEventListener('online', reconnected);
            window.clearInterval(timer);
        };
    }, [runIfDue]);
}
