import { defineConfig, devices } from '@playwright/test';
import path from 'path';

/**
 * E2E suite (Phase 18 S6/S7, extended post-v1.0.0 for the setup wizard).
 *
 * Isolation, per the proven verify-skill recipe:
 * - NEVER the real database: each server BUILDS its own SQLite file under
 *   tests/e2e/.runtime from migrations + seeders. Copying the dev database is
 *   what silently broke this suite — its contents are whatever the developer
 *   last did, and it need not contain an Owner at all.
 * - `php -S` from public/ with the framework router — NOT `artisan serve`,
 *   whose child process drops the DB_DATABASE/APP_DEBUG overrides.
 * - `npm run e2e` runs `vite build` BEFORE Playwright: the servers render
 *   through the built manifest, and building once up front keeps the two
 *   servers from racing on public/build.
 *
 * Two servers, because the setup wizard needs an un-hydrated device
 * (RequireInitialSetup redirects /setup → /login the moment `instance` says the
 * device is a branch), which is irreconcilable with the seeded database every
 * other spec needs.
 */

const serve = path.join(import.meta.dirname, 'tests/e2e/serve.sh');

export default defineConfig({
    testDir: 'tests/e2e',
    // One worker against shared servers + databases: specs mutate state
    // (checkouts) and the app enforces single-session semantics.
    workers: 1,
    fullyParallel: false,
    timeout: 30_000,
    use: {
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            testIgnore: /setup\.spec\.ts/,
            use: { ...devices['Desktop Chrome'], baseURL: 'http://127.0.0.1:8123' },
        },
        {
            name: 'setup',
            testMatch: /setup\.spec\.ts/,
            use: { ...devices['Desktop Chrome'], baseURL: 'http://127.0.0.1:8124' },
        },
    ],
    webServer: [
        {
            // Seeded: settings + demo catalog/sales + the per-role e2e logins.
            command: `${serve} 8123 e2e.sqlite full`,
            url: 'http://127.0.0.1:8123/login',
            reuseExistingServer: true,
            timeout: 180_000,
        },
        {
            // Un-hydrated: the app funnels to /setup. A successful import would
            // make the wizard unreachable, so this server must never be reused —
            // its database has to be rebuilt each run.
            command: `${serve} 8124 setup.sqlite fresh`,
            url: 'http://127.0.0.1:8124/setup',
            reuseExistingServer: false,
            timeout: 180_000,
        },
    ],
});
