<?php

namespace Tests\Integration;

use App\Models\Instance;
use App\Models\SyncOutboxEntry;
use App\Models\SyncStatus;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use App\Support\Sync\Outbox;
use App\Support\Sync\SyncCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\Concerns\ProvisionsABusiness;
use Tests\TestCase;

/**
 * The push against a REAL provisioned business (M6 milestone).
 *
 * ## Why this cannot be a unit test
 *
 * Everything that goes wrong quietly in a push is a Postgres value shape or a
 * Postgres constraint, and none of them reproduce against a stand-in:
 *
 *  - a naive `datetime` landing in a `timestamptz` and being read in the
 *    SERVER's timezone — every sale silently shifted, sorting correctly and
 *    reporting wrong;
 *  - `on conflict do nothing` without a target absorbing a `(branch_id,
 *    transaction_number)` collision, so a re-used serial is dropped rather than
 *    raised;
 *  - the BEFORE UPDATE immutability triggers, which turn any `do update` into an
 *    error only the real schema can raise;
 *  - `stock_snapshots` having no trigger behind it, so nothing but this code
 *    keeps Postgres's stock true.
 *
 * So: provision, hydrate, sell offline, push for real, and read the rows back
 * out of Postgres.
 *
 * Tagged `supabase`, excluded from `composer verify`, run by
 * `composer verify:hydrate --fail-on-skipped` so a silent skip on a credentialed
 * machine is a failure.
 */
#[Group('supabase')]
class PushTest extends TestCase
{
    use ProvisionsABusiness, RefreshDatabase;

    /** Each test sets this device up for itself, through the real wizard. */
    protected bool $hydrated = false;

    /** The real probe would answer honestly here; these tests are genuinely online. */
    protected ?bool $connected = null;

    private const OWNER_USERNAME = 'push-owner';

    private const BUSINESS_NAME = 'Push Test Store';

    private const BRANCH_CODE = 'PSH';

    /** @var array<string, mixed>|null */
    private static ?array $document = null;

    private static ?string $schema = null;

    private static ?string $role = null;

    private static ?PDO $admin = null;

    private static string $variantId = '';

    public static function tearDownAfterClass(): void
    {
        self::destroyBusiness(self::$admin, self::$schema, self::$role);

        self::$admin = null;
        self::$document = null;
        self::$schema = null;
        self::$role = null;

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $env = $this->consoleEnv();

        if (($env['CONSOLE_DB_PASSWORD'] ?? '') === '') {
            $this->markTestSkipped('No console admin credentials in console/.env — this test provisions a real business.');
        }

        $this->directory = storage_path('framework/testing/push');
        File::ensureDirectoryExists($this->directory);

        if (self::$document === null) {
            self::$admin = $this->adminConnection($env);
            self::$document = $this->provisionBusiness(self::BUSINESS_NAME, self::OWNER_USERNAME, self::BRANCH_CODE, 'push-test');
            self::$schema = self::$document['business']['schema'];
            self::$role = self::$document['role']['name'];

            $this->seedCatalog();
        }

        $this->hydrate();
    }

