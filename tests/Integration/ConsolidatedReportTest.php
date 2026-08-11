<?php

namespace Tests\Integration;

use App\Models\Category;
use App\Models\Instance;
use App\Models\User;
use App\Models\Variant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\Concerns\ProvisionsABusiness;
use Tests\TestCase;

/**
 * The M11 milestone against two real provisioned branches: **the Owner, online,
 * sees the business as one business — and the figures foot.**
 *
 * ## Why this cannot be proved anywhere else
 *
 * tests/Feature/Reports/SalesReportTest.php proves the arithmetic, but only over
 * SQLite, which is the one database a consolidated report never reads. The three
 * things that separate the two paths all fail silently, and all three are here:
 *
 *  - **`to_char(... at time zone ?)` instead of `strftime`.** A bucket key is a
 *    string the frontend parses; a wrong format string yields a plausible key
 *    that groups nothing correctly.
 *  - **`timestamptz` against business-local window bounds.** Postgres reads a
 *    naive bound in the SESSION timezone, which is UTC. Bind one without its
 *    offset and every figure shifts by eight hours — a shift that sorts, sums
 *    and renders perfectly, and reports the wrong day. Two sales here sit either
 *    side of local midnight for exactly that reason.
 *  - **`numeric` SUMs.** Postgres returns exact decimal strings where SQLite
 *    returns floats. Both have to land cent-exact through the same centavos
 *    reconciliation.
 *
 * And one thing no stand-in has at all: the **branch role's grants**. Reading
 * another branch's `transactions` is only possible because `BranchGrants::SELECT`
 * enumerates it. That is the only boundary of its kind (docs/glossary.md §9), so
 * a report that works here is a report the shipped app can actually run.
 *
 * ## Why the rows are seeded through the admin connection
 *
 * The push path is tests/Integration/PushTest.php's subject. Here the question is
 * what a report READS, so the branches' sales are placed upstream directly —
 * including branch B's, which this device could never write.
 */
#[Group('supabase')]
class ConsolidatedReportTest extends TestCase
{
    use ProvisionsABusiness, RefreshDatabase;

    /** Each test sets its device up for itself, through the real wizard. */
    protected bool $hydrated = false;

    /** The real probe, the real credential, the real grants. */
    protected ?bool $connected = null;

    private const OWNER_USERNAME = 'report-owner';

    private const BUSINESS_NAME = 'Report Store';

    /** The branch the reporting device IS. */
    private const BRANCH_A = 'RAA';

    /** The branch it can only see by asking the business database. */
    private const BRANCH_B = 'RBB';

    /** The reported day, in business-local time. */
    private const DAY = '2026-03-10';

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

        $this->directory = storage_path('framework/testing/consolidated');
        File::ensureDirectoryExists($this->directory);

        if (self::$documentA === null) {
            self::$admin = $this->adminConnection($env);
            self::$documentA = $this->provisionBusiness(self::BUSINESS_NAME, self::OWNER_USERNAME, self::BRANCH_A, 'report-test');
            self::$schema = self::$documentA['business']['schema'];
            self::$roleA = self::$documentA['role']['name'];

            self::$documentB = $this->provisionSecondBranch();
            self::$roleB = self::$documentB['role']['name'];
        }

