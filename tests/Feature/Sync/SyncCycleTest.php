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
use App\Support\Sync\Heartbeat;
use App\Support\Sync\Puller;
use App\Support\Sync\Pusher;
use App\Support\Sync\StockReconciler;
use App\Support\Sync\SyncCycle;
use App\Support\Sync\VersionGate;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
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

    /**
     * A push that can never succeed must not make the branch deaf.
     *
     * The push runs before the pull, and its exception used to escape straight
     * past it. `markFailed()` deliberately leaves the rows PENDING and
     * `pendingGroups()` orders strictly by `min(sequence)`, so one group that
     * will never commit — a `(branch_id, transaction_number)` collision after
     * hardware was replaced, a check-constraint violation, a revoked column grant
     * — sits at the head of the outbox forever. Every later cycle threw at the
     * same place, so `Puller::pull()` and `StockReconciler::reconcile()` never ran
     * again: no price changes, no new or archived staff, no settings, and none of
     * the adjustments other branches approved. Indefinitely. While
     * `sync_status.connection_state` read `online`, because a constraint
     * violation classifies as a conflict rather than a network fault.
     *
     * The push failure is still recorded and still re-thrown — the cycle did not
     * succeed and must not say it did. It is simply re-thrown AFTER the pull, so
     * the one direction that still works keeps working. Safe by construction:
     * `StockReconciler` already subtracts this branch's unsynced local movements
     * from the baseline, which is exactly the state a failed push leaves.
     */
    public function test_a_push_that_cannot_succeed_still_lets_the_pull_run(): void
    {
        $pulled = false;

        $this->fakeCollaborators(
            push: fn () => throw new QueryException('pgsql', 'insert into transactions', [], new PDOException(
                'SQLSTATE[23505]: Unique violation: duplicate key value violates unique constraint',
            )),
            pull: function () use (&$pulled): array {
                $pulled = true;

                return ['items' => 2];
            },
        );

        $result = app(SyncCycle::class)->run();

        $this->assertTrue($pulled, 'A branch stops receiving the catalog, staff and settings the moment one row cannot be pushed.');

        // And it still reports the failure: the cycle did not succeed.
        //
        // The STATE is `online`, and correctly so — a constraint violation means
        // the branch reached Postgres and was refused, which is the distinction
        // `classify()` exists to draw (see the conflict test above). What says
        // the cycle failed is the error and the absence of a sync stamp.
        $this->assertSame(SyncStatus::ONLINE, $result['state']);
        $this->assertNotNull(SyncStatus::current()->last_error);
        $this->assertSame(SyncStatus::KIND_CONFLICT, SyncStatus::current()->last_error_kind);
        $this->assertNull(SyncStatus::current()->last_sync_at, 'A cycle whose push failed has not synced.');
        $this->assertNotNull(SyncStatus::current()->blocked_since);
    }

    /** A pull failure is still fatal to the cycle, and nothing masks it. */
    public function test_a_failing_pull_is_still_reported(): void
    {
        $this->fakeCollaborators(
            push: fn (): array => ['rows' => 0],
            pull: fn () => throw new SetupFailedException('the catalog read fell over'),
        );

        $result = app(SyncCycle::class)->run();

        $this->assertNotSame(SyncStatus::ONLINE, $result['state']);
        $this->assertStringContainsString('catalog read', (string) SyncStatus::current()->last_error);
    }

    /**
     * Run the real inner closure — version gate, push, pull, reconcile,
     * heartbeat — with the collaborators replaced, so the ORDER of the steps is
     * what is under test rather than any one of them.
     */
    private function fakeCollaborators(Closure $push, Closure $pull): void
    {
        $this->stubCycle(fn (Closure $work) => $work(DB::connection()));

        $this->app->instance(VersionGate::class, new class extends VersionGate
        {
            public function __construct() {}

            public function read(Connection $remote): array
            {
                return ['schema_version' => 24, 'min_app_version' => '1.0.0'];
            }

            public function assert(string $minAppVersion): void {}
        });

        $this->app->instance(Pusher::class, new class($push) extends Pusher
        {
            public function __construct(private readonly Closure $behaviour) {}

            public function push(Connection $remote): array
            {
                return ($this->behaviour)();
            }
        });

        $this->app->instance(Puller::class, new class($pull) extends Puller
        {
            public function __construct(private readonly Closure $behaviour) {}

            public function pull(Connection $remote, string $branchId): array
            {
                return ($this->behaviour)();
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

        $this->app->instance(Heartbeat::class, new class extends Heartbeat
        {
            public function __construct() {}

            public function upsert(Connection $remote, string $branchId, string $state, ?string $error, bool $synced): void {}
        });
    }

    /**
     * A credential that cannot be decrypted says so, and is not reported as
     * having no credential at all.
     *
     * `CredentialVault::open()` builds an accurate, actionable message — the
     * sealed value belongs to a different install's `APP_KEY`, which is what a
     * copied SQLite file looks like — and the cycle threw it away for a generic
     * "this device has no stored branch credential". Two situations with
     * completely different remedies ("was this ever set up?" versus "did someone
     * copy the database?"), reported identically.
     *
     * A device with no credential at all also stamps its probe time now: a
     * tried-and-failed cycle is still a try, and that path used to return before
     * the stamp — leaving the question unanswerable for exactly the device that
     * most needs it answered.
     */
    public function test_an_undecryptable_credential_is_diagnosed_rather_than_flattened(): void
    {
        // Sealed under a key this install does not hold.
        InstanceSecret::query()->where('id', InstanceSecret::ID)->update([
            'dsn_ciphertext' => 'eyJpdiI6InNvbWV0aGluZyIsInZhbHVlIjoiZ2FyYmFnZSIsIm1hYyI6ImJhZCJ9',
        ]);

        $this->stubCycle(fn () => $this->fail('A credential that cannot be opened must not reach Postgres.'));

        $result = app(SyncCycle::class)->run();

        $this->assertSame(SyncStatus::OFFLINE, $result['state']);
        $this->assertSame(SyncStatus::KIND_AUTH, SyncStatus::current()->last_error_kind);

        // The distinguishing half: not the "no credential" wording.
        $this->assertStringNotContainsString(
            'no stored branch credential',
            (string) SyncStatus::current()->last_error,
        );

        $this->assertNotNull(SyncStatus::current()->last_probe_at, 'Even a cycle that got nowhere is an attempt.');
    }

    public function test_a_device_with_no_credential_still_records_the_attempt(): void
    {
        InstanceSecret::query()->delete();

        app(SyncCycle::class)->run();

        $this->assertNotNull(SyncStatus::current()->last_probe_at);
    }

    public function test_the_route_answers_json_even_when_the_cycle_fails(): void
    {
        $this->stubCycle(fn () => throw new SetupFailedException('everything is on fire'));

        $this->actingAs(User::factory()->cashier()->create())
            ->postJson(route('sync'))
            ->assertOk()
            ->assertJsonPath('sync.state', SyncStatus::OFFLINE);
    }

    /**
     * M11.5. The cycle's PULL is what brings `users` down, so a device that
     * cannot sync until somebody logs in cannot be logged into by anyone it has
     * not already heard of. A staff member created at another branch would be
     * locked out of this till entirely — on a product whose premise is that any
     * user may work at any branch.
     */
    public function test_a_guest_on_the_login_screen_can_run_a_cycle(): void
    {
        $this->stubCycle(fn () => throw new SetupFailedException('still offline'));

        $this->assertGuest();

        $this->postJson(route('sync'))
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
