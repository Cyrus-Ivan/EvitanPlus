import { expect, test } from '@playwright/test';

/**
 * The setup wizard (M5). A device that has never been hydrated has no `instance`
 * row, so RequireInitialSetup funnels every request to /setup, where one submit
 * imports a branch credential, connects to the business database, and copies the
 * baseline down.
 *
 * Runs against its own un-hydrated server (:8124, see playwright.config.ts) —
 * the wizard is unreachable the moment a device is set up, so it cannot share
 * the seeded database the rest of the suite uses.
 *
 * SCOPE, and it is deliberately narrow: this spec drives everything up to the
 * connection, because a successful import needs a real provisioned business in
 * Supabase and `composer verify` must stay runnable on any machine. The
 * connect-and-hydrate half is covered end to end against the real project by
 * tests/Integration/HydrationTest.php (`composer verify:hydrate`).
 */

test('an un-hydrated device is funnelled to the wizard and refuses a file that is not a credential', async ({ page }) => {
    // Any entry point lands on the wizard, not on login.
    await page.goto('/');
    await page.waitForURL('**/setup');

    await expect(page.getByRole('heading', { name: 'Set up this branch' })).toBeVisible();

    // Both steps are advertised up front, current step marked.
    const steps = page.getByRole('list', { name: 'Setup progress' });
    for (const label of ['Branch credentials', 'Printer']) {
        await expect(steps.getByText(label, { exact: true })).toBeVisible();
    }

    // Step 1 — the credentials file, defaulting to removing it after import.
    await expect(page.locator('#delete_after_import')).toBeChecked();
    await page.locator('#credentials_path').fill('/tmp/not-a-real-credentials-file.json');

    // Step 2 — the printer, the one thing the wizard still collects itself
    // because it belongs to this device rather than to the business.
    await page.getByRole('button', { name: 'Next' }).click();
    await expect(page.getByRole('button', { name: 'Connect and set up' })).toBeVisible();

    // Skipping the printer still submits, and the import is refused by name.
    await page.getByRole('button', { name: 'Skip for now' }).click();

    await expect(page.getByText(/No file found at/)).toBeVisible();

    // Refused all the way: still on the wizard, still un-hydrated, so every
    // other route still redirects here.
    await expect(page).toHaveURL(/\/setup$/);
    await page.goto('/pos');
    await page.waitForURL('**/setup');
});
