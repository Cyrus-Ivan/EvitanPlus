import { expect, test } from '@playwright/test';
import { login } from './helpers';

/**
 * Phase 18 addendum: nav smoke. Every page the sidebar and user menu link to
 * boots and renders its own document title through the built manifest — this
 * catches pages that only break outside the dev server. POS, Sales, Reports,
 * and Backup already have deep flows in the other specs.
 */

const DESTINATIONS: Array<[path: string, title: RegExp]> = [
    ['/inventory', /^Inventory -/],
    ['/inventory/categories', /^Categories -/],
    ['/inventory/stock-history', /^Stock History -/],
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
