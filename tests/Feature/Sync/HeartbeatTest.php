<?php

namespace Tests\Feature\Sync;

use App\Exceptions\VersionBlockedException;
use App\Models\Instance;
use App\Models\InstanceSecret;
use App\Models\SyncOutboxEntry;
use App\Models\SyncStatus;
use App\Support\Sync\BranchCycle;
use App\Support\Sync\CredentialVault;
use App\Support\Sync\Heartbeat;
use App\Support\Sync\Puller;
use App\Support\Sync\Pusher;
use App\Support\Sync\StockReconciler;
use App\Support\Sync\SyncCycle;
use App\Support\Sync\VersionGate;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
use Tests\Feature\Sync\Concerns\FakesTheBusinessDatabase;
use Tests\TestCase;

/**
 * Step 4: what this branch tells the business about itself (§14, M10).
 *
 * Two things are being pinned here, and they fail in different ways:
 *
 *  - **The row's content.** A heartbeat is the only route by which a device's
 *    app version reaches Postgres, so a monitor built on a row that quietly
 *    stops carrying it looks healthy while answering the one question it exists
 *    for ("which tills are frozen?") with a blank.
 *  - **Its placement.** It must be written on the failure paths too, and it must
 *    never be the reason a cycle fails. A heartbeat that only beats when
 *    everything already worked reports exactly the branches nobody needs to
 *    hear from.
 *
 * The genuine round trip — real `timestamptz`, the real grants — is
 * tests/Integration/PushTest.php. Treat this file as the semantics.
 */
class HeartbeatTest extends TestCase
{
    use FakesTheBusinessDatabase;
    use RefreshDatabase;

    private const BRANCH = '00000000-0000-0000-0000-0000000b4a4c';

    private Connection $remote;

    protected function setUp(): void
    {
        parent::setUp();

        $this->remote = $this->businessDatabase(['branch_status']);

        // Tests\TestCase already seeds this device's identity, and self::BRANCH
        // is the branch id it seeds. Only the recorded schema version matters
        // here: the cycle refreshes it from `schema_meta` at step 0, and the
        // heartbeat is what carries it back up.
        Instance::query()->where('id', Instance::ID)->update(['schema_version' => 20]);
    }

    public function test_it_reports_the_versions_and_the_backlog_this_device_alone_knows(): void
    {
        SyncOutboxEntry::query()->create([
            'id' => (string) Str::uuid(),
            'table_name' => 'transactions',
            'row_id' => (string) Str::uuid(),
            'group_id' => (string) Str::uuid(),
        ]);

        $this->beat(SyncStatus::ONLINE, null, synced: true);

        $row = $this->row();

        $this->assertSame(config('nativephp.version'), $row->app_version);
        $this->assertSame(20, (int) $row->schema_version);
        $this->assertSame(1, (int) $row->unsynced_count);
        $this->assertSame(SyncStatus::ONLINE, $row->connection_state);
        $this->assertNull($row->last_error);
        $this->assertNotNull($row->last_sync_at);
        $this->assertNotNull($row->last_seen_at);
    }

    /** One row per branch: a heartbeat is current state, not a history. */
    public function test_a_second_beat_updates_the_same_row(): void
    {
        $this->beat(SyncStatus::ONLINE, null, synced: true);
        $this->beat(SyncStatus::ONLINE, null, synced: true);

        $this->assertSame(1, $this->remote->table('branch_status')->count());
    }

    /**
     * The two timestamps are not the same fact.
     *
     * A branch that reached Postgres and was then refused has been SEEN but has
     * not SYNCED, and overwriting last_sync_at here would erase the one figure
     * that says how long it has been stuck — which is the whole question the
     * monitor is asked.
     */
    public function test_a_failed_cycle_is_seen_but_does_not_count_as_a_sync(): void
    {
        $this->beat(SyncStatus::ONLINE, null, synced: true);

        $synced = $this->row()->last_sync_at;

        $this->beat(SyncStatus::VERSION_BLOCKED, 'This build is too old for the business database.', synced: false);

        $row = $this->row();

        $this->assertSame($synced, $row->last_sync_at);
        $this->assertSame(SyncStatus::VERSION_BLOCKED, $row->connection_state);
        $this->assertStringContainsString('too old', (string) $row->last_error);

        // Seen is never behind synced. Not asserted as "newer": SQLite's
        // CURRENT_TIMESTAMP has one-second resolution, so two beats in one test
        // land on the same string, and pinning a strict inequality here would
        // be pinning the stand-in's clock rather than the rule.
        $this->assertGreaterThanOrEqual((string) $synced, (string) $row->last_seen_at);
    }

    /** A branch that has never synced has no row at all — not a zeroed one. */
    public function test_a_device_that_never_ran_a_cycle_writes_nothing(): void
    {
        $this->assertSame(0, $this->remote->table('branch_status')->count());
    }

