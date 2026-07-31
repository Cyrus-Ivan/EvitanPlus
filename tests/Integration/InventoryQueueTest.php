<?php

namespace Tests\Integration;

use App\Models\Category;
use App\Models\Instance;
use App\Models\SyncStatus;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use App\Support\Sync\SyncCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\Concerns\ProvisionsABusiness;
use Tests\TestCase;
use Throwable;

/**
 * The M8 milestone, against two real provisioned branches: **an adjustment
 * issued at one branch for another, and turned down by that branch when its own
 * sales have already spent the stock.**
 *
 * ## Why this cannot be proved anywhere else
 *
 * tests/Feature/Sync/InventoryQueueTest.php covers the accept/reject arithmetic
 * against a SQLite stand-in, in milliseconds. Three things it structurally
 * cannot reach, and each of them fails silently:
 *
 *  - **The INSERT grant.** `inventory_update_queue` was added to
 *    `BranchGrants::INSERT` by this phase and delivered to existing schemas with
 *    `schema:reapply-grants`. Without it every issue is `permission denied`, and
 *    the branch role is the only credential the shipped app ever holds — the
 *    console never ships.
 *  - **The four-column UPDATE grant.** A resolution that also set `updated_at`
 *    is refused, because the grant is column-level. A stand-in with no grants at
 *    all would pass either way.
 *  - **The check constraints.** `(status = 'pending') = (resolved_at is null)`
 *    and `resulting_snapshot >= 0` are real Postgres constraints; the stand-in
 *    carries none, on purpose.
 */
#[Group('supabase')]
class InventoryQueueTest extends TestCase
{
    use ProvisionsABusiness, RefreshDatabase;

    /** Each test sets its devices up for itself, through the real wizard. */
    protected bool $hydrated = false;

    /** The real probe, the real cycle, the real grants. */
    protected ?bool $connected = null;

    private const OWNER_USERNAME = 'queue-owner';

    private const BUSINESS_NAME = 'Queue Store';

    /** The branch that ISSUES. */
    private const BRANCH_A = 'QAA';

    /** The branch that is TARGETED, and therefore the one that decides. */
    private const BRANCH_B = 'QBB';

    /** @var array<string, mixed>|null */
    private static ?array $documentA = null;

    /** @var array<string, mixed>|null */
    private static ?array $documentB = null;

    private static ?string $schema = null;

    private static ?string $roleA = null;

    private static ?string $roleB = null;

    private static ?PDO $admin = null;

    public static function tearDownAfterClass(): void
    {
        self::destroyBusiness(self::$admin, self::$schema, self::$roleA);

        if (self::$admin !== null && self::$roleB !== null) {
            try {
                self::$admin->exec('drop role if exists "'.self::$roleB.'"');
            } catch (Throwable) {
                //
            }
        }

        self::$admin = null;
        self::$documentA = null;
        self::$documentB = null;
        self::$schema = null;
        self::$roleA = null;
        self::$roleB = null;

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $env = $this->consoleEnv();

        if (($env['CONSOLE_DB_PASSWORD'] ?? '') === '') {
            $this->markTestSkipped('No console admin credentials in console/.env — this test provisions a real business.');
        }

        $this->directory = storage_path('framework/testing/queue');
        File::ensureDirectoryExists($this->directory);

        if (self::$documentA === null) {
            self::$admin = $this->adminConnection($env);
            self::$documentA = $this->provisionBusiness(self::BUSINESS_NAME, self::OWNER_USERNAME, self::BRANCH_A, 'queue-test');
            self::$schema = self::$documentA['business']['schema'];
            self::$roleA = self::$documentA['role']['name'];

            self::$documentB = $this->provisionSecondBranch();
            self::$roleB = self::$documentB['role']['name'];
        }
    }

