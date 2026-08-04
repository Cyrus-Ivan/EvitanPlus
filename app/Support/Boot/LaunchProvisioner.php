<?php

namespace App\Support\Boot;

use Illuminate\Support\Facades\Artisan;

/**
 * Once-per-launch database provisioning for the native app (Phase 19).
 *
 * The NativePHP runtime only migrates when the app version changes, which
 * misses the case this covers by always migrating: a device whose database file
 * predates the build now running against it. A fresh install is not seeded here
 * — it has no branch identity, so RequireInitialSetup routes its first page load
 * to the setup wizard, which imports a branch credential and hydrates the
 * business baseline and this branch's history from Postgres (M5, M11.6). This is
 * also what satisfies that wizard's "run the local migrations" step: by the time
 * it renders, they have run.
 *
 * The closing reconcile sweep rebuilds stock snapshots from the ledger and is a
 * cheap no-op in normal operation. It is only safe on a hydrated device because
 * M11.6 brings the branch's ledger down with its snapshots; before that it would
 * have zeroed every variant here, on the first launch after setup. See
 * ReconcileInventory.
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
