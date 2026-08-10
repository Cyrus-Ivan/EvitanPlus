<?php

namespace Tests\Integration;

use App\Models\Category;
use App\Models\Instance;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\Concerns\ProvisionsABusiness;
use Tests\TestCase;

/**
 * The M12 inventory scope against two real provisioned branches: **who has stock
 * of this, and how much.**
 *
 * ## Why this cannot be proved anywhere else
 *
 * tests/Feature/Inventory/InventoryScopeTest.php proves the boundary and the
 * fallback. What is left needs two branches' rows in one `stock_snapshots` table,
 * which no device holds: the local table has no `branch_id` at all, and
 * `StockReconciler` pulls only this branch's.
 *
 * The one that fails silently is `test_the_scoped_figure_is_not_the_local_one`.
 * `Variant::currentStock()` is a get-only cached accessor, so writing another
 * branch's number into `current_stock` lands in `$attributes`, is ignored by
 * `getAttribute()`, and renders THIS device's figure under that branch's heading
 * — a correct-looking screen, no error anywhere. Seeding the two sides to
 * different numbers is the only way to catch it.
 *
 * This phase adds nothing to `BranchGrants`: SELECT on `stock_snapshots` has been
 * in the matrix since M2 because the pull reads it.
 */
#[Group('supabase')]
class ConsolidatedStockTest extends TestCase
{
    use ProvisionsABusiness, RefreshDatabase;

    /** Each test sets its device up for itself, through the real wizard. */
    protected bool $hydrated = false;

    /** The real probe, the real credential, the real grants. */
    protected ?bool $connected = null;

    private const OWNER_USERNAME = 'stock-owner';

    private const BUSINESS_NAME = 'Stock Store';

    /** The branch the reading device IS. */
    private const BRANCH_A = 'KAA';

    /** The branch it can only see by asking the business database. */
    private const BRANCH_B = 'KBB';

    /** @var array<string, mixed>|null */
    private static ?array $documentA = null;

    /** @var array<string, mixed>|null */
    private static ?array $documentB = null;

    private static ?string $schema = null;

    private static ?string $roleA = null;

    private static ?string $roleB = null;

    private static ?PDO $admin = null;

    /** The variant both branches hold. */
    private string $stocked = '';

    /** A variant no branch has ever handled. */
    private string $untouched = '';

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

        $this->directory = storage_path('framework/testing/consolidated-stock');
        File::ensureDirectoryExists($this->directory);

        if (self::$documentA === null) {
            self::$admin = $this->adminConnection($env);
            self::$documentA = $this->provisionBusiness(self::BUSINESS_NAME, self::OWNER_USERNAME, self::BRANCH_A, 'stock-test');
            self::$schema = self::$documentA['business']['schema'];
            self::$roleA = self::$documentA['role']['name'];

            self::$documentB = $this->provisionSecondBranch();
            self::$roleB = self::$documentB['role']['name'];
        }