        // The reporting device is branch A throughout: the point of the phase is
        // that ONE till sees every branch.
        $this->hydrateAs(self::$documentA);
        $this->seedTheBusinessDay();
    }

    protected function tearDown(): void
    {
        $this->resetSharedData();

        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_the_owner_sees_every_branch_combined_and_the_rows_foot_to_the_totals(): void
    {
        $this->actingAs($this->owner())
            ->get(route('reports', ['scope' => 'all', 'period' => 'daily', 'date' => self::DAY]))
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->component('reports', false)
                    ->where('scope', 'all')
                    ->where('consolidatedOnline', true)
                    ->where('canViewCost', true);

                $props = $page->toArray()['props'];

                // 200 + 50 sold, 100 returned, 25 defected — across two branches
                // and two devices, neither of which holds the other's sales.
                $this->assertSame('250.00', $props['totals']['gross_sales']);
                $this->assertSame('100.00', $props['totals']['returns']);
                $this->assertSame('25.00', $props['totals']['defects']);
                $this->assertSame('125.00', $props['totals']['net_sales']);

                // Cost is Owner-only EVEN HERE. COGS is not reported, but profit
                // is net of it: sold cost 80 + 10, less the 40 credited back by the
                // return; the defect's cost stays written off, so COGS is 50.00.
                $this->assertSame('75.00', $props['totals']['profit']);

                // The per-branch cut is the same recompute over a different GROUP
                // BY, so it has to foot to the figures above.
                $branches = collect($props['branchRows'])->keyBy('code');

                $this->assertSame([self::BRANCH_A, self::BRANCH_B], $branches->keys()->sort()->values()->all());
                $this->assertSame('200.00', $branches[self::BRANCH_A]['gross_sales']);
                $this->assertSame('100.00', $branches[self::BRANCH_A]['returns']);
                $this->assertSame('100.00', $branches[self::BRANCH_A]['net_sales']);
                $this->assertSame('50.00', $branches[self::BRANCH_B]['gross_sales']);
                $this->assertSame('25.00', $branches[self::BRANCH_B]['defects']);
                $this->assertSame('25.00', $branches[self::BRANCH_B]['net_sales']);

                $this->assertSame(
                    $props['totals']['net_sales'],
                    number_format(collect($props['branchRows'])->sum(fn (array $row) => (float) $row['net_sales']), 2, '.', ''),
                );
            });
    }

    public function test_buckets_are_cut_on_the_business_day_not_the_utc_one(): void
    {
        // Branch B's two sales sit either side of local midnight: 23:30 on the
        // reported day, and 00:30 on the next one. In UTC both are the SAME day
        // (15:30 and 16:30), so a window bound sent without its offset would
        // pull the second into this report and bucket the first at hour 15.
        $this->actingAs($this->owner())
            ->get(route('reports', ['scope' => 'all', 'period' => 'daily', 'date' => self::DAY]))
            ->assertOk()
            ->assertInertia(function ($page) {
                $buckets = collect($page->toArray()['props']['rows'])->keyBy('bucket');

                $this->assertTrue($buckets->has(self::DAY.' 23'), 'The 23:30 local sale did not bucket at hour 23.');
                $this->assertSame('50.00', $buckets[self::DAY.' 23']['gross_sales']);
                $this->assertSame('200.00', $buckets[self::DAY.' 09']['gross_sales']);
                $this->assertSame('100.00', $buckets[self::DAY.' 10']['returns']);

                // The next day's sale is not in this report at any hour.
                $this->assertSame('250.00', collect($page->toArray()['props']['totals'])['gross_sales']);
            });

        // And it IS in the next day's, which is the other half of the same fact.
        $this->actingAs($this->owner())
            ->get(route('reports', ['scope' => 'all', 'period' => 'daily', 'date' => '2026-03-11']))
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];

                $this->assertSame('999.00', $props['totals']['gross_sales']);
                $this->assertSame('2026-03-11 00', $props['rows'][0]['bucket']);
            });
    }

    public function test_a_single_branch_scope_reports_only_that_branch(): void
    {
        $this->actingAs($this->owner())
            ->get(route('reports', [
                'scope' => self::$documentB['branch']['id'],
                'period' => 'daily',
                'date' => self::DAY,
            ]))
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];

                $page->where('scope', self::$documentB['branch']['id'])->where('consolidatedOnline', true);

                $this->assertSame('50.00', $props['totals']['gross_sales']);
                $this->assertSame('25.00', $props['totals']['defects']);
                $this->assertSame('25.00', $props['totals']['net_sales']);

                // Narrowed to one branch, the per-branch table has one row — and
                // it is branch B's, not the device's own.
                $this->assertCount(1, $props['branchRows']);
                $this->assertSame(self::BRANCH_B, $props['branchRows'][0]['code']);
            });
    }

    public function test_the_chart_can_be_stacked_by_branch_and_reconciles_to_the_branch_rows(): void
    {
        $this->actingAs($this->owner())
            ->get(route('reports', ['scope' => 'all', 'by' => 'branch', 'period' => 'daily', 'date' => self::DAY]))
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];

                $page->where('chartBy', 'branch');

                // Series are branches, named by code from the roster rather than
                // by joining `branches` into every aggregate.
                $this->assertSame(
                    [self::BRANCH_A, self::BRANCH_B],
                    collect($props['chartItems'])->pluck('name')->sort()->values()->all(),
                );

                $keys = collect($props['chartItems'])->pluck('key', 'name');

                // Every segment of every bucket, summed per series, is that
                // branch's net for the period.
                $perBranch = [];
                foreach ($props['chart'] as $bucket) {
                    foreach ((array) $bucket['values'] as $key => $value) {
                        $perBranch[$key] = ($perBranch[$key] ?? 0) + (float) $value;
                    }
                }

                $this->assertSame(100.0, $perBranch[$keys[self::BRANCH_A]]);
                $this->assertSame(25.0, $perBranch[$keys[self::BRANCH_B]]);
            });
    }

    public function test_the_branch_local_report_still_sees_only_this_device(): void
    {
        // The milestone's other half: offline figures stay branch-local. Nothing
        // was ever written to this device's SQLite, so its own report is empty
        // even though the business database is full.
        $this->actingAs($this->owner())
            ->get(route('reports', ['period' => 'daily', 'date' => self::DAY]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'local')
                ->where('consolidatedOnline', null)
                ->where('totals.gross_sales', '0.00')
                ->where('branchRows', null)
            );
    }

    // ---------------------------------------------------------------- helpers

    private function owner(): User
    {
        return User::query()->where('username', self::OWNER_USERNAME)->sole();
    }

    /**
     * One business day across two branches, written straight into Postgres.
     *
     * Branch A: a ₱200 sale at 09:15, and a ₱100 return of half of it at 10:00.
     * Branch B: a ₱50 sale at 23:30 with a ₱25 defect at 23:45, plus a ₱999 sale
     * at 00:30 the NEXT local day — the row that catches a mis-bound window.
     */
    private function seedTheBusinessDay(): void
    {
        $this->createProduct('RPT1', '100.00', '40.00');

        $variant = (string) Variant::query()->where('sku', 'RPT1')->sole()->getKey();
        $owner = (string) $this->owner()->getKey();
        $a = (string) self::$documentA['branch']['id'];
        $b = (string) self::$documentB['branch']['id'];

        $saleA = $this->seedSale($a, 'RAA-0000000001', self::DAY.' 09:15:00', '200.00', $variant, 2, '100.00', '40.00', $owner);
        $saleB = $this->seedSale($b, 'RBB-0000000001', self::DAY.' 23:30:00', '50.00', $variant, 1, '50.00', '10.00', $owner);
        $this->seedSale($b, 'RBB-0000000002', '2026-03-11 00:30:00', '999.00', $variant, 1, '999.00', '10.00', $owner);

        $this->seedReturn($a, 'RAA-R-0000000001', self::DAY.' 10:00:00', 'return', '100.00', $saleA, $variant, 1, '100.00', '40.00', $owner);
        $this->seedReturn($b, 'RBB-R-0000000001', self::DAY.' 23:45:00', 'defect', '25.00', $saleB, $variant, 1, '25.00', '10.00', $owner);
    }

    /**
     * One sale and its single line. Times are business-local and written with
     * the business offset, which is what the columns are: `timestamptz`.
     *
     * @return array{0: string, 1: string} the transaction and line ids
     */
    private function seedSale(
        string $branchId,
        string $number,
        string $localTime,
        string $total,
        string $variantId,
        int $quantity,
        string $sellingPrice,
        string $cost,
        string $userId,
    ): array {
        $transaction = $this->uuid();
        $line = $this->uuid();
        $at = $this->businessTime($localTime);

        $this->remoteExec(
            'insert into transactions (id, branch_id, transaction_number, payment_type, discount, total, user_id, created_at)
             values (?, ?, ?, ?, 0, ?, ?, ?)',
            [$transaction, $branchId, $number, 'cash', $total, $userId, $at],
        );

        $this->remoteExec(
            'insert into transaction_items (id, transaction_id, variant_id, quantity, selling_price, cost, discount, total, created_at)
             values (?, ?, ?, ?, ?, ?, 0, ?, ?)',
            [$line, $transaction, $variantId, $quantity, $sellingPrice, $cost, bcmul($sellingPrice, (string) $quantity, 2), $at],
        );

        return [$transaction, $line];
    }

    /**
     * An after-sales event and its single line. `$type` is 'return' or 'defect':
     * one table, one column, per docs/returns-refunds-voids-design.md.
     *
     * @param  array{0: string, 1: string}  $sale
     */
    private function seedReturn(
        string $branchId,
        string $number,
        string $localTime,
        string $type,
        string $refund,
        array $sale,
        string $variantId,
        int $quantity,
        string $sellingPrice,
        string $cost,
        string $userId,
    ): void {
        $return = $this->uuid();
        $at = $this->businessTime($localTime);

        $this->remoteExec(
            'insert into sale_returns (id, branch_id, return_number, transaction_id, type, total_refund, performed_by, created_at)
             values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$return, $branchId, $number, $sale[0], $type, $refund, $userId, $at],
        );

        $this->remoteExec(
            'insert into sale_return_items (id, sale_return_id, transaction_item_id, variant_id, quantity, selling_price, cost, refund_amount, created_at)
             values (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->uuid(), $return, $sale[1], $variantId, $quantity, $sellingPrice, $cost, $refund, $at],
        );
    }

    /** A business-local wall-clock time, carrying the offset Postgres needs. */
    private function businessTime(string $localTime): string
    {
        return Carbon::parse($localTime, config('app.timezone'))->format('Y-m-d H:i:sP');
    }

    private function createProduct(string $sku, string $price, string $cost): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('categories.store'), ['name' => 'Reported'])->assertSessionHasNoErrors();

        $this->actingAs($owner)->post(route('items.store'), [
            'name' => 'Product '.$sku,
            'unit' => 'pc',
            'category_id' => Category::query()->where('name', 'Reported')->value('id'),
            'variants' => [[
                'name' => null, 'sku' => $sku, 'barcode' => null,
                'selling_price' => $price, 'cost' => $cost, 'low_stock_threshold' => 0,
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
            '--branch-name=Second Report Branch',
            '--branch-code='.self::BRANCH_B,
            '--branch-address=2 Test Street',
            '--out='.$out,
            '--actor=report-test',
            '--force',
            '--no-interaction',
        ]);

        exec($command.' 2>&1', $output, $status);

        $this->assertSame(0, $status, "branch:provision failed:\n".implode("\n", $output));

        return json_decode((string) file_get_contents($out), true);
    }

    /** @param list<mixed> $bindings */
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

        // Truncate, not delete: the immutability triggers reject a DELETE on
        // transactions, sale_returns and their lines, which is the whole point
        // of them.
        self::$admin->exec(
            'truncate sale_return_items, sale_returns, transaction_items, transactions,
                      inventory_update_queue, stock_snapshots, inventory_ledger, import_history,
                      variants, items, categories cascade'
        );

        self::$admin->exec("delete from users where username <> '".self::OWNER_USERNAME."'");
    }
}
