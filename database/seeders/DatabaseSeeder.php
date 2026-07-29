<?php

namespace Database\Seeders;

use App\Models\Instance;
use App\Models\Setting;
use App\Support\Settings\BusinessSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Settings defaults only. No user and no branch identity are seeded: a fresh
     * install is routed to the setup wizard (RequireInitialSetup), which imports
     * a branch credential and hydrates everything else from Postgres. Idempotent
     * — safe to re-run.
     *
     * Local runs also get DemoDataSeeder (demo accounts incl. the dev login
     * owner/password, catalog, stock, sales history) so every screen has
     * something to show during development, plus the fake branch identity below
     * that keeps those screens reachable. Elsewhere run it explicitly:
     * `php artisan db:seed --class=DemoDataSeeder`.
     */
    public function run(): void
    {
        // Business-identity defaults (Phase 4) so the first run isn't blank.
        foreach (BusinessSettings::DEFAULTS as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }

        if (app()->environment('local')) {
            $this->seedDevInstance();

            $this->call(DemoDataSeeder::class);

            // The demo sales enqueued themselves in the outbox on the way in,
            // which is the enqueue trait working correctly and useless here:
            // the dev identity has no credential, so none of it can ever push.
            // Clearing it means the sync indicator reads "Synced" on a dev
            // machine instead of showing a four-figure backlog that will never
            // move — a number a developer would learn to ignore, which is the
            // last thing this indicator should teach anyone.
            DB::table('sync_outbox')->truncate();
        }
    }

    /**
     * A dev device that pretends to have been hydrated.
     *
     * The setup gate is `instance.hydrated_at` (M5), so without this row a
     * development database full of demo data would still funnel every request to
     * the wizard and the `owner`/`password` login would be unreachable.
     *
     * LOCAL ONLY, and that is load-bearing twice over: a packaged install must
     * genuinely go through the wizard, and `tests/e2e/serve.sh fresh` seeds under
     * a non-local APP_ENV precisely so the wizard spec meets an un-hydrated
     * device.
     *
     * There is no `instance_secret` to go with it. A fake identity with no
     * credential is honest about what this is — nothing here can sync, and from
     * M6 on a developer wanting that will import a real credentials JSON.
     */
    private function seedDevInstance(): void
    {
        Instance::firstOrCreate(['id' => Instance::ID], [
            'business_id' => (string) Str::uuid(),
            'schema_name' => 'biz_development',
            'branch_id' => (string) Str::uuid(),
            'branch_code' => 'DEV',
            'branch_name' => 'Development Branch',
            'schema_version' => 1,
            'min_app_version' => '1.0.0',
            'hydrated_at' => now(),
        ]);
    }
}
