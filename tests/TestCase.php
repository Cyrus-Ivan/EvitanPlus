<?php

namespace Tests;

use App\Models\Instance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Set this false in a test that needs a device which has never been set up.
     * The setup wizard's own tests are the only ones that should.
     */
    protected bool $hydrated = true;

    /**
     * Give every test a device that is already a branch.
     *
     * Since M5 the fresh-install gate is `instance.hydrated_at` rather than "no
     * user exists" (RequireInitialSetup), so without this every feature test
     * would be funnelled to /setup no matter how many users it created. Seeding
     * the row here rather than in each test keeps a change to the gate from
     * spreading into four hundred unrelated tests.
     *
     * Conditioned on RefreshDatabase rather than hung off its
     * afterRefreshingDatabase() hook: a trait's method wins over an inherited
     * one, so the trait's own empty version of that hook would silently replace
     * anything written here — a failure that presents as "the gate ignores the
     * instance row". By this point parent::setUp() has run the trait's setup, so
     * the schema exists and the per-test transaction has begun, which is what
     * makes the row disappear again after each test.
     *
     * Tests that never touch the database (the build-exclusion checks, the
     * shipped-connection defaults) do not use the trait and must not have a
     * database implied for them.
     *
     * The identity is obvious nonsense on purpose — a test asserting anything
     * about a real branch identity should be writing its own row.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->hydrated || ! in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            return;
        }

        Instance::query()->create([
            'id' => Instance::ID,
            'business_id' => '00000000-0000-0000-0000-00000000b12b',
            'schema_name' => 'biz_test',
            'branch_id' => '00000000-0000-0000-0000-0000000b4a4c',
            'branch_code' => 'TST',
            'branch_name' => 'Test Branch',
            'schema_version' => 1,
            'min_app_version' => '1.0.0',
            'hydrated_at' => now(),
        ]);
    }
}
