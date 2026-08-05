<?php

namespace Tests\Integration;

use App\Models\Category;
use App\Models\Instance;
use App\Models\Item;
use App\Models\Setting;
use App\Models\SyncCursor;
use App\Models\SyncStatus;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use App\Support\Sync\CredentialVault;
use App\Support\Sync\StockReconciler;
use App\Support\Sync\SyncCycle;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ApprovesEveryPendingRow;
use Tests\Integration\Concerns\ProvisionsABusiness;
use Tests\TestCase;

/**
 * The M7 milestone, proved the only way it can be: **an edit made at one branch
 * arriving at another without that device being re-set-up.**
 *
 * M6.5's integration test proved shared data reaches Postgres, and checked the
 * far end by hydrating a second device from scratch. That is the wrong shape for
 * a running shop — a till hydrates once in its life. This file asserts the same
 * data arriving through a **sync cycle** on a device that has been live all
 * along, which is the thing branches actually do every five minutes.
 *
 * ## Why these cases need a real Postgres and the feature suite does not
 *
 * tests/Feature/Sync/PullTest.php covers the cursor algorithm against a SQLite
 * stand-in, fast and credential-free. What it cannot reach is everything about
 * Postgres being Postgres, and that is precisely where the silent failures live:
 * `timestamptz` rendering and its microseconds against a cursor stored as a
 * string, `pdo_pgsql` returning booleans as 't'/'f' where `(bool) 'f'` is true,
 * the business timezone conversion in both directions, and the branch role's
 * actual grants. Each of those passes against a stand-in and breaks in a shop.
 */
#[Group('supabase')]
class PullTest extends TestCase
{
    use ApprovesEveryPendingRow, ProvisionsABusiness, RefreshDatabase;

    /** Each test sets its devices up for itself, through the real wizard. */
    protected bool $hydrated = false;

    /** The real probe, the real cycle. */
    protected ?bool $connected = null;

    private const OWNER_USERNAME = 'pull-owner';

    private const BUSINESS_NAME = 'Pull Store';

    private const BRANCH_A = 'PAA';

