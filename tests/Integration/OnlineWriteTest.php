<?php

namespace Tests\Integration;

use App\Models\Category;
use App\Models\Instance;
use App\Models\InstanceSecret;
use App\Models\Item;
use App\Models\Setting;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\InventoryCsvSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\Concerns\ProvisionsABusiness;
use Tests\TestCase;

/**
 * The M6.5 milestone, proved the only way it can be: **a change made at one
 * branch arriving at another.**
 *
 * Two real branches of one real business, both hydrated through the real wizard
 * on separate local databases. Everything else in the suite can be satisfied by
 * a device talking to itself; this cannot, and this is the thing the whole
 * initiative is named for.
 *
 * Until M6.5 every assertion below failed in the same way and for the same
 * reason: the edit was written to the editing device's SQLite and stopped there.
 * §5.1 said the till "never edits them locally", which was a claim about the app
 * rather than a description of it, so no phase ever owned making it true.
 */
#[Group('supabase')]
class OnlineWriteTest extends TestCase
{
    use ProvisionsABusiness, RefreshDatabase;

    /** Each test sets its devices up for itself, through the real wizard. */
    protected bool $hydrated = false;

    /** The real probe and the real OnlineWrite — that is the subject. */
    protected ?bool $connected = null;

    private const OWNER_USERNAME = 'shared-owner';

    private const BUSINESS_NAME = 'Shared Write Store';

    private const BRANCH_A = 'AAA';

    private const BRANCH_B = 'BBB';

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
        self::destroyBusiness(self::$admin, null, self::$roleB);

        // The second role outlives the schema drop above, so it is dropped on
        // its own — destroyBusiness() returns early without a schema.
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

        $this->directory = storage_path('framework/testing/online-write');
        File::ensureDirectoryExists($this->directory);

