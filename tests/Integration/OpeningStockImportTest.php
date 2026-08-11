<?php

namespace Tests\Integration;

use App\Models\Instance;
use App\Models\SyncStatus;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use App\Support\Sync\SyncCycle;
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
 * Opening stock against a real provisioned business, which is the only place
 * the thing that matters can be proved.
 *
 * ## The failure this exists to catch
 *
 * Postgres has no trigger behind `stock_snapshots`; the till maintains it, and
 * `RemoteStock::recompute()` rebuilds it by summing **Postgres's own**
 * `inventory_ledger`. So a path that puts opening stock into a business schema
 * must write a LEDGER ENTRY and not just a number — otherwise the recompute sums
 * a ledger the movement is missing from, the branch's first sale takes the total
 * negative, `stock_snapshots_non_negative` refuses the whole cycle, and the
 * branch can never sync again.
 *
 * That is not hypothetical. It is the M7 rig defect, verbatim: `tests/manual/
 * pg.php` seeded snapshots with no ledger behind them and no rig branch had ever
 * been able to sync a sale. Every automated suite seeded its own opening stock
 * correctly, so nothing caught it.
 *
 * The feature test proves the arithmetic and the ordering against a SQLite
 * stand-in. What it cannot reach is the check constraint, the branch role's
 * grants on these tables, and `timestamptz` — the three things that fail
 * silently. Hence a full {@see SyncCycle::run()} at the end of the main test: the
 * assertion is not that the numbers are right but that **the branch is still
 * able to sync afterwards.**
 *
 * Needs no grant change. `inventory_ledger` has had INSERT since M2 and
 * `stock_snapshots` INSERT/UPDATE since M6 — this writes nothing new, it writes
 * the same rows from a different door.
 */
#[Group('supabase')]
class OpeningStockImportTest extends TestCase
{
    use ProvisionsABusiness, RefreshDatabase;

    /** Each test sets its device up for itself, through the real wizard. */
    protected bool $hydrated = false;

    /** The real probe, the real credential, the real grants. */
    protected ?bool $connected = null;

    private const OWNER_USERNAME = 'opening-owner';

    private const BUSINESS_NAME = 'Opening Stock Store';

    private const BRANCH = 'OPN';

    /** @var array<string, mixed>|null */
    private static ?array $document = null;

    private static ?string $schema = null;

    private static ?string $role = null;

    private static ?PDO $admin = null;

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

        $this->directory = storage_path('framework/testing/opening-stock');
        File::ensureDirectoryExists($this->directory);

