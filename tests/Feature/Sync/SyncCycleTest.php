<?php

namespace Tests\Feature\Sync;

use App\Exceptions\SetupFailedException;
use App\Exceptions\VersionBlockedException;
use App\Models\InstanceSecret;
use App\Models\SyncOutboxEntry;
use App\Models\SyncStatus;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use App\Support\Sync\BranchCycle;
use App\Support\Sync\CredentialVault;
use App\Support\Sync\SyncCycle;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDOException;
use Tests\TestCase;

/**
 * What a cycle records, and — more importantly — what it never does.
 *
 * A cycle is the only part of the till that touches a network, so it is the code
 * most able to break the offline guarantee. Every test here is really one
 * assertion: whatever went wrong, the sale still went through and the app still
 * answered.
 *
 * The four connection states are exercised through a stubbed {@see BranchCycle}.
 * Three of them cannot be produced from a real connection without a real server
 * to refuse us in a specific way, and the thing under test is the CLASSIFICATION
 * — whether a revoked credential is reported as revoked rather than as a network
 * fault the cashier should wait out. The genuine round trip is
 * tests/Integration/PushTest.php.
 */
class SyncCycleTest extends TestCase
{
    use RefreshDatabase;

    /** Connectivity is driven through Http::fake below, not stubbed away. */
    protected ?bool $connected = null;

    protected function setUp(): void
    {
        parent::setUp();

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

        // The probe answers yes unless a test says otherwise, so a test about
        // classification is not silently a test about connectivity.
        Http::fake(['*' => Http::response('', 401)]);
    }

    /** Replace the connection-owning cycle with one that does $behaviour. */
    private function stubCycle(Closure $behaviour): void
    {
        $this->app->instance(BranchCycle::class, new class($behaviour) extends BranchCycle
        {
            public function __construct(private readonly Closure $behaviour) {}

            public function run(array $credentials, Closure $work): mixed
            {
                return ($this->behaviour)($work);
            }
        });
    }

    public function test_a_successful_cycle_is_recorded_as_online(): void
    {
        $this->stubCycle(fn () => ['pushed' => 3, 'pulled' => 7]);

        $result = app(SyncCycle::class)->run();

        $this->assertSame(SyncStatus::ONLINE, $result['state']);
        $this->assertSame(3, $result['pushed']);
        $this->assertSame(7, $result['pulled']);

        $status = SyncStatus::current();
        $this->assertNotNull($status->last_sync_at);
        $this->assertNull($status->last_error);
        $this->assertNull($status->blocked_since);
    }

    public function test_a_failed_probe_never_opens_postgres(): void
    {
        Http::fake(fn () => throw new ConnectionException('no route to host'));

        $this->stubCycle(fn () => $this->fail('The cycle must not connect when the probe says offline.'));

        $this->assertSame(SyncStatus::OFFLINE, app(SyncCycle::class)->run()['state']);
        $this->assertNotNull(SyncStatus::current()->last_probe_at);
    }

    public function test_a_version_block_is_not_reported_as_a_network_fault(): void
    {
        $this->stubCycle(fn () => throw new VersionBlockedException('1.0.0', '2.0.0'));

        $result = app(SyncCycle::class)->run();

        // Waiting fixes nothing here; installing a newer build does. Reporting
        // this as `offline` would tell the store the opposite.
        $this->assertSame(SyncStatus::VERSION_BLOCKED, $result['state']);
        $this->assertSame(SyncStatus::KIND_VERSION, SyncStatus::current()->last_error_kind);
    }

    public function test_a_revoked_credential_is_reported_as_unauthorized(): void
    {
        $this->stubCycle(fn () => throw new SetupFailedException(
            'Could not connect to the business database: SQLSTATE[28P01] password authentication failed',
            previous: new PDOException('SQLSTATE[28P01] password authentication failed for user "branch_9f2c"'),
        ));

        $result = app(SyncCycle::class)->run();

        $this->assertSame(SyncStatus::UNAUTHORIZED, $result['state']);
        $this->assertSame(SyncStatus::KIND_AUTH, SyncStatus::current()->last_error_kind);
    }

    public function test_a_disabled_role_is_reported_as_unauthorized(): void
    {
        // `branch:disable` revokes LOGIN. The wording differs from a rotation,
        // and both must land on the same state.
        $this->stubCycle(fn () => throw new SetupFailedException(
            'Could not connect to the business database: role "branch_9f2c" is not permitted to log in',
        ));

        $this->assertSame(SyncStatus::UNAUTHORIZED, app(SyncCycle::class)->run()['state']);
    }

    /**
     * The fourth error kind, which M6 named in the spec and did not implement.
     *
     * A push that fails on the DATA — a duplicate serial, a constraint the rows
     * violate — used to fall into the catch-all and be reported as `offline`.
     * That is the worst available answer: staff wait for a connection they
     * already have, the backlog grows, and nothing on screen suggests calling
     * anyone.
     */
    public function test_a_failure_after_connecting_is_a_conflict_not_a_network_fault(): void
    {
        // SQLSTATE class 23: an integrity constraint refused the rows. Only a
        // query that actually ran can produce one, which is what makes it the
        // signal that we got there.
        $this->stubCycle(fn () => throw new PDOException(
            'SQLSTATE[23505]: Unique violation: duplicate key value violates unique constraint "transactions_branch_number_unique"'
        ));

        $result = app(SyncCycle::class)->run();

        // ONLINE, because it is: the branch reached Postgres and was refused.
        $this->assertSame(SyncStatus::ONLINE, $result['state']);
        $this->assertSame(SyncStatus::KIND_CONFLICT, SyncStatus::current()->last_error_kind);

        // And it is a blocked state despite reading `online`, so it still has a
        // beginning — otherwise the one failure that never resolves itself
        // would be the one with no "since when".
        $this->assertNotNull(SyncStatus::current()->blocked_since);

        // Not counted as a successful sync.
        $this->assertNull(SyncStatus::current()->last_sync_at);
    }