        if (self::$documentA === null) {
            self::$admin = $this->adminConnection($env);
            self::$documentA = $this->provisionBusiness(self::BUSINESS_NAME, self::OWNER_USERNAME, self::BRANCH_A, 'online-write-test');
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

    public function test_a_product_created_at_one_branch_reaches_another(): void
    {
        $this->hydrateAs(self::$documentA);

        $owner = User::query()->where('username', self::OWNER_USERNAME)->sole();

        // Created through the real controller, on branch A's device.
        $this->actingAs($owner)->post(route('categories.store'), ['name' => 'Beverages'])
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)->post(route('items.store'), [
            'name' => 'Shared Coffee',
            'unit' => 'pc',
            'category_id' => Category::query()->where('name', 'Beverages')->value('id'),
            'variants' => [[
                'name' => null, 'sku' => 'SHARED1', 'barcode' => null,
                'selling_price' => '25.50', 'cost' => '10.00', 'low_stock_threshold' => 5,
            ]],
        ])->assertSessionHasNoErrors();

        // It is in the business database, not merely on this device.
        $this->assertSame(1, $this->remoteCount('categories'));
        $this->assertSame('Shared Coffee', $this->remote('select name from items')[0]['name']);
        $this->assertSame('SHARED1', $this->remote('select sku from variants')[0]['sku']);

        // The catalog rows are business-wide, so they carry no branch_id at all
        // — a product belongs to the business, not to whoever typed it.
        $this->assertSame(
            [],
            $this->remote("select column_name from information_schema.columns
                            where table_schema = current_schema() and table_name = 'items' and column_name = 'branch_id'"),
        );

        // …and now branch B, a different device with its own database and its
        // own credential, hydrates and finds it.
        $this->hydrateAs(self::$documentB);

        $variant = Variant::query()->where('sku', 'SHARED1')->sole();

        $this->assertSame('25.50', $variant->selling_price);
        $this->assertSame('Shared Coffee', $variant->item->name);
        $this->assertSame('Beverages', $variant->item->category->name);
    }

    public function test_a_price_changed_at_one_branch_reaches_the_other(): void
    {
        $this->hydrateAs(self::$documentA);

        $owner = User::query()->where('username', self::OWNER_USERNAME)->sole();

        // Through the controller, not Category::create(): a category written
        // locally only would not exist upstream, and the item that referenced it
        // would be refused by Postgres's foreign key — correctly. Getting that
        // wrong the first time is the clearest demonstration there is that these
        // writes really are reaching the business database.
        $this->actingAs($owner)->post(route('categories.store'), ['name' => 'Priced'])
            ->assertSessionHasNoErrors();

        $categoryId = Category::query()->where('name', 'Priced')->value('id');

        $this->actingAs($owner)->post(route('items.store'), [
            'name' => 'Repriced Item', 'unit' => 'pc', 'category_id' => $categoryId,
            'variants' => [[
                'name' => null, 'sku' => 'REPRICE1', 'barcode' => null,
                'selling_price' => '10.00', 'cost' => '4.00', 'low_stock_threshold' => 0,
            ]],
        ])->assertSessionHasNoErrors();

        $item = Item::query()->where('name', 'Repriced Item')->sole();

        $this->actingAs($owner)->patch(route('items.update', $item), [
            'name' => 'Repriced Item', 'unit' => 'pc', 'category_id' => $categoryId,
            'variants' => [[
                'id' => $item->variants()->value('id'),
                'name' => null, 'sku' => 'REPRICE1', 'barcode' => null,
                'selling_price' => '99.00', 'cost' => '4.00', 'low_stock_threshold' => 0,
            ]],
        ])->assertSessionHasNoErrors();

        // An UPDATE upstream, not a second row: `on conflict (id) do update`.
        $remote = $this->remote("select selling_price from variants where sku = 'REPRICE1'");

        $this->assertCount(1, $remote);
        $this->assertEqualsWithDelta(99.00, (float) $remote[0]['selling_price'], 0.001);

        $this->hydrateAs(self::$documentB);

        $this->assertSame('99.00', Variant::query()->where('sku', 'REPRICE1')->sole()->selling_price);
    }

    public function test_a_staff_account_created_at_one_branch_can_log_in_at_the_other(): void
    {
        $this->hydrateAs(self::$documentA);

        $this->actingAs(User::query()->where('username', self::OWNER_USERNAME)->sole())
            ->post(route('users.store'), [
                'name' => 'Roaming Cashier',
                'username' => 'roaming',
                'role' => 'cashier',
                'password' => 'a-strong-password1',
                'password_confirmation' => 'a-strong-password1',
            ])->assertSessionHasNoErrors();

        $this->assertSame(1, (int) $this->remote("select count(*) as n from users where username = 'roaming'")[0]['n']);

        // §6's actual requirement: any user can log in at any branch. Which is
        // only true if the account reached the business database — before M6.5
        // it existed on exactly one machine.
        $this->hydrateAs(self::$documentB);

        $this->post('/login', ['username' => 'roaming', 'password' => 'a-strong-password1'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticated();
    }

    public function test_a_business_setting_changed_at_one_branch_reaches_the_other(): void
    {
        $this->hydrateAs(self::$documentA);

        $this->actingAs(User::query()->where('username', self::OWNER_USERNAME)->sole())
            ->patch(route('business-settings.update'), [
                'business_name' => 'Renamed From Branch A',
                'business_address' => '1 Test Street',
                'tin' => '000-000-000',
                'receipt_footer' => 'Salamat po!',
                'timezone' => 'Asia/Manila',
                'currency_symbol' => '₱',
            ])->assertSessionHasNoErrors();

        $this->assertSame(
            'Renamed From Branch A',
            $this->remote("select value from settings where key = 'business_name'")[0]['value'],
        );

        $this->hydrateAs(self::$documentB);

        $this->assertSame('Renamed From Branch A', Setting::query()->where('key', 'business_name')->value('value'));
    }

    public function test_a_csv_import_puts_the_catalog_into_the_business_database(): void
    {
        $this->hydrateAs(self::$documentA);

        $this->actingAs(User::query()->where('username', self::OWNER_USERNAME)->sole())
            ->post(route('items.import.store'), ['file' => $this->csv()])
            ->assertSessionHasNoErrors();

        // The catalog rows.
        $this->assertSame(1, (int) $this->remote("select count(*) as n from variants where sku = 'IMPORTED1'")[0]['n']);
        $this->assertSame(1, (int) $this->remote("select count(*) as n from categories where name = 'Imported'")[0]['n']);

        // The import history row too, which is why import_history gained INSERT.
        $this->assertSame(1, (int) $this->remote('select count(*) as n from import_history')[0]['n']);

        // An import touches no stock at all since M13 — not the ledger, not the
        // upstream cache, and not the queue. It describes products, and what is
        // on a shelf is a claim somebody at the branch has to make.
        $this->assertSame(
            0,
            (int) $this->remote("select count(*) as n from inventory_ledger where type = 'stock_adjustment'")[0]['n'],
        );
        $this->assertSame(0, (int) $this->remote('select count(*) as n from inventory_update_queue')[0]['n']);
        $this->assertSame(0, (int) $this->remote('select count(*) as n from stock_snapshots')[0]['n']);

        $this->hydrateAs(self::$documentB);

        // Branch B sees the product, because the catalog is business-wide.
        $this->assertSame(1, Variant::query()->where('sku', 'IMPORTED1')->count());
        $this->assertSame(0, DB::table('stock_snapshots')->count());
    }

    public function test_an_offline_device_writes_nothing_on_either_side(): void
    {
        $this->hydrateAs(self::$documentA);

        // A credential that cannot be reached: the probe fails, so the write is
        // refused before it starts.
        InstanceSecret::query()->delete();

        $this->actingAs(User::query()->where('username', self::OWNER_USERNAME)->sole())
            ->post(route('categories.store'), ['name' => 'Never Saved'])
            ->assertRedirect();

        $this->assertSame(0, Category::query()->where('name', 'Never Saved')->count());
        $this->assertSame(0, (int) $this->remote("select count(*) as n from categories where name = 'Never Saved'")[0]['n']);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Run the real wizard for $document on a device that knows nothing.
     *
     * Emptied row by row rather than with `migrate:fresh`, which wipes SQLite
     * with a VACUUM — and VACUUM is illegal inside a transaction, which is
     * exactly where RefreshDatabase puts every test. The immutability triggers
     * come down first because `audit_logs` and `login_history` refuse deletes by
     * design and the users they reference have to go; the schema is rebuilt for
     * the next test anyway.
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
            'sync_outbox', 'sync_status', 'audit_logs', 'login_history',
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
            '--branch-name=Second Branch',
            '--branch-code='.self::BRANCH_B,
            '--branch-address=2 Test Street',
            '--out='.$out,
            '--actor=online-write-test',
            '--force',
            '--no-interaction',
        ]);

        exec($command.' 2>&1', $output, $status);

        $this->assertSame(0, $status, "branch:provision failed:\n".implode("\n", $output));

        return json_decode((string) file_get_contents($out), true);
    }

    private function csv(): UploadedFile
    {
        $columns = InventoryCsvSchema::COLUMNS;

        $row = [
            'category' => 'Imported', 'product' => 'Imported Product', 'variant' => '',
            'sku' => 'IMPORTED1', 'barcode' => '', 'selling_price' => '15.00', 'cost' => '9.50',
            'unit' => 'pc', 'description' => '', 'low_stock_threshold' => '5',
        ];

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $columns, escape: '');
        fputcsv($handle, array_map(fn (string $c) => $row[$c] ?? '', $columns), escape: '');
        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return UploadedFile::fake()->createWithContent('inventory.csv', $contents);
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

    private function remoteCount(string $table): int
    {
        return (int) $this->remote("select count(*) as n from {$table}")[0]['n'];
    }

    /**
     * Back to the provisioned baseline. The schema is shared by every test in
     * this class and Postgres has no per-test rollback, so what one test writes
     * the next would otherwise read as its own.
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
