import { expect, test } from '@playwright/test';
import { login } from './helpers';

/**
 * S7 role gating in the DOM: cost never appears for Cashier/Admin, the nav
 * hides forbidden pages, direct URLs render the styled 403/404 error page.
 * (Server-side enforcement is backend-tested; this pins the rendered surface.)
 */

async function openLatestSale(page: import('@playwright/test').Page): Promise<void> {
    await page.goto('/sales');
    await page.getByRole('row').nth(1).click();
    await page.waitForURL(/\/sales\/[0-9a-f-]+$/);
    await expect(page.getByRole('columnheader', { name: 'Unit Price' }).first()).toBeVisible();
}

test('cashier: no cost anywhere, forbidden nav hidden, 403 and 404 pages render', async ({ page }) => {
    await login(page, 'cashier');

    // Nav hides Admin/Owner surfaces.
    for (const name of ['Reports', 'Audit Logs', 'User Management']) {
        await expect(page.getByRole('link', { name })).toHaveCount(0);
    }

    // Sales detail renders prices but never cost.
    await openLatestSale(page);
    await expect(page.getByRole('columnheader', { name: 'Unit Cost' })).toHaveCount(0);

    // Direct URL to a forbidden page → styled 403.
    await page.goto('/reports');
    await expect(page.getByText('Access denied')).toBeVisible();

    // Unknown URL → styled 404.
    await page.goto('/definitely-missing');
    await expect(page.getByText('Page not found')).toBeVisible();
});

test('admin: no cost or profit surfaces, Owner pages 403', async ({ page }) => {
    await login(page, 'admin');

    // Reports render, but without the Owner-only profit surfaces.
    await page.goto('/reports');
    await expect(page.locator('div.uppercase').filter({ hasText: /^Gross sales$/ })).toBeVisible();
    await expect(page.locator('div.uppercase').filter({ hasText: /^Gross profit$/ })).toHaveCount(0);
    await expect(page.getByRole('columnheader', { name: 'Profit' })).toHaveCount(0);

    // Sales detail: no cost column.
    await openLatestSale(page);
    await expect(page.getByRole('columnheader', { name: 'Unit Cost' })).toHaveCount(0);

    // Owner-only page → styled 403.
    await page.goto('/business-settings');
    await expect(page.getByText('Access denied')).toBeVisible();
});

test('owner: cost and profit surfaces render', async ({ page }) => {
    await login(page, 'owner');

    await page.goto('/reports');
    await expect(page.locator('div.uppercase').filter({ hasText: /^Gross profit$/ })).toBeVisible();

    await openLatestSale(page);
    await expect(page.getByRole('columnheader', { name: 'Unit Cost' }).first()).toBeVisible();
});