        if (self::$document === null) {
            self::$admin = $this->adminConnection($env);
            self::$document = $this->provisionBusiness(self::BUSINESS_NAME, self::OWNER_USERNAME, self::BRANCH, 'opening-stock-test');
            self::$schema = self::$document['business']['schema'];
            self::$role = self::$document['role']['name'];
        }
    }

    protected function tearDown(): void
    {
        $this->resetSharedData();

        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    /**
     * The whole chain, in the order a real onboarding runs it: provision, wizard,
     * items file, opening-stock file, sync.
     */
    public function test_opening_stock_lands_upstream_and_the_branch_can_still_sync(): void
    {
        $this->hydrateAs(self::$document);

        $owner = User::query()->where('username', self::OWNER_USERNAME)->sole();

        // A brand-new business hydrates an empty catalog, so the products come
        // first — through the real item import, which is the other half of the
        // two-file design.
        $this->actingAs($owner)
            ->post(route('items.import.store'), ['file' => $this->itemsCsv()])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->remoteCount('stock_snapshots'));

        $this->actingAs($owner)
            ->post(route('opening-stock.store'), ['scope' => 'local', 'file' => $this->openingCsv(['OPEN-001' => 24, 'OPEN-002' => 0])])
            ->assertSessionHasNoErrors();

        // Locally.
        $variant = Variant::query()->where('sku', 'OPEN-001')->sole();
        $this->assertSame(24, app(StockLedger::class)->currentStock($variant));

        // Upstream: the movement, then the snapshot recomputed from it.
        $ledger = $this->remote('select quantity, type, reference_type from inventory_ledger where variant_id = ?', [$variant->getKey()]);
        $this->assertCount(1, $ledger);
        $this->assertSame(24, (int) $ledger[0]['quantity']);
        $this->assertSame('stock_adjustment', $ledger[0]['type']);
        $this->assertSame('csv_import', $ledger[0]['reference_type']);

        $snapshot = $this->remote('select quantity from stock_snapshots where variant_id = ?', [$variant->getKey()]);
        $this->assertCount(1, $snapshot);
        $this->assertSame(24, (int) $snapshot[0]['quantity']);

        // The zero line wrote nothing at either end — the absence of a movement,
        // not a movement of nothing. A snapshot here with no ledger behind it is
        // exactly what makes a branch unable to sync.
        $zero = Variant::query()->where('sku', 'OPEN-002')->sole();
        $this->assertCount(0, $this->remote('select 1 from stock_snapshots where variant_id = ?', [$zero->getKey()]));
        $this->assertCount(0, $this->remote('select 1 from inventory_ledger where variant_id = ?', [$zero->getKey()]));

        // The assertion the whole file is for. A cycle that completes is a branch
        // whose ledger and snapshots agree well enough for Postgres to accept the
        // next thing it says.
        $status = app(SyncCycle::class)->run();
        $this->assertSame(
            SyncStatus::ONLINE,
            $status['state'],
            'The cycle failed after an opening-stock import: '.($status['error'] ?? ''),
        );
    }

    /**
     * The history row reaches Postgres too, so a rebuilt device can show what it
     * was told. `import_history` gained INSERT in M6.5 for exactly this.
     */
    public function test_the_import_history_row_reaches_the_business_database(): void
    {
        $this->hydrateAs(self::$document);

        $owner = User::query()->where('username', self::OWNER_USERNAME)->sole();

        $this->actingAs($owner)->post(route('items.import.store'), ['file' => $this->itemsCsv()]);
        $this->actingAs($owner)->post(route('opening-stock.store'), ['scope' => 'local', 'file' => $this->openingCsv(['OPEN-001' => 5])]);

        $rows = $this->remote("select mode, status from import_history where mode = 'opening_stock'");

        $this->assertCount(1, $rows);
        $this->assertSame('success', $rows[0]['status']);
    }

    /**
     * A second run of the same file is a no-op at both ends, which is what makes
     * a mistyped opening count survivable.
     */
    public function test_re_running_the_file_writes_nothing_upstream(): void
    {
        $this->hydrateAs(self::$document);

        $owner = User::query()->where('username', self::OWNER_USERNAME)->sole();

        $this->actingAs($owner)->post(route('items.import.store'), ['file' => $this->itemsCsv()]);
        $this->actingAs($owner)->post(route('opening-stock.store'), ['scope' => 'local', 'file' => $this->openingCsv(['OPEN-001' => 24])]);
        $this->actingAs($owner)->post(route('opening-stock.store'), ['scope' => 'local', 'file' => $this->openingCsv(['OPEN-001' => 24])])
            ->assertSessionHasNoErrors();

        $variant = Variant::query()->where('sku', 'OPEN-001')->sole();

        $this->assertSame(
            1,
            (int) $this->remote('select count(*) as n from inventory_ledger where variant_id = ?', [$variant->getKey()])[0]['n'],
        );
        $this->assertSame(
            24,
            (int) $this->remote('select quantity from stock_snapshots where variant_id = ?', [$variant->getKey()])[0]['quantity'],
        );
    }

    // ------------------------------------------------------------------ fixtures

    /** Two products, both at zero, because that is what a new business has. */
    private function itemsCsv(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('items.csv', implode("\n", [
            'category,product,variant,sku,barcode,selling_price,cost,unit,description,low_stock_threshold',
            'Opening,Opening Product One,,OPEN-001,,15.00,9.00,pc,,5',
            'Opening,Opening Product Two,,OPEN-002,,20.00,12.00,pc,,5',
            '',
        ]));
    }

    /** @param array<string, int> $quantities Keyed by SKU. */
    private function openingCsv(array $quantities): UploadedFile
    {
        $lines = ['sku,quantity'];

        foreach ($quantities as $sku => $quantity) {
            $lines[] = $sku.','.$quantity;
        }

        return UploadedFile::fake()->createWithContent('opening-stock.csv', implode("\n", [...$lines, '']));
    }

    // ------------------------------------------------------------------- harness

    /**
     * Put this device through the real setup wizard as the given branch.
     *
     * Copied rather than shared, as every file in this directory does: the
     * table list is what THIS test needs cleared between devices, and a shared
     * version would be a list nobody could safely change.
     *
     * @param  array<string, mixed>  $document
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
            'truncate stock_snapshots, inventory_ledger, inventory_update_queue, import_history, variants, items, categories cascade'
        );

        self::$admin->exec("delete from users where username <> '".self::OWNER_USERNAME."'");
    }
}