        $this->hydrateAs(self::$documentA);
        $this->createProducts();
        $this->seedShelves();
    }

    protected function tearDown(): void
    {
        $this->resetSharedData();

        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_a_single_branch_scope_shows_only_that_branch(): void
    {
        $this->actingAs($this->owner())
            ->get(route('inventory', ['scope' => self::$documentB['branch']['id']]))
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->where('scope', self::$documentB['branch']['id'])->where('consolidatedOnline', true);

                $variant = $this->variantOnPage($page, 'STOCKED');

                // Branch B's 3, and only B's — not the 12 on this device's own
                // shelf, and not the 15 the two of them add up to. The `where`
                // is the whole point of the read.
                $this->assertSame(3, $variant['scoped_stock']);
            });
    }

    /**
     * The trap this whole feature turns on.
     *
     * `current_stock` is a get-only cached accessor over the LOCAL snapshot, so
     * an implementation that wrote the scoped figure into it would be silently
     * ignored and the page would show 99 while claiming to show branch B.
     */
    public function test_the_scoped_figure_is_not_the_local_one(): void
    {
        DB::table('stock_snapshots')->updateOrInsert(
            ['variant_id' => $this->stocked],
            ['id' => (string) Str::uuid(), 'quantity' => 99, 'created_at' => now(), 'updated_at' => now()],
        );

        $this->actingAs($this->owner())
            ->get(route('inventory', ['scope' => self::$documentB['branch']['id']]))
            ->assertInertia(function ($page) {
                $variant = $this->variantOnPage($page, 'STOCKED');

                $this->assertSame(3, $variant['scoped_stock'], 'The scoped figure must come from upstream.');
                $this->assertSame(99, $variant['current_stock'], 'The local figure must be left exactly as it was.');
            });
    }

    public function test_a_variant_that_branch_has_never_handled_reads_zero(): void
    {
        // `RemoteStock::recompute()` only writes a row for a variant with ledger
        // movement, so "no row" is the ordinary state of a product nobody has
        // stocked yet — not a failure, and not a reason to leave the cell blank.
        // No row means none has moved, and none has moved means none on the shelf.
        $this->actingAs($this->owner())
            ->get(route('inventory', ['scope' => self::$documentB['branch']['id']]))
            ->assertInertia(function ($page) {
                $variant = $this->variantOnPage($page, 'UNTOUCHED');

                $this->assertSame(0, $variant['scoped_stock']);
            });
    }

    public function test_a_variant_this_device_never_handled_still_reads_the_other_branch(): void
    {
        // ONLY-B has no row at branch A at all, so this device's own figure is
        // zero and the scoped one is not. The page must show B's shelf rather
        // than fall back to the local answer for a variant it knows nothing about.
        $this->actingAs($this->owner())
            ->get(route('inventory', ['scope' => self::$documentB['branch']['id']]))
            ->assertInertia(function ($page) {
                $variant = $this->variantOnPage($page, 'ONLY-B');

                $this->assertSame(7, $variant['scoped_stock']);
                $this->assertSame(0, $variant['current_stock']);
            });
    }

    public function test_the_catalog_query_stays_local_under_a_wider_scope(): void
    {
        // Search, the category filter and the ordering are the local catalog's
        // work in every scope — the online read is one whereIn over the variant
        // ids already on the page.
        $this->actingAs($this->owner())
            ->get(route('inventory', ['scope' => self::$documentB['branch']['id'], 'search' => 'ONLY-B']))
            ->assertOk()
            ->assertInertia(function ($page) {
                $variants = $page->toArray()['props']['variants']['data'];

                $this->assertCount(1, $variants);
                $this->assertSame(7, $variants[0]['scoped_stock']);
            });
    }

    public function test_the_roster_arrives_with_the_stock_in_one_read(): void
    {
        $this->actingAs($this->owner())
            ->get(route('inventory', ['scope' => self::$documentB['branch']['id']]))
            ->assertInertia(fn ($page) => $page->where(
                'branches',
                fn ($branches) => collect($branches)->pluck('code')->all() === [self::BRANCH_B],
            ));
    }

    public function test_this_branchs_own_id_is_the_local_scope(): void
    {
        $this->actingAs($this->owner())
            ->get(route('inventory', ['scope' => self::$documentA['branch']['id']]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'local')
                ->where('consolidatedOnline', null)
            );
    }

    // ---------------------------------------------------------------- helpers

    private function owner(): User
    {
        return User::query()->where('username', self::OWNER_USERNAME)->sole();
    }

    /**
     * The serialised variant with this SKU, wherever it sits on the page.
     *
     * @return array<string, mixed>
     */
    /**
     * The page is one row per VARIANT since M13, so there is nothing to flatten
     * — but the lookup stays a helper because every test here names a product by
     * SKU rather than by position.
     */
    private function variantOnPage(mixed $page, string $sku): array
    {
        $variant = collect($page->toArray()['props']['variants']['data'])->firstWhere('sku', $sku);

        $this->assertNotNull($variant, "No variant [{$sku}] on the page.");

        return $variant;
    }

    /**
     * Three products, created the way an Owner would — online writes, so each
     * exists in BOTH databases and the local catalog can carry the upstream
     * figures.
     */
    private function createProducts(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('categories.store'), ['name' => 'Stocked'])->assertSessionHasNoErrors();

        $categoryId = Category::query()->where('name', 'Stocked')->value('id');

        foreach (['STOCKED', 'ONLY-B', 'UNTOUCHED'] as $sku) {
            $this->actingAs($owner)->post(route('items.store'), [
                'name' => 'Product '.$sku,
                'unit' => 'pc',
                'category_id' => $categoryId,
                'variants' => [[
                    'name' => null, 'sku' => $sku, 'barcode' => null,
                    'selling_price' => '10.00', 'cost' => '5.00', 'low_stock_threshold' => 0,
                ]],
            ])->assertSessionHasNoErrors();
        }

        $this->stocked = (string) Variant::query()->where('sku', 'STOCKED')->value('id');
        $this->untouched = (string) Variant::query()->where('sku', 'UNTOUCHED')->value('id');
    }

    /**
     * The two branches' shelves, written straight into Postgres.
     *
     * STOCKED: 12 at A, 3 at B. ONLY-B: 7 at B and no row at A. UNTOUCHED: no row
     * anywhere.
     */
    private function seedShelves(): void
    {
        $a = (string) self::$documentA['branch']['id'];
        $b = (string) self::$documentB['branch']['id'];
        $onlyB = (string) Variant::query()->where('sku', 'ONLY-B')->value('id');

        $this->seedSnapshot($a, $this->stocked, 12);
        $this->seedSnapshot($b, $this->stocked, 3);
        $this->seedSnapshot($b, $onlyB, 7);
    }

    private function seedSnapshot(string $branchId, string $variantId, int $quantity): void
    {
        $this->remoteExec(
            'insert into stock_snapshots (id, branch_id, variant_id, quantity, created_at, updated_at)
             values (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             on conflict (branch_id, variant_id) do update set quantity = excluded.quantity',
            [$this->uuid(), $branchId, $variantId, $quantity],
        );
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
            '--branch-name=Second Stock Branch',
            '--branch-code='.self::BRANCH_B,
            '--branch-address=2 Test Street',
            '--out='.$out,
            '--actor=stock-test',
            '--force',
            '--no-interaction',
        ]);

        exec($command.' 2>&1', $output, $status);

        $this->assertSame(0, $status, "branch:provision failed:\n".implode("\n", $output));

        return json_decode((string) file_get_contents($out), true);
    }

    /** @param  list<mixed>  $bindings */
    private function remoteExec(string $sql, array $bindings = []): void
    {
        self::$admin->exec('set search_path to "'.self::$schema.'"');

        self::$admin->prepare($sql)->execute($bindings);
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

        self::$admin->exec('truncate inventory_update_queue, inventory_ledger, stock_snapshots cascade');
        self::$admin->exec('delete from variants');
        self::$admin->exec('delete from items');
        self::$admin->exec('delete from categories');

        self::$admin->exec("delete from users where username <> '".self::OWNER_USERNAME."'");
    }
}