    public function test_a_finished_cycle_beats_before_the_connection_closes(): void
    {
        $this->stubWorkers();

        $this->assertSame(SyncStatus::ONLINE, app(SyncCycle::class)->run()['state']);

        $this->assertSame(SyncStatus::ONLINE, $this->row()->connection_state);
        $this->assertNotNull($this->row()->last_sync_at);
    }

    /**
     * The beat this phase exists for.
     *
     * A branch frozen by the version gate is invisible to the console unless it
     * says so itself: `schema:migrate` knows which BUILDS a raised floor froze,
     * and only `branch_status` knows which tills are on them.
     */
    public function test_a_version_blocked_cycle_still_reports_itself(): void
    {
        $this->stubWorkers(fn () => throw new VersionBlockedException('1.0.0', '2.0.0'));

        $this->assertSame(SyncStatus::VERSION_BLOCKED, app(SyncCycle::class)->run()['state']);

        $row = $this->row();

        $this->assertSame(SyncStatus::VERSION_BLOCKED, $row->connection_state);
        $this->assertNull($row->last_sync_at);
        $this->assertStringContainsString('2.0.0', (string) $row->last_error);
    }

    /**
     * A report about a cycle may never become the cycle's outcome — the same
     * rule that keeps a cycle from stopping a sale, one level in.
     */
    public function test_a_heartbeat_that_fails_changes_nothing(): void
    {
        $this->stubWorkers();

        $this->app->instance(Heartbeat::class, new class extends Heartbeat
        {
            public function __construct() {}

            public function upsert(Connection $remote, string $branchId, string $state, ?string $error, bool $synced): void
            {
                throw new RuntimeException('permission denied for table branch_status');
            }
        });

        $result = app(SyncCycle::class)->run();

        $this->assertSame(SyncStatus::ONLINE, $result['state']);
        $this->assertNull(SyncStatus::current()->last_error);
        $this->assertNotNull(SyncStatus::current()->last_sync_at);
    }

    private function beat(string $state, ?string $error, bool $synced): void
    {
        app(Heartbeat::class)->upsert($this->remote, self::BRANCH, $state, $error, $synced);
    }

    private function row(): stdClass
    {
        $row = $this->remote->table('branch_status')->where('branch_id', self::BRANCH)->first();

        $this->assertNotNull($row, 'No heartbeat was written for this branch.');

        return $row;
    }

    /**
     * Run a real cycle over the stand-in with every step but the heartbeat
     * stubbed out.
     *
     * The steps themselves have their own tests; what is under test here is that
     * step 4 happens at all, on both ways out of the closure — and that means
     * exercising SyncCycle's real control flow rather than asserting on a mock
     * of it.
     *
     * @param  Closure|null  $gate  What the version gate does, if not "pass".
     */
    private function stubWorkers(?Closure $gate = null): void
    {
        InstanceSecret::query()->create([
            'id' => InstanceSecret::ID,
            'dsn_ciphertext' => app(CredentialVault::class)->seal([
                'driver' => 'pgsql',
                'host' => 'aws-0-ap-southeast-1.pooler.supabase.com',
                'port' => 6543,
                'database' => 'postgres',
                'username' => 'branch_9f2c.abcdefghijklmnop',
                'password' => 'secret',
                'search_path' => null,
            ]),
            'fingerprint' => 'sha256:test',
            'imported_at' => now(),
        ]);

        Http::fake(['*' => Http::response('', 401)]);

        $remote = $this->remote;

        // The connection the cycle would open is the stand-in, so the closure
        // runs for real against it.
        $this->app->instance(BranchCycle::class, new class($remote) extends BranchCycle
        {
            public function __construct(private readonly Connection $remote) {}

            public function run(array $credentials, Closure $work): mixed
            {
                return $work($this->remote);
            }
        });

        $this->app->instance(VersionGate::class, new class($gate) extends VersionGate
        {
            public function __construct(private readonly ?Closure $gate) {}

            public function read(Connection $remote): array
            {
                return ['schema_version' => 20, 'min_app_version' => '1.0.0'];
            }

            public function assert(string $minAppVersion): void
            {
                if ($this->gate !== null) {
                    ($this->gate)();
                }
            }
        });

        $this->app->instance(Pusher::class, new class extends Pusher
        {
            public function __construct() {}

            public function push(Connection $remote): array
            {
                return ['rows' => 0, 'groups' => 0];
            }
        });

        $this->app->instance(Puller::class, new class extends Puller
        {
            public function __construct() {}

            public function pull(Connection $remote, string $branchId): array
            {
                return [];
            }
        });

        $this->app->instance(StockReconciler::class, new class extends StockReconciler
        {
            public function __construct() {}

            public function reconcile(Connection $remote, string $branchId): int
            {
                return 0;
            }
        });
    }
}