    public function test_a_missing_grant_is_a_conflict_too(): void
    {
        // 42501, insufficient_privilege — what a branch role looks like when a
        // migration added a table and nobody re-applied the matrix. The fix is
        // `schema:reapply-grants`, and nobody would think to run it while the
        // till insists the internet is down.
        $this->stubCycle(fn () => throw new PDOException(
            'SQLSTATE[42501]: Insufficient privilege: 7 ERROR: permission denied for table categories'
        ));

        $this->assertSame(SyncStatus::ONLINE, app(SyncCycle::class)->run()['state']);
        $this->assertSame(SyncStatus::KIND_CONFLICT, SyncStatus::current()->last_error_kind);
    }

    public function test_a_dropped_connection_is_still_offline_not_a_conflict(): void
    {
        // SQLSTATE class 08 is a connection exception: the query never
        // completed, so nothing is known about the data.
        $this->stubCycle(fn () => throw new PDOException(
            'SQLSTATE[08006]: Connection failure: server closed the connection unexpectedly'
        ));

        $this->assertSame(SyncStatus::OFFLINE, app(SyncCycle::class)->run()['state']);
        $this->assertSame(SyncStatus::KIND_NETWORK, SyncStatus::current()->last_error_kind);
    }

    public function test_an_ordinary_connection_failure_is_offline(): void
    {
        $this->stubCycle(fn () => throw new SetupFailedException(
            'Could not connect to the business database: SQLSTATE[08006] connection timed out',
        ));

        $result = app(SyncCycle::class)->run();

        $this->assertSame(SyncStatus::OFFLINE, $result['state']);
        $this->assertSame(SyncStatus::KIND_NETWORK, SyncStatus::current()->last_error_kind);
    }

    public function test_blocked_since_holds_across_repeated_failures(): void
    {
        $this->stubCycle(fn () => throw new SetupFailedException('connection timed out'));

        app(SyncCycle::class)->run();
        $first = SyncStatus::current()->blocked_since;

        $this->travel(5)->minutes();
        app(SyncCycle::class)->run();

        // "Offline since 9:14" is answerable without a history table only if the
        // stamp does not move on every retry.
        $this->assertNotNull($first);
        $this->assertTrue($first->equalTo(SyncStatus::current()->blocked_since));

        $this->travelBack();
    }

    public function test_a_device_with_no_credential_says_so(): void
    {
        InstanceSecret::query()->delete();

        $result = app(SyncCycle::class)->run();

        $this->assertSame(SyncStatus::OFFLINE, $result['state']);
        $this->assertStringContainsString('Run setup', (string) $result['error']);
    }

    public function test_a_failed_cycle_leaves_the_outbox_intact(): void
    {
        Transaction::factory()->create(['user_id' => User::factory()->cashier()->create()->id]);

        $before = SyncOutboxEntry::query()->whereNull('synced_at')->count();

        $this->stubCycle(fn () => throw new SetupFailedException('connection timed out'));

        $result = app(SyncCycle::class)->run();

        $this->assertSame($before, $result['unsynced_count']);
        $this->assertSame($before, SyncOutboxEntry::query()->whereNull('synced_at')->count());
    }

    public function test_the_route_answers_json_even_when_the_cycle_fails(): void
    {
        $this->stubCycle(fn () => throw new SetupFailedException('everything is on fire'));

        $this->actingAs(User::factory()->cashier()->create())
            ->postJson(route('sync'))
            ->assertOk()
            ->assertJsonPath('sync.state', SyncStatus::OFFLINE);
    }

    public function test_a_broken_sync_never_stops_a_sale(): void
    {
        $this->stubCycle(fn () => throw new SetupFailedException('everything is on fire'));

        $cashier = User::factory()->cashier()->create();
        $variant = Variant::factory()->create(['selling_price' => 10]);

        app(StockLedger::class)->record(
            $variant, 5, 'stock_adjustment', null, 'manual_adjustment', null, $cashier,
        );

        // The opportunistic sync is fired by the client AFTER this response, so
        // a cycle cannot be in the commit path at all. Asserted anyway, because
        // "the till never stops selling" is the invariant everything else here
        // is in service of.
        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'lines' => [['variant_id' => $variant->id, 'quantity' => 1]],
            'payment_type' => 'cashless',
        ])->assertSessionHasNoErrors();

        app(SyncCycle::class)->run();

        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_the_shared_prop_reports_the_backlog(): void
    {
        DB::table('sync_outbox')->truncate();

        Transaction::factory()->count(2)->create(['user_id' => User::factory()->cashier()->create()->id]);

        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('pos'))
            ->assertInertia(fn ($page) => $page
                ->where('sync.unsynced_count', 2)
                ->where('sync.state', SyncStatus::OFFLINE));
    }
}
