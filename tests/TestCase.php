<?php

namespace Tests;

use App\Exceptions\OfflineWriteException;
use App\Models\Instance;
use App\Support\Sync\ConnectivityProbe;
use App\Support\Sync\OnlineWrite;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Set this false in a test that needs a device which has never been set up.
     * The setup wizard's own tests are the only ones that should.
     */
    protected bool $hydrated = true;

    /**
     * What the connectivity probe answers, or null to use the real one.
     *
     * Since M6 some screens are online-only (CSV import, §17), so a suite with
     * no answer here would test them all in their refusing state. Default true:
     * a hydrated device with a working connection is the case nearly every test
     * means. Set false to test a refusal, null when the probe itself is what is
     * under test.
     */
    protected ?bool $connected = true;

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

        if ($this->connected !== null) {
            $this->app->instance(ConnectivityProbe::class, new class($this->connected) extends ConnectivityProbe
            {
                public function __construct(private readonly bool $answer) {}

                public function online(): bool
                {
                    return $this->answer;
                }

                public function reachable(array $connection): bool
                {
                    return $this->answer;
                }
            });

            // Shared-data writes go to Postgres AND SQLite as one act (M6.5).
            // There is no Postgres here, so the online half is stood down and
            // the local half runs exactly as it always did — which is what the
            // several hundred tests written before M6.5 are actually about.
            //
            // The real two-database behaviour cannot be faked honestly and is
            // not faked: OnlineWriteTest sets $connected = null to exercise this
            // class for real, and tests/Integration/OnlineWriteTest.php proves
            // the round trip against a provisioned business.
            $this->app->instance(OnlineWrite::class, new class($this->connected) extends OnlineWrite
            {
                public function __construct(private readonly bool $answer) {}

                public function run(Closure $work, string $subject = 'This'): mixed
                {
                    if (! $this->answer) {
                        throw OfflineWriteException::forSubject($subject);
                    }

                    return DB::transaction($work);
                }

                public function mirror(Model ...$models): void
                {
                    //
                }

                public function afterMirroring(Closure $work): void
                {
                    // Nothing has been mirrored, so nothing follows it. The
                    // work this drops is `RemoteStock::recompute`, which has no
                    // meaning without a Postgres to recompute in.
                }

                public function possible(): bool
                {
                    return $this->answer;
                }
            });
        }

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
