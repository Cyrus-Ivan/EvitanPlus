import { expect, test } from '@playwright/test';
import { login } from './helpers';

/**
 * Phase 18 addendum: nav smoke. Every page the sidebar and user menu link to
 * boots and renders its own document title through the built manifest — this
 * catches pages that only break outside the dev server. POS, Sales and Reports
 * already have deep flows in the other specs.
 *
 * It also carries the focused checks that belong to no feature spec: what the
 * app calls itself, and what a screen shows when its content needs a connection
 * this device does not have.
 */

const DESTINATIONS: Array<[path: string, title: RegExp]> = [
    ['/inventory', /^Inventory -/],
    ['/inventory/categories', /^Categories -/],
    ['/inventory/stock-history', /^Stock History -/],
    // Renders with nothing to show on this device — it has no branch credential,
    // so the online read cannot happen. That IS the case worth smoking: the page
    // must say so rather than fail.
    ['/inventory/stock-updates', /^Stock Updates -/],
    // The same case from the other direction (M11): this URL asks for figures
    // from every branch, which the device cannot fetch for want of a credential.
    // It must still render a correct report of what it CAN see, rather than
    // erroring or presenting one branch's numbers as the business's.
    ['/reports?scope=all', /^Reports -/],
    ['/login-history', /^Login History -/],
    ['/users', /^User Management -/],
    ['/audit-logs', /^Audit Logs -/],
    ['/business-settings', /^Business Settings -/],
    ['/settings/profile', /^Profile settings -/],
    ['/settings/password', /^Profile settings -/],
    ['/settings/appearance', /^Appearance settings -/],
    ['/settings/about', /^About -/],
];

test('every nav destination renders for the owner', async ({ page }) => {
    await login(page, 'owner');

    for (const [path, title] of DESTINATIONS) {
        await page.goto(path);
        await expect(page).toHaveTitle(title);
        // The error boundary renders in-page without changing the title.
        await expect(page.getByText('Something went wrong')).toBeHidden();
    }
});

test('the app identifies itself as Evitan Plus', async ({ page }) => {
    // The rename is a product decision, not a cosmetic one: About renders the
    // configured app name, and every page title is suffixed with it.
    await login(page, 'owner');
    await page.goto('/settings/about');

    // Scoped to About's identity block — the sidebar logo hardcodes the name,
    // but this one comes from config, which is what the rename actually moved.
    const productName = page.getByText('Offline-first retail point of sale').locator('xpath=preceding-sibling::p[1]');

    await expect(productName).toHaveText('Evitan Plus');
    await expect(page).toHaveTitle(/- Evitan Plus$/);
});

test('a consolidated report this device cannot fetch falls back and says so', async ({ page }) => {
    // The state a real shop meets whenever its internet drops, and the one the
    // M11 fallback exists for. This device is hydrated but holds no branch
    // credential, so the online read cannot happen — the same condition as
    // /inventory/stock-updates above.
    await login(page, 'owner');
    await page.goto('/reports?scope=all');

    // The notice is the assertion that matters. Without it the Owner is reading
    // one branch's figures believing they are the business's.
    await expect(page.getByRole('alert')).toContainText("These are this branch's figures only");

    // The server snapped the scope back to `local`; the control has to agree.
    // Still reading "All branches" over branch-local numbers is that same
    // mislabelling in its most convincing form. `local` names the branch it
    // actually is — the dev seed's DEV / Development Branch.
    await expect(page.getByLabel('Branches reported on')).toHaveText('DEV — Development Branch (this branch)');

    // And no per-branch table, because there are no other branches in these figures.
    await expect(page.getByRole('columnheader', { name: 'Branch' })).toBeHidden();
});

test('a lagging navigation shows the loading bar', async ({ page }) => {
    await login(page, 'owner');

    // The indicator is deliberately silent for the first 150ms and gone the
    // instant the page lands, so a normal navigation never shows it long enough
    // to observe. Holding the response open manufactures the one condition it
    // exists for — the lag that used to look like a frozen screen.
    await page.route('**/inventory', async (route) => {
        await new Promise((resolve) => setTimeout(resolve, 1500));
        await route.continue();
    });

    // A sidebar click, not page.goto: the bar rides Inertia's client-side visit.
    // It is also the case that used to fail — see the prefetch note in
    // `nav-main.tsx`, which is what this test is really guarding.
    await page.getByRole('link', { name: 'Inventory', exact: true }).click();

    // Inertia owns this id — the element is created by its progress component at
    // runtime, so a selector is the only handle. Same exception the suite
    // already makes for [data-sonner-toast].
    const bar = page.locator('#nprogress .bar');
    await expect(bar).toBeVisible();

    // Drawn along the top edge, and drawn at all: the failure this guards is a
    // prefetch hiding the element mid-navigation, which leaves it in the DOM
    // with an empty box rather than removing it.
    const box = (await bar.boundingBox())!;
    expect(box.y).toBeLessThan(4);
    expect(box.height).toBeGreaterThan(0);

    await page.waitForURL('**/inventory');
    await expect(bar).toBeHidden();
});