    private const BRANCH_B = 'PBB';

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
            } catch (\Throwable) {
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

        $this->directory = storage_path('framework/testing/pull');
        File::ensureDirectoryExists($this->directory);

        if (self::$documentA === null) {
            self::$admin = $this->adminConnection($env);
            self::$documentA = $this->provisionBusiness(self::BUSINESS_NAME, self::OWNER_USERNAME, self::BRANCH_A, 'pull-test');
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

    public function test_a_price_changed_at_one_branch_reaches_a_live_branch_on_the_next_cycle(): void
    {
        // Branch A creates the product.
        $this->hydrateAs(self::$documentA);
        $this->createProduct('PULLED1', '10.00');

        // Branch B hydrates and sees the price of the day it was set up. This is
        // where M6.5 stopped.
        $this->hydrateAs(self::$documentB);
        $this->assertSame('10.00', Variant::query()->where('sku', 'PULLED1')->sole()->selling_price);

        // Someone at A reprices. B is not re-set-up, not restarted, not touched.
        $this->repriceUpstream('PULLED1', '99.00');

        $result = app(SyncCycle::class)->run();

        $this->assertSame(SyncStatus::ONLINE, $result['state'], (string) $result['error']);
        $this->assertGreaterThan(0, $result['pulled']);
        $this->assertSame('99.00', Variant::query()->where('sku', 'PULLED1')->sole()->selling_price);
    }

    public function test_a_new_product_and_a_new_user_arrive_on_a_live_branch(): void
    {
        // Branch A establishes the category, and B is set up while that is all
        // there is. Everything asserted below is created AFTER B's hydrate, so
        // the only way it can reach B is the pull.
        $this->hydrateAs(self::$documentA);
        $this->createProduct('EARLY1', '5.00');

        $this->hydrateAs(self::$documentB);

        $this->assertSame(0, Variant::query()->where('sku', 'LATER1')->count());
        $this->assertSame(0, User::query()->where('username', 'latehire')->count());

        $this->createProductUpstream('LATER1', '5.00');
        $this->createUserUpstream('latehire', 'a-strong-password');

        app(SyncCycle::class)->run();

        $this->assertSame(1, Variant::query()->where('sku', 'LATER1')->count());
        $this->assertSame('Product LATER1', Variant::query()->where('sku', 'LATER1')->sole()->item->name);

        $hire = User::query()->where('username', 'latehire')->sole();

        // The boolean conversion, for real. pdo_pgsql can hand `false` back as
        // the string 'f', and `(bool) 'f'` is TRUE — so a mishandled pull would
        // demand a password change from every user it delivered.
        $this->assertFalse((bool) $hire->must_change_password);

        // §6's requirement: any user can log in at any branch, offline. Only
        // true if the hash arrived as a hash rather than being re-hashed by the
        // `hashed` cast — which is one of the five reasons the pull writes
        // through the query builder and never a model.
        $this->post('/login', ['username' => 'latehire', 'password' => 'a-strong-password'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticated();
    }

    public function test_a_business_setting_arrives_without_disturbing_the_device_printer(): void
    {
        $this->hydrateAs(self::$documentA);

        $this->actingAs($this->owner())->patch(route('business-settings.update'), [
            'business_name' => 'Renamed Upstream',
            'business_address' => '1 Test Street',
            'tin' => '000-000-000',
            'receipt_footer' => 'Salamat po!',
            'timezone' => 'Asia/Manila',
            'currency_symbol' => '₱',
        ])->assertSessionHasNoErrors();

        $this->hydrateAs(self::$documentB);

        // This device's own hardware config, which has no business-wide meaning
        // and exists in no Postgres schema.
        DB::table('settings')->insert([
            'id' => (string) $this->uuid(),
            'key' => 'printer_name',
            'value' => 'THIS-DEVICES-PRINTER',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(SyncCycle::class)->run();

        $this->assertSame('Renamed Upstream', Setting::query()->where('key', 'business_name')->value('value'));
        $this->assertSame('THIS-DEVICES-PRINTER', Setting::query()->where('key', 'printer_name')->value('value'));
    }

    public function test_a_second_cycle_with_nothing_new_reads_almost_nothing(): void
    {
        // The cursor against real `timestamptz`, which is the half a SQLite
        // stand-in cannot prove. Postgres renders microseconds and an offset;
        // the cursor stores that rendering verbatim and hands it straight back.
        // Round-tripping it through this app's second-resolution datetime
        // convention instead would re-read a whole second of rows every cycle,
        // or step over one — and stepping over is silent and permanent.
        $this->hydrateAs(self::$documentA);
        $this->createProduct('CURSOR1', '1.00');

        $this->hydrateAs(self::$documentB);

        $first = app(SyncCycle::class)->run();
        $second = app(SyncCycle::class)->run();

        $this->assertSame(SyncStatus::ONLINE, $second['state'], (string) $second['error']);

        // Not zero — `>=` re-reads the boundary row of each table on purpose, so
        // a row written in the same microsecond can never be stranded behind the
        // bookmark. What matters is that it is SMALL and does not grow: a steady
        // state costs one row per table, not the whole catalog.
        $this->assertLessThanOrEqual($first['pulled'], $second['pulled']);
        $this->assertLessThanOrEqual(10, $second['pulled']);

        $cursor = SyncCursor::positionOf('variants');

        $this->assertNotNull($cursor);
        // Postgres's own rendering, kept opaque. If this ever reads as a bare
        // 'Y-m-d H:i:s' the conversion has crept back in and the precision is
        // already gone.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d+)?[+-]\d{2}/', $cursor);
    }

    public function test_selling_offline_then_syncing_leaves_stock_right_on_both_sides(): void
    {
        // The reconciliation round trip, and the case §10 is written for.
        $this->hydrateAs(self::$documentA);
        $this->createProduct('STOCK1', '20.00');

        $variant = Variant::query()->where('sku', 'STOCK1')->sole();

        // Opening stock, online. Two steps since schema 21: the adjustment is
        // asked for, then confirmed by somebody at the branch — and only the
        // confirming writes the ledger row and recomputes the upstream cache.
        $this->stockUp($variant, 50);

        $this->assertSame(50, (int) $this->remote('select quantity from stock_snapshots')[0]['quantity']);

        // Now sell four, with the sale rows still sitting in the outbox — the
        // state a branch is in for the whole time it is offline.
        for ($i = 0; $i < 4; $i++) {
            app(StockLedger::class)->record(
                variant: $variant, quantity: -1, type: 'sale', saleType: 'sale',
                referenceType: 'transaction', referenceId: null, performedBy: $this->owner(),
            );
        }

        $this->assertSame(46, app(StockLedger::class)->currentStock($variant));

        // A cycle: push first, so the baseline it then reads already contains
        // the four sales and the reconciliation has nothing left to subtract.
        $result = app(SyncCycle::class)->run();

        $this->assertSame(SyncStatus::ONLINE, $result['state'], (string) $result['error']);
        $this->assertSame(0, $result['unsynced_count']);

        // The same 46 on both sides, arrived at independently: Postgres by
        // recomputing its own ledger, the till by baseline + (now empty)
        // unsynced set.
        $this->assertSame(46, (int) $this->remote('select quantity from stock_snapshots')[0]['quantity']);
        $this->assertSame(46, app(StockLedger::class)->currentStock($variant));
    }

    public function test_a_baseline_never_overwrites_unpushed_sales(): void
    {
        // The failure this whole class guards against. Blind-overwriting is the
        // obvious reading of "pull the stock down", and on a branch that has been
        // offline all morning it hands back stock that was sold and paid for.
        $this->hydrateAs(self::$documentA);
        $this->createProduct('KEEP1', '20.00');

        $variant = Variant::query()->where('sku', 'KEEP1')->sole();

        $this->stockUp($variant, 30);

        app(StockLedger::class)->record(
            variant: $variant, quantity: -7, type: 'sale', saleType: 'sale',
            referenceType: 'transaction', referenceId: null, performedBy: $this->owner(),
        );

        // Reconcile WITHOUT pushing, which is what a cycle that fails part-way
        // through its push leaves behind.
        app(StockReconciler::class)->reconcile(
            $this->branchConnection(),
            (string) Instance::current()?->branch_id,
        );

        // 30 upstream − 7 unsynced = 23, not the 30 Postgres still believes.
        $this->assertSame(23, app(StockLedger::class)->currentStock($variant));
    }

    public function test_a_confirmed_manual_adjustment_reaches_the_business_database(): void
    {
        // The defect M7 had to fix before the reconciliation above could be
        // safe. Before this, the row was written to local SQLite and nothing
        // else — shouldPush() excludes stock_adjustment rows from the outbox, so
        // no other path would ever have carried it — and the next baseline
        // silently reverted the count.
        //
        // Schema 21 moved WHEN it is written, not whether: asking for the
        // adjustment writes nothing anywhere, and confirming it writes both
        // sides in one online write.
        $this->hydrateAs(self::$documentA);
        $this->createProduct('ADJUST1', '20.00');

        $variant = Variant::query()->where('sku', 'ADJUST1')->sole();

        $this->actingAs($this->owner())
            ->post(route('stock.adjust', $variant->id), ['mode' => 'add', 'value' => 15, 'notes' => 'Recount'])
            ->assertSessionHasNoErrors();

        // Nothing yet, on either side.
        $this->assertSame(
            0,
            (int) $this->remote("select count(*) as c from inventory_ledger where type = 'stock_adjustment'")[0]['c'],
        );

        $this->approveEverythingPending($this->owner());

        $ledger = $this->remote("select quantity, type, reference_type from inventory_ledger where type = 'stock_adjustment'");

        $this->assertCount(1, $ledger);
        $this->assertSame(15, (int) $ledger[0]['quantity']);
        $this->assertSame('manual_adjustment', $ledger[0]['reference_type']);

        // And the upstream cache Postgres keeps no trigger for.
        $this->assertSame(15, (int) $this->remote('select quantity from stock_snapshots')[0]['quantity']);

        // Which is the whole point: a cycle now finds this adjustment in the
        // baseline instead of reverting it.
        app(SyncCycle::class)->run();

        $this->assertSame(15, app(StockLedger::class)->currentStock($variant));
    }

    public function test_an_archived_product_arrives_archived(): void
    {
        $this->hydrateAs(self::$documentA);
        $this->createProduct('ARCHIVED1', '3.00');

        $this->hydrateAs(self::$documentB);
        app(SyncCycle::class)->run();

        $this->assertSame(1, Item::query()->count());

        // Archived at A, which is an UPDATE and not a delete — the never-delete
        // invariant is exactly what lets a watermark pull carry disappearances.
        $this->hydrateAs(self::$documentA);
        $item = Item::query()->where('name', 'Product ARCHIVED1')->sole();
        $this->actingAs($this->owner())->patch(route('items.archive', $item))->assertSessionHasNoErrors();

        $this->hydrateAs(self::$documentB);
        app(SyncCycle::class)->run();

        $this->assertNotNull(DB::table('items')->where('id', $item->id)->value('archived_at'));
    }

    // ---------------------------------------------------------------- helpers

    private function owner(): User
    {
        return User::query()->where('username', self::OWNER_USERNAME)->sole();
    }

    /** The sealed branch credential, opened the way a cycle opens it. */
    private function branchConnection(): Connection
    {
        $credentials = app(CredentialVault::class)->open();

        $base = config('database.connections.pgsql');
        config(['database.connections.pgsql' => array_merge($base, $credentials)]);

        DB::purge('pgsql');

        return DB::connection('pgsql');
    }

    /**
     * Opening stock at the currently hydrated branch.
     *
     * Two steps since schema 21 — asked for, then confirmed by somebody standing
     * there. Both are online writes, and only the second one moves anything.
     */
    private function stockUp(Variant $variant, int $quantity): void
    {
        $this->actingAs($this->owner())
            ->post(route('stock.adjust', $variant->id), ['mode' => 'add', 'value' => $quantity, 'notes' => 'Opening'])
            ->assertSessionHasNoErrors();

        $this->approveEverythingPending($this->owner());
    }

    private function createProduct(string $sku, string $price): void
    {
        $owner = $this->owner();

        if (Category::query()->where('name', 'Pulled')->doesntExist()) {
            $this->actingAs($owner)->post(route('categories.store'), ['name' => 'Pulled'])->assertSessionHasNoErrors();
        }

        $this->actingAs($owner)->post(route('items.store'), [
            'name' => 'Product '.$sku,
            'unit' => 'pc',
            'category_id' => Category::query()->where('name', 'Pulled')->value('id'),
            'variants' => [[
                'name' => null, 'sku' => $sku, 'barcode' => null,
                'selling_price' => $price, 'cost' => '1.00', 'low_stock_threshold' => 0,
            ]],
        ])->assertSessionHasNoErrors();
    }

    /**
     * Create a product directly in the business database.
     *
     * Through the admin connection rather than branch A's controller, because
     * these tests are about branch B NOTICING a change: driving it from the
     * admin side keeps B's device the only one whose local state is under test,
     * and removes the re-hydrate that would otherwise deliver the row by the
     * very path being tested against.
     */
    private function createProductUpstream(string $sku, string $price): void
    {
        self::$admin->exec('set search_path to "'.self::$schema.'"');

        $itemId = $this->uuid();
        $categoryId = self::$admin->query("select id from categories where name = 'Pulled'")->fetchColumn();

        self::$admin->exec(
            "insert into items (id, name, unit, category_id, created_at, updated_at)
             values ('{$itemId}', 'Product {$sku}', 'pc', '{$categoryId}', now(), now())"
        );

        self::$admin->exec(
            "insert into variants (id, item_id, name, sku, selling_price, cost, low_stock_threshold, created_at, updated_at)
             values ('{$this->uuid()}', '{$itemId}', null, '{$sku}', {$price}, 1.00, 0, now(), now())"
        );
    }

    /** A staff account created elsewhere in the business, hash and all. */
    private function createUserUpstream(string $username, string $password): void
    {
        self::$admin->exec('set search_path to "'.self::$schema.'"');

        $hash = bcrypt($password);

        self::$admin->exec(
            "insert into users (id, name, username, password, role, must_change_password, created_at, updated_at)
             values ('{$this->uuid()}', 'Late Hire', '{$username}', '{$hash}', 'cashier', false, now(), now())"
        );
    }

    /**
     * Reprice through the admin connection.
     *
     * Deliberately not through branch A's controller: this test is about branch
     * B noticing a change, and driving it from the admin side keeps B's device
     * the only one whose local state is under test.
     */
    private function repriceUpstream(string $sku, string $price): void
    {
        self::$admin->exec('set search_path to "'.self::$schema.'"');
        self::$admin->exec("update variants set selling_price = {$price}, updated_at = now() where sku = '{$sku}'");
    }

    /**
     * Run the real wizard for $document on a device that knows nothing.
     *
     * The same routine as tests/Integration/OnlineWriteTest.php — emptied row by
     * row because `migrate:fresh` VACUUMs, and VACUUM is illegal inside the
     * transaction RefreshDatabase puts every test in.
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
            '--branch-name=Second Pull Branch',
            '--branch-code='.self::BRANCH_B,
            '--branch-address=2 Test Street',
            '--out='.$out,
            '--actor=pull-test',
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
            'truncate stock_snapshots, inventory_ledger, import_history, variants, items, categories cascade'
        );

        self::$admin->exec("delete from users where username <> '".self::OWNER_USERNAME."'");

        foreach ([
            'business_name' => self::BUSINESS_NAME,
            'business_address' => '1 Test Street',
        ] as $key => $value) {
            self::$admin->exec("update settings set value = '{$value}' where key = '{$key}'");
        }
    }
}
