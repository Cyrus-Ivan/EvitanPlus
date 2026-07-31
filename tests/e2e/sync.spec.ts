import { expect, test, type Page } from '@playwright/test';
import { addToCart, login, toast } from './helpers';

/**
 * The four sync triggers (`resources/js/hooks/use-sync.ts`, M7).
 *
 * These shipped with no automated coverage at all — they were verified by
 * driving a browser by hand, once. The regression they exist to catch is not
 * hypothetical: this app sets no persistent Inertia layout, so every navigation
 * unmounts and remounts the header, the indicator and the hook. A version of
 * this code that kept its bookkeeping in a ref would run a cycle on every page
 * a cashier opened, all day, and nothing would have noticed. That bug was found
 * by reading the file.
 *
 * ## Why the counting happens at the network layer
 *
 * `page.on('request')` filtered to `POST /sync`, never an injected page script.
 * The instrumentation traps that produced a false reading during M7's manual
 * verification were both of that kind — a probe installed after React had
 * already hydrated, and a reading corroborated against a `data-page` attribute
 * that a client-side visit never updates. A network observer attaches before
 * the first navigation and cannot race hydration, and the follow-up
 * `router.reload({ only: ['sync'] })` after every cycle is a GET, so it is never
 * miscounted.
 *
 * ## Each assertion was confirmed to fail
 *
 * Against a deliberately broken hook, because a trigger test that cannot fail is
 * worse than none: with the due check removed, "navigating between screens" went
 * 1 → 8 cycles and the backlog test 1 → 6; with the interval lengthened, the
 * backstop test saw no second cycle. Worth repeating that mutation if this file
 * is ever restructured.
 *
 * ## What this device is, and what that buys
 *
 * The e2e database carries the fake `DEV` branch identity and NO sealed branch
 * credential, so every cycle here ends `offline`/`auth` in a few milliseconds
 * without touching a network. That is what makes these assertions hermetic —
 * and it is also what bounds them, see the last test.
 */

/** Count the cycles this page asks for. Attach before the first navigation. */
function cycleCounter(page: Page): () => number {
    let cycles = 0;

    page.on('request', (request) => {
        if (request.method() === 'POST' && new URL(request.url()).pathname === '/sync') {
            cycles++;
        }
    });

    return () => cycles;
}

/**
 * Follow a sidebar link, i.e. a CLIENT-SIDE Inertia visit.
 *
 * `page.goto` would be wrong for every assertion below: a full page load
 * legitimately resets the module-scoped bookkeeping the throttle depends on, so
 * a cycle after one proves nothing. The remount these tests are about is the
 * one a navigation causes without a reload.
 */
async function follow(page: Page, title: string, path: string): Promise<void> {
    await page.getByRole('link', { name: title, exact: true }).click();
    await page.waitForURL(`**${path}`);
}

test('the first mount after a launch runs exactly one cycle', async ({ page }) => {
    const cycles = cycleCounter(page);

    // `lastCycleAt` starts at 0, so the genuine first mount always qualifies —
    // an app launched while already online never receives an `online` event, and
    // without this trigger its first cycle would wait a full interval.
    await login(page, 'owner');

    await expect.poll(cycles).toBe(1);

    // And settles there: a second cycle seconds after the first would mean the
    // mount trigger is not going through the due check at all.
    await page.waitForTimeout(1_500);
    expect(cycles()).toBe(1);
});

test('navigating between screens does not run a cycle per page', async ({ page }) => {
    const cycles = cycleCounter(page);

    await login(page, 'owner');
    await expect.poll(cycles).toBe(1);

    await follow(page, 'Inventory', '/inventory');
    await follow(page, 'Sales', '/sales');
    await follow(page, 'Reports', '/reports');
    await follow(page, 'Stock History', '/inventory/stock-history');
    await follow(page, 'Point of Sale', '/pos');

    // Five remounts of the indicator, still one cycle. This is the whole reason
    // the trigger bookkeeping lives at module scope rather than in a ref.
    expect(cycles()).toBe(1);
});

test('a reconnect runs a cycle immediately, without waiting for the interval', async ({ page }) => {
    const cycles = cycleCounter(page);

    await login(page, 'owner');
    await expect.poll(cycles).toBe(1);

    // Deliberately NOT throttled: this is §11.3's near-instant reconnect, and it
    // is the entire reason the webview event is listened to. The cycle's own
    // probe confirms reachability afterwards, because `navigator.onLine` is
    // optimistic and a captive portal reports online.
    await page.evaluate(() => window.dispatchEvent(new Event('online')));

    await expect.poll(cycles).toBe(2);
});

test('the backstop runs a cycle at five minutes and not before', async ({ page }) => {
    // Installed before any navigation so the page's own clock is the fake one.
    await page.clock.install();

    const cycles = cycleCounter(page);

    await login(page, 'owner');
    await expect.poll(cycles).toBe(1);

    // Four minutes in: the interval has not elapsed, and `runIfDue` refuses.
    await page.clock.fastForward('04:00');
    await page.waitForTimeout(500);
    expect(cycles()).toBe(1);

    // Past five: exactly one more. The timer's job since M7 is the PULL —
    // nothing local signals that another branch changed a price — so this
    // interval is the freshness bound on shared data, not a safety net.
    await page.clock.fastForward('01:30');
    await expect.poll(cycles).toBe(2);
});

test('a backlog that cannot clear does not run a cycle per navigation', async ({ page }) => {
    const cycles = cycleCounter(page);

    await login(page, 'owner');
    await expect.poll(cycles).toBe(1);

    // A sale enqueues its rows in the outbox, so `unsynced_count` grows and the
    // shared `sync` prop churns on every response after it. This device has no
    // credential, so the state stays `offline` and the backlog can never clear.
    await addToCart(page, 'E2E Smoke Coffee');
    await page.locator('#cash-received').fill('100');
    await page.getByRole('button', { name: /Checkout ·/ }).click();
    await expect(toast(page)).toContainText(/completed/i);

    await follow(page, 'Inventory', '/inventory');
    await follow(page, 'Sales', '/sales');
    await follow(page, 'Reports', '/reports');
    await follow(page, 'Point of Sale', '/pos');

    // The suppression rule, which is the difference between a listener that
    // fires on new work and one that fires on every page opened for something
    // only a person can fix.
    expect(cycles()).toBe(1);
});

/**
 * NOT COVERED HERE, on purpose: the outbox listener's other half — a growing
 * backlog on a HEALTHY branch running one cycle per new write.
 *
 * It needs `sync.state === 'online'`, which only a real sealed credential and a
 * reachable business database produce. The only way to force it here would be to
 * intercept the Inertia partial reload and rewrite `props.sync`, which tests a
 * fabricated prop rather than the one the server sends — precisely the kind of
 * finding that passes against a stand-in while the shipped path is broken.
 *
 * So it stays verified where it is real: the two-branch rig (`composer rig`),
 * selling at one branch and watching the cycle land within seconds. Same split
 * `tests/Feature/Sync/Concerns/FakesTheBusinessDatabase.php` draws for the pull.
 */