    protected function tearDown(): void
    {
        // Every test leaves rows in the shared schema; the next one must not
        // read them as its own. The local SQLite is rolled back by
        // RefreshDatabase, but Postgres has no such thing.
        $this->truncateBranchHistory();

        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_offline_sales_reach_postgres_after_a_push(): void
    {
        $cashier = $this->cashier();

        $this->sell($cashier, 2);
        $this->sell($cashier, 1);

        $this->assertSame(8, app(Outbox::class)->unsyncedCount());

        $result = app(SyncCycle::class)->run();

        $this->assertSame(SyncStatus::ONLINE, $result['state'], (string) $result['error']);
        $this->assertSame(0, $result['unsynced_count']);

        $branchId = self::$document['branch']['id'];

        $rows = $this->remote(
            'select transaction_number, total, branch_id, created_at from transactions where branch_id = ? order by transaction_number',
            [$branchId],
        );

        $this->assertCount(2, $rows);

        // The serial arrived with its branch prefix, which is the only thing
        // making receipt numbers unique across a business (§12).
        $this->assertSame('PSH-0000000001', $rows[0]['transaction_number']);
        $this->assertSame('PSH-0000000002', $rows[1]['transaction_number']);

        // branch_id is stamped at push time from `instance` — the local mirror
        // has no such column.
        $this->assertSame($branchId, $rows[0]['branch_id']);

        // THE TIMESTAMP TRAP: the local value is naive UTC. Handed over without
        // an offset, Postgres would read it in the server's timezone and every
        // sale would be silently shifted.
        $local = Transaction::query()->orderBy('transaction_number')->first();
        $this->assertSame(
            $local->created_at->utc()->format('Y-m-d H:i:s'),
            (new \DateTimeImmutable($rows[0]['created_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        );

        // The whole batch, not just its header. The ledger is counted by
        // `type = 'sale'`: the opening stock is a stock_adjustment row seeded
        // upstream and was never this branch's to push.
        $this->assertSame(2, $this->remoteCount('transaction_items', $branchId));
        $this->assertSame(2, $this->remoteCount('audit_logs', $branchId));
        $this->assertCount(2, $this->remote(
            "select id from inventory_ledger where branch_id = ? and type = 'sale'",
            [$branchId],
        ));
    }

    public function test_re_running_a_push_is_a_no_op(): void
    {
        $this->sell($this->cashier(), 1);

        app(SyncCycle::class)->run();

        $branchId = self::$document['branch']['id'];
        $before = $this->remoteCount('transactions', $branchId);

        // Re-enqueue everything that was just pushed, exactly as a cycle
        // interrupted after the Postgres commit but before the local stamp
        // would leave it.
        SyncOutboxEntry::query()->update(['synced_at' => null]);

        $result = app(SyncCycle::class)->run();

        $this->assertSame(SyncStatus::ONLINE, $result['state'], (string) $result['error']);
        $this->assertSame($before, $this->remoteCount('transactions', $branchId));
        $this->assertSame(1, $this->remoteCount('transaction_items', $branchId));

        // `on conflict (id) do nothing`, not `do update`: every table here
        // carries a BEFORE UPDATE immutability trigger, so a re-push that tried
        // to update would raise rather than absorb.
        $this->assertSame(0, SyncOutboxEntry::query()->whereNull('synced_at')->count());
    }

    public function test_postgres_stock_is_recomputed_from_its_own_ledger(): void
    {
        $branchId = self::$document['branch']['id'];

        // Postgres has NO trigger maintaining stock_snapshots; the branch role
        // holds INSERT and UPDATE on it because the till is what maintains it.
        // Without this the table would stay at its hydrated value forever while
        // M7's baseline and M8's queue both read it as though it meant something.
        $this->sell($this->cashier(), 3);

        app(SyncCycle::class)->run();

        $quantity = $this->remote(
            'select quantity from stock_snapshots where branch_id = ? and variant_id = ?',
            [$branchId, self::$variantId],
        )[0]['quantity'];

        // Seeded at 40, sold 3.
        $this->assertSame(37, (int) $quantity);
        $this->assertSame(37, (int) DB::table('stock_snapshots')->where('variant_id', self::$variantId)->value('quantity'));
    }

    public function test_a_colliding_serial_raises_instead_of_being_swallowed(): void
    {
        $branchId = self::$document['branch']['id'];

        $this->sell($this->cashier(), 1);

        $number = Transaction::query()->value('transaction_number');

        // A different row wearing a serial this branch has already used — the
        // shape a replacement device would produce if the serial floor were
        // missing. `insertOrIgnore`'s untargeted `on conflict do nothing` would
        // discard the real sale here, report a clean sync and a zero backlog,
        // and lose the money silently.
        self::$admin->exec('set search_path to "'.self::$schema.'"');
        self::$admin->exec(sprintf(
            "insert into transactions (id, branch_id, transaction_number, payment_type, discount, total, user_id, created_at)
             values ('%s', '%s', '%s', 'cash', 0, 1.00, '%s', now())",
            $this->uuid(),
            $branchId,
            $number,
            User::query()->value('id'),
        ));

        $result = app(SyncCycle::class)->run();

        // M6 could only assert "not online" here, because a constraint refusal
        // fell into the catch-all and was reported as `offline` — telling the
        // shop to wait for a connection it already had. M6.5 named it: the
        // branch IS online and its backlog is stuck.
        $this->assertSame(SyncStatus::ONLINE, $result['state']);
        $this->assertSame(SyncStatus::KIND_CONFLICT, SyncStatus::current()->last_error_kind);
        $this->assertNull(SyncStatus::current()->last_sync_at, 'A refused push is not a successful sync.');

        // And the sale is still in the backlog, for a human to look at, rather
        // than marked as delivered.
        $this->assertGreaterThan(0, SyncOutboxEntry::query()->whereNull('synced_at')->count());
    }

    public function test_returns_and_defects_push_but_stock_adjustments_do_not(): void
    {
        $branchId = self::$document['branch']['id'];
        $cashier = $this->cashier();

        $this->sell($cashier, 4);

        $transaction = Transaction::query()->firstOrFail();
        $item = $transaction->items()->firstOrFail();

        $this->actingAs($cashier)->post(route('sales.returns.store', $transaction), [
            'type' => 'return',
            'lines' => [['transaction_item_id' => $item->id, 'quantity' => 1]],
            'refund_payment_type' => 'cash',
        ])->assertSessionHasNoErrors();

        // An online-authoritative row, written locally the way an M7 pull would
        // have written it. It must NOT go back up: `stock_adjustment` rows have
        // one writer, and it is not this branch.
        $adjustment = app(StockLedger::class)->record(
            Variant::query()->findOrFail(self::$variantId),
            5, 'stock_adjustment', null, 'manual_adjustment', null, $cashier,
        );

        $result = app(SyncCycle::class)->run();
        $this->assertSame(SyncStatus::ONLINE, $result['state'], (string) $result['error']);

        $this->assertSame(1, $this->remoteCount('sale_returns', $branchId));
        $this->assertSame(1, $this->remoteCount('sale_return_items', $branchId));

        // The sale deduction and the return restoration went up…
        $pushed = $this->remote(
            "select id, sale_type from inventory_ledger where branch_id = ? and type = 'sale' order by created_at",
            [$branchId],
        );

        $this->assertSame(['sale', 'return'], array_column($pushed, 'sale_type'));

        // …and the locally written adjustment did not. (The opening stock is a
        // stock_adjustment too, seeded upstream, so counting that type proves
        // nothing — the row's own id is the claim.)
        $this->assertSame(
            [],
            $this->remote('select id from inventory_ledger where id = ?', [$adjustment->id]),
        );
    }

    public function test_a_hydrated_device_records_the_branchs_existing_serials_as_its_floor(): void
    {
        $branchId = self::$document['branch']['id'];

        // A branch that has been trading, on hardware that is about to be
        // replaced.
        self::$admin->exec('set search_path to "'.self::$schema.'"');
        self::$admin->exec(sprintf(
            "insert into transactions (id, branch_id, transaction_number, payment_type, discount, total, user_id, created_at)
             values ('%s', '%s', 'PSH-0000000317', 'cash', 0, 1.00, '%s', now())",
            $this->uuid(),
            $branchId,
            $this->remote('select id from users where username = ?', [self::OWNER_USERNAME])[0]['id'],
        ));

        // The replacement device, hydrating with no history of its own.
        Instance::query()->delete();
        DB::table('instance_secret')->delete();
        $this->hydrate();

        $this->assertSame('PSH-0000000317', Instance::current()->transaction_number_floor);

        // …and it continues the branch's sequence rather than restarting at 1,
        // which the insert-only push would have discarded without a word.
        $this->sell($this->cashier(), 1);

        $this->assertSame('PSH-0000000318', Transaction::query()->value('transaction_number'));
        $this->assertSame(SyncStatus::ONLINE, app(SyncCycle::class)->run()['state']);
    }

    // ---------------------------------------------------------------- helpers

    /** Run the real wizard against this business's credentials JSON. */
    private function hydrate(): void
    {
        $this->post(route('setup.store'), [
            'credentials_path' => $this->writeCredentialsFile(self::$document),
            'delete_after_import' => '0',
            'configure_printer' => '0',
        ])->assertSessionHasNoErrors();

        // The hydrate's own `system.setup_completed` audit row would otherwise
        // ride along in every assertion about what a sale pushes. Clearing the
        // outbox is enough and is the only option: the row itself cannot be
        // deleted, because audit_logs is immutable by trigger.
        DB::table('sync_outbox')->truncate();
    }

    private function cashier(): User
    {
        return User::query()->where('username', self::OWNER_USERNAME)->sole();
    }

    private function sell(User $user, int $quantity): void
    {
        $this->actingAs($user)->post(route('pos.checkout'), [
            'lines' => [['variant_id' => self::$variantId, 'quantity' => $quantity]],
            'payment_type' => 'cashless',
        ])->assertSessionHasNoErrors();
    }

    /**
     * Read Postgres over the ADMIN connection, so a test never proves the push
     * worked using the same credential the push used.
     *
     * @param  list<mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    private function remote(string $sql, array $bindings = []): array
    {
        self::$admin->exec('set search_path to "'.self::$schema.'"');

        $statement = self::$admin->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function remoteCount(string $table, string $branchId): int
    {
        // The two line-item tables have no branch_id: a line belongs to whatever
        // branch its parent row does, and repeating the fact on the child would
        // be a second place for it to be wrong. The schema is truncated between
        // tests, so an unscoped count is exact here.
        $scoped = ! in_array($table, ['transaction_items', 'sale_return_items'], true);

        return (int) $this->remote(
            "select count(*) as n from {$table}".($scoped ? ' where branch_id = ?' : ''),
            $scoped ? [$branchId] : [],
        )[0]['n'];
    }

    private function truncateBranchHistory(): void
    {
        if (self::$admin === null) {
            return;
        }

        self::$admin->exec('set search_path to "'.self::$schema.'"');

        // Immutability triggers are BEFORE UPDATE/DELETE; truncate is neither,
        // which is exactly why the console's own cleanup can drop a schema but
        // not delete a row.
        self::$admin->exec(
            'truncate transaction_items, sale_return_items, sale_returns, inventory_ledger, audit_logs, login_history, transactions cascade'
        );

        // Back to the opening position for the next test — the ledger row
        // included, for the reason seedOpeningStock() explains.
        $this->seedOpeningStock();
    }

    /**
     * Catalog, as an online Owner would have created it. Written with the ADMIN
     * connection: the branch role deliberately cannot write any of it.
     */
    private function seedCatalog(): void
    {
        $categoryId = $this->uuid();
        $itemId = $this->uuid();
        self::$variantId = $this->uuid();

        $now = now()->toDateTimeString();

        self::$admin->exec('set search_path to "'.self::$schema.'"');

        foreach ([
            "insert into categories (id, name, created_at, updated_at) values ('{$categoryId}', 'Beverages', '{$now}', '{$now}')",
            "insert into items (id, name, unit, category_id, created_at, updated_at)
             values ('{$itemId}', 'Push Coffee', 'pc', '{$categoryId}', '{$now}', '{$now}')",
            'insert into variants (id, item_id, name, sku, barcode, selling_price, cost, low_stock_threshold, created_at, updated_at)
             values (\''.self::$variantId."', '{$itemId}', null, 'PSHSKU1', null, 25.50, 10.00, 5, '{$now}', '{$now}')",
        ] as $statement) {
            self::$admin->exec($statement);
        }

        $this->seedOpeningStock();
    }

    /**
     * 40 on hand, as a LEDGER ROW plus the snapshot it implies.
     *
     * The ledger row is not decoration, and getting this wrong the first time is
     * what proved it. Stock is ledger-derived (CLAUDE.md) — a snapshot is a
     * cache of the ledger sum, never an independent fact — so a snapshot written
     * without the movement that produced it is a state the system cannot
     * legitimately reach. Seeded that way, the push's recompute reads the sum of
     * this branch's SALES alone, arrives at a negative number, and Postgres's
     * `stock_snapshots_non_negative` check refuses the whole cycle. Which is the
     * right behaviour: the alternative would be writing a stock figure that is
     * quietly wrong.
     *
     * The operational consequence is recorded in MULTIBRANCH-ROADMAP.md's M6
     * note: whatever puts opening stock into a business schema — M8's queue, a
     * future online CSV import — must write a ledger entry, not just a snapshot.
     */
    private function seedOpeningStock(): void
    {
        $now = now()->toDateTimeString();
        $branchId = self::$document['branch']['id'];
        $ownerId = $this->remote('select id from users where username = ?', [self::OWNER_USERNAME])[0]['id'];

        self::$admin->exec('set search_path to "'.self::$schema.'"');

        self::$admin->exec(
            "insert into inventory_ledger
                (id, branch_id, variant_id, quantity, resulting_snapshot, type, sale_type, reference_id, reference_type, performed_by, created_at)
             values ('".$this->uuid()."', '{$branchId}', '".self::$variantId."', 40, 40, 'stock_adjustment', null, null, 'manual_adjustment', '{$ownerId}', '{$now}')"
        );

        self::$admin->exec(
            "insert into stock_snapshots (id, branch_id, variant_id, quantity, created_at, updated_at)
             values ('".$this->uuid()."', '{$branchId}', '".self::$variantId."', 40, '{$now}', '{$now}')
             on conflict (branch_id, variant_id) do update set quantity = 40"
        );
    }
}