    protected function tearDown(): void
    {
        $this->resetSharedData();

        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_an_adjustment_the_branch_cannot_afford_is_turned_down_on_reconnect(): void
    {
        // Branch B has ten on its shelf, and that figure reaches Postgres — a
        // manual adjustment is an online write.
        $this->hydrateAs(self::$documentA);
        $this->createProduct('QUEUE1', '20.00');

        $this->hydrateAs(self::$documentB);
        $this->stockUp('QUEUE1', 10);

        // Someone at branch A writes off twenty-five. B is not running, is not
        // consulted, and cannot be: the row is only a request.
        $this->hydrateAs(self::$documentA);
        $this->issue(self::$documentB, 'QUEUE1', -25, 'Written off at QBB');

        $queued = $this->remote('select * from inventory_update_queue');
        $this->assertCount(1, $queued);
        $this->assertSame('pending', $queued[0]['status']);
        $this->assertNull($queued[0]['resolved_at']);

        // B reconnects. Its cycle answers the queue in step 2.
        $this->hydrateAs(self::$documentB);
        $result = app(SyncCycle::class)->run();

        $this->assertSame(SyncStatus::ONLINE, $result['state'], (string) $result['error']);
        $this->assertSame(1, $result['resolved']);

        $answered = $this->remote('select * from inventory_update_queue')[0];

        $this->assertSame('rejected', $answered['status']);
        $this->assertNotNull($answered['resolved_at']);
        $this->assertStringContainsString('10 on hand', (string) $answered['rejection_reason']);
        $this->assertNull($answered['ledger_entry_id']);

        // Stock is untouched on both sides, and never went negative anywhere.
        $this->assertSame(10, (int) $this->remote(
            'select quantity from stock_snapshots where branch_id = ?',
            [self::$documentB['branch']['id']],
        )[0]['quantity']);

        $this->assertSame(10, app(StockLedger::class)->currentStock(Variant::query()->where('sku', 'QUEUE1')->sole()));

        // And no ledger entry was invented for a rejected request.
        $this->assertSame(
            0,
            (int) $this->remote("select count(*) as c from inventory_ledger where notes = 'Written off at QBB'")[0]['c'],
        );
    }

    public function test_an_adjustment_the_branch_can_afford_becomes_a_ledger_entry_and_comes_back_down(): void
    {
        $this->hydrateAs(self::$documentA);
        $this->createProduct('QUEUE2', '20.00');

        $this->hydrateAs(self::$documentB);
        $this->stockUp('QUEUE2', 10);

        $this->hydrateAs(self::$documentA);
        $this->issue(self::$documentB, 'QUEUE2', -4, 'Damaged at QBB');

        $this->hydrateAs(self::$documentB);
        $result = app(SyncCycle::class)->run();

        $this->assertSame(SyncStatus::ONLINE, $result['state'], (string) $result['error']);

        $answered = $this->remote('select * from inventory_update_queue')[0];

        $this->assertSame('accepted', $answered['status']);
        // The entry's id IS the queue row's id, which is what makes a retry
        // after a lost connection collide with itself instead of double-applying.
        $this->assertSame($answered['id'], $answered['ledger_entry_id']);

        $entry = $this->remote(
            'select * from inventory_ledger where id = ?',
            [$answered['ledger_entry_id']],
        )[0];

        $this->assertSame(-4, (int) $entry['quantity']);
        $this->assertSame('stock_adjustment', $entry['type']);
        $this->assertSame('manual_adjustment', $entry['reference_type']);
        $this->assertSame(self::$documentB['branch']['id'], $entry['branch_id']);
        // Written against the ISSUER, not against whoever happened to be logged
        // in at the branch that applied it.
        $this->assertSame($this->owner()->getKey(), $entry['performed_by']);

        // Upstream stock is recomputed from Postgres's own ledger, so the next
        // baseline contains the adjustment rather than reverting it.
        $this->assertSame(6, (int) $this->remote(
            'select quantity from stock_snapshots where branch_id = ?',
            [self::$documentB['branch']['id']],
        )[0]['quantity']);

        // And the whole point of running step 2 BEFORE step 3: the entry rode
        // the same cycle's pull back down, and the reconciliation used it.
        $variant = Variant::query()->where('sku', 'QUEUE2')->sole();

        $this->assertSame(6, app(StockLedger::class)->currentStock($variant));
        $this->assertDatabaseHas('inventory_ledger', [
            'id' => $answered['ledger_entry_id'],
            'quantity' => -4,
            'type' => 'stock_adjustment',
        ]);
    }

    public function test_unsynced_sales_at_the_branch_are_counted_before_the_decision(): void
    {
        // The case the queue exists for, and the one an issuer cannot see. The
        // upstream figure says ten; B has sold eight of them and has not pushed.
        $this->hydrateAs(self::$documentA);
        $this->createProduct('QUEUE3', '20.00');

        $this->hydrateAs(self::$documentB);
        $this->stockUp('QUEUE3', 10);

        $variant = Variant::query()->where('sku', 'QUEUE3')->sole();

        for ($i = 0; $i < 8; $i++) {
            app(StockLedger::class)->record(
                variant: $variant, quantity: -1, type: 'sale', saleType: 'sale',
                referenceType: 'transaction', referenceId: null, performedBy: $this->owner(),
            );
        }

        // Issued from A against the ten it can see. Note B is NOT re-hydrated
        // afterwards — its outbox has to survive into the cycle below, because
        // the unsynced sales are half the arithmetic.
        $this->issueAsBranchA(self::$documentB, 'QUEUE3', -5, 'Shrinkage at QBB');

        $result = app(SyncCycle::class)->run();

        $this->assertSame(SyncStatus::ONLINE, $result['state'], (string) $result['error']);

        // The push in step 1 lands the eight sales, so step 2 decides against 2
        // and refuses. Decided against the baseline alone it would have been
        // accepted, and the branch would have gone to -3.
        $this->assertSame('rejected', $this->remote('select * from inventory_update_queue')[0]['status']);
        $this->assertSame(2, app(StockLedger::class)->currentStock($variant));
        $this->assertSame(2, (int) $this->remote(
            'select quantity from stock_snapshots where branch_id = ?',
            [self::$documentB['branch']['id']],
        )[0]['quantity']);
    }

    public function test_the_issuing_branch_sees_the_flag_and_can_re_issue(): void
    {
        $this->hydrateAs(self::$documentA);
        $this->createProduct('QUEUE4', '20.00');

        $this->hydrateAs(self::$documentB);
        $this->stockUp('QUEUE4', 3);

        $this->hydrateAs(self::$documentA);
        $this->issue(self::$documentB, 'QUEUE4', -9, 'Too many');

        $this->hydrateAs(self::$documentB);
        app(SyncCycle::class)->run();

        // Back at the branch that issued it: the in-app admin, reading the queue
        // live out of the business database.
        $this->hydrateAs(self::$documentA);

        $rejected = null;

        $this->actingAs($this->owner())
            ->get(route('stock-updates'))
            ->assertOk()
            ->assertInertia(function ($page) use (&$rejected) {
                $page->component('inventory/stock-updates', false)->where('online', true);

                $rows = $page->toArray()['props']['queued'];

                $this->assertCount(1, $rows);
                $this->assertSame('rejected', $rows[0]['status']);
                $this->assertSame(self::BRANCH_B, $rows[0]['branch']);
                $this->assertSame('Product QUEUE4', $rows[0]['item']);
                $this->assertSame(-9, $rows[0]['quantity']);
                $this->assertNotNull($rows[0]['rejection_reason']);

                $rejected = $rows[0]['id'];
            });

        // Re-issued for an amount that fits: a NEW pending row, leaving the
        // rejection standing as the record of what happened.
        $this->actingAs($this->owner())
            ->post(route('stock-updates.reissue', $rejected))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $rows = $this->remote('select * from inventory_update_queue order by created_at');

        $this->assertCount(2, $rows);
        $this->assertSame('rejected', $rows[0]['status']);
        $this->assertSame('pending', $rows[1]['status']);
        $this->assertNotSame($rows[0]['id'], $rows[1]['id']);
    }

    // ---------------------------------------------------------------- helpers

    private function owner(): User
    {
        return User::query()->where('username', self::OWNER_USERNAME)->sole();
    }

    /** Issue an adjustment from whichever device is currently hydrated. */
    private function issue(array $target, string $sku, int $quantity, string $notes): void
    {
        $this->actingAs($this->owner())
            ->post(route('stock-updates.store'), [
                'branch_id' => $target['branch']['id'],
                'variant_id' => Variant::query()->where('sku', $sku)->sole()->getKey(),
                'quantity' => $quantity,
                'notes' => $notes,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    /**
     * Issue from branch A WITHOUT re-hydrating the current device.
     *
     * The device stays branch B — outbox, local sales and all — while the row is
     * written with A's credential. Re-hydrating would empty exactly the unsynced
     * set the test is about.
     */
    private function issueAsBranchA(array $target, string $sku, int $quantity, string $notes): void
    {
        self::$admin->exec('set search_path to "'.self::$schema.'"');

        $variantId = Variant::query()->where('sku', $sku)->sole()->getKey();
        $ownerId = $this->owner()->getKey();

        self::$admin->exec(
            "insert into inventory_update_queue
               (id, branch_id, variant_id, quantity, notes, status, issued_by, created_at, updated_at)
             values ('{$this->uuid()}', '{$target['branch']['id']}', '{$variantId}', {$quantity},
                     '{$notes}', 'pending', '{$ownerId}', now(), now())"
        );
    }

    /** Opening stock at the currently hydrated branch, as an online write. */
    private function stockUp(string $sku, int $quantity): void
    {
        $this->actingAs($this->owner())
            ->post(route('stock.adjust', Variant::query()->where('sku', $sku)->sole()->getKey()), [
                'mode' => 'delta',
                'value' => $quantity,
                'notes' => 'Opening',
            ])
            ->assertSessionHasNoErrors();
    }

    private function createProduct(string $sku, string $price): void
    {
        $owner = $this->owner();

        if (Category::query()->where('name', 'Queued')->doesntExist()) {
            $this->actingAs($owner)->post(route('categories.store'), ['name' => 'Queued'])->assertSessionHasNoErrors();
        }

        $this->actingAs($owner)->post(route('items.store'), [
            'name' => 'Product '.$sku,
            'unit' => 'pc',
            'category_id' => Category::query()->where('name', 'Queued')->value('id'),
            'variants' => [[
                'name' => null, 'sku' => $sku, 'barcode' => null,
                'selling_price' => $price, 'cost' => '1.00', 'low_stock_threshold' => 0,
            ]],
        ])->assertSessionHasNoErrors();
    }

    /**
     * Run the real wizard for $document on a device that knows nothing.
     *
     * Emptied row by row because `migrate:fresh` VACUUMs, and VACUUM is illegal
     * inside the transaction RefreshDatabase puts every test in.
     */
    private function hydrateAs(array $document): void
    {
        foreach (['audit_logs', 'login_history', 'transactions', 'transaction_items', 'inventory_ledger', 'sale_returns', 'sale_return_items'] as $table) {
            DB::unprepared("drop trigger if exists {$table}_immutable_delete");
        }

        DB::unprepared('drop trigger if exists instance_singleton');
        DB::unprepared('drop trigger if exists instance_secret_singleton');

        Schema::disableForeignKeyConstraints();

        foreach ([
            'sync_outbox', 'sync_status', 'sync_cursor', 'audit_logs', 'login_history',
            'sale_return_items', 'sale_returns', 'transaction_items', 'transactions',
            'inventory_ledger', 'stock_snapshots', 'import_history',
            'variants', 'items', 'categories', 'settings', 'users',
            'instance_secret', 'instance',
        ] as $table) {
            DB::table($table)->delete();
        }

        Schema::enableForeignKeyConstraints();

        $this->post(route('setup.store'), [
            'credentials_path' => $this->writeCredentialsFile($document, 'device-'.$document['branch']['code'].'.json'),
            'delete_after_import' => '0',
            'configure_printer' => '0',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(Instance::current());
    }

    /** @return array<string, mixed> */
    private function provisionSecondBranch(): array
    {
        $out = $this->directory.'/branch-b.json';

        $command = $this->consoleCommand([
            'branch:provision',
            '--schema='.self::$schema,
            '--branch-name=Second Queue Branch',
            '--branch-code='.self::BRANCH_B,
            '--branch-address=2 Test Street',
            '--out='.$out,
            '--actor=queue-test',
            '--force',
            '--no-interaction',
        ]);

        exec($command.' 2>&1', $output, $status);

        $this->assertSame(0, $status, "branch:provision failed:\n".implode("\n", $output));

        return json_decode((string) file_get_contents($out), true);
    }

    /**
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

    /**
     * Back to the provisioned baseline. The schema is shared by every test in
     * this class and Postgres has no per-test rollback.
     */
    private function resetSharedData(): void
    {
        if (self::$admin === null) {
            return;
        }

        self::$admin->exec('set search_path to "'.self::$schema.'"');

        self::$admin->exec(
            'truncate inventory_update_queue, stock_snapshots, inventory_ledger, import_history,
                      variants, items, categories cascade'
        );

        self::$admin->exec("delete from users where username <> '".self::OWNER_USERNAME."'");
    }
}
