<?php

namespace App\Support\Boot;

use Illuminate\Support\Facades\Artisan;

/**
 * Once-per-launch database provisioning for the native app (Phase 19).
 *
 * The NativePHP runtime only migrates when the app version changes, which
 * misses two cases this covers by always migrating: a same-version relaunch
 * after a database restore, and a restored backup that predates newer
 * migrations (e.g. the Phase 18 immutability triggers). A fresh install is
 * not seeded here — it has no branch identity, so RequireInitialSetup routes
 * its first page load to the setup wizard, which imports a branch credential
 * and hydrates users, catalog and settings from Postgres (M5). This is also
 * what satisfies that wizard's "run the local migrations" step: by the time it
 * renders, they have run. The closing reconcile sweep rebuilds stock snapshots
 * from the ledger, which finishes a restore (F-11) and is a cheap no-op
 * otherwise.
 *
 * Runs inside the /_native/api/booted request before the window opens, so all
 * of this completes before the first page load touches the database.
 */
class LaunchProvisioner
{
    public function run(): void
    {
        Artisan::call('migrate', ['--force' => true]);

        Artisan::call('inventory:reconcile');
    }
}
