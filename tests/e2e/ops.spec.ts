import { expect, test } from '@playwright/test';
import { login, toast } from './helpers';

/**
 * S7 ops surfaces: receipt reprint. The print-failure backend path is
 * unit-tested — here we assert the UI flow surfaces a toast (success,
 * no-printer, or device failure all toast; none mutate the sale).
 */

test('reprint from the sales detail surfaces a print toast', async ({ page }) => {
    await login(page, 'cashier');

    await page.goto('/sales');
    await page.getByRole('row').nth(1).click();
    await page.waitForURL(/\/sales\/[0-9a-f-]+$/);

    await page.getByRole('button', { name: 'Print receipt' }).click();

    // Printer state in the isolated DB copy decides the exact message
    // (configured → "sent to the printer" / failure, unconfigured → "No
    // receipt printer is configured"); all of them mention print.
    await expect(toast(page)).toContainText(/print/i);
});
