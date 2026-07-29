<?php

/**
 * Manual rig hydration — executed by tests/manual/rig.sh (via artisan tinker)
 * against ONE of the rig's isolated runtime databases, never the real one.
 *
 * This is the setup wizard's work without the wizard: SetupController::store
 * does nothing but wrap this same ConnectAndHydrate call in a transaction and
 * redirect. `rig ready` runs it so both tills come up usable; `rig wizard`
 * skips it so you can drive the real screen yourself.
 *
 * The credentials path arrives in RIG_CREDENTIALS because `tinker --execute`
 * takes no arguments of its own.
 *
 * Prints "manual rig hydrated <CODE>" ONLY after verifying its own work: tinker
 * exits 0 even when this file throws, so that marker is the only signal rig.sh
 * can trust. The check is on instance.hydrated_at rather than on reaching the
 * last line — that is the same thing RequireInitialSetup reads to decide this
 * device is set up, so if it is not set, nothing else here matters.
 */

use App\Models\Instance;
use App\Models\User;
use App\Models\Variant;
use App\Support\Sync\ConnectAndHydrate;
use App\Support\Sync\CredentialsDocument;

$path = (string) getenv('RIG_CREDENTIALS');

if ($path === '' || ! is_file($path)) {
    throw new RuntimeException("RIG_CREDENTIALS does not point at a file: [{$path}]");
}

$summary = app(ConnectAndHydrate::class)(CredentialsDocument::fromFile($path));

$checks = [
    'instance hydrated' => Instance::isHydrated(),
    'branch identity' => Instance::current()?->branch_code === $summary['branch_code'],
    'owner arrived' => User::query()->where('role', 'owner')->exists(),
    'catalog arrived' => Variant::query()->exists(),
    // The rig seeds different quantities per branch, so a till with catalog but
    // no stock means the branch filter or the seed step did not do its job.
    'stock arrived' => Variant::query()->whereHas('stockSnapshot')->exists(),
];

foreach ($checks as $label => $ok) {
    if (! $ok) {
        throw new RuntimeException("manual rig hydrate check failed: {$label}");
    }
}

echo "manual rig hydrated {$summary['branch_code']}\n";
