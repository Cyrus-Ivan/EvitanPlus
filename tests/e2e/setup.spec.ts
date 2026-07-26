import { expect, test } from '@playwright/test';

/**
 * The initial setup wizard (post-v1.0.0). A fresh install has no user at all,
 * so RequireInitialSetup funnels every request to /setup; one atomic submit
 * creates the Owner, saves business identity and localization, and signs in.
 *
 * Runs against its own user-less server (:8124, see playwright.config.ts) —
 * the wizard is unreachable the moment any user exists, so it cannot share the
 * seeded database the rest of the suite uses. The server rebuilds that
 * database on every run, which is what keeps this spec repeatable: it is
 * inherently one-shot against any given database.
 *
 * The backend contract is covered by tests/Feature/Setup/InitialSetupTest.php;
 * this pins the rendered four-step flow and the state it leaves behind.
 */

const OWNER = {
    name: 'Maria Cruz',
    username: 'maria',
    password: 'a-password-of-my-own',
    business: 'Cruz Sari-Sari Store',
};

test('a fresh install is funnelled to the wizard and set up in one pass', async ({ page }) => {
    // Any entry point lands on the wizard, not on login.
    await page.goto('/');
    await page.waitForURL('**/setup');

    await expect(page.getByRole('heading', { name: 'Welcome to Evitan Plus' })).toBeVisible();

    // All four steps are advertised up front, current step marked.
    const steps = page.getByRole('list', { name: 'Setup progress' });
    for (const label of ['Owner account', 'Business details', 'Localization', 'Printer']) {
        await expect(steps.getByText(label, { exact: true })).toBeVisible();
    }

    // Step 1 — the Owner account.
    await page.locator('#name').fill(OWNER.name);
    await page.locator('#username').fill(OWNER.username);
    await page.locator('#password').fill(OWNER.password);
    await page.locator('#password_confirmation').fill(OWNER.password);
    await page.getByRole('button', { name: 'Next' }).click();

    // Step 2 — business identity.
    await page.locator('#business_name').fill(OWNER.business);
    await page.getByRole('button', { name: 'Next' }).click();

    // Step 3 — localization, pre-filled with the seeded defaults.
    await expect(page.locator('#currency_symbol')).toHaveValue('₱');
    await page.getByRole('button', { name: 'Next' }).click();

    // Step 4 — the printer is optional; skipping must still complete setup.
    await expect(page.getByRole('button', { name: 'Finish setup' })).toBeVisible();
    await page.getByRole('button', { name: 'Skip for now' }).click();

    // One submit: account created, settings saved, and already signed in.
    await page.waitForURL('**/pos');
    await expect(page.getByPlaceholder(/^Search or scan/)).toBeVisible();
    await expect(page.getByRole('button', { name: new RegExp(OWNER.name) })).toBeVisible();

    // The business identity from step 2 was saved by that same submit.
    await page.goto('/business-settings');
    await expect(page.locator('#business_name')).toHaveValue(OWNER.business);

    // An Owner exists now, so the wizard is closed forever.
    await page.goto('/setup');
    await expect(page).not.toHaveURL(/\/setup$/);
});
