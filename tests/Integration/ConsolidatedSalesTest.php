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
 * The M12 sales scope against two real provisioned branches: **what the other
 * branch sold, and what was in it.**
 *
 * ## Why this cannot be proved anywhere else
 *
 * tests/Feature/Sales/SalesScopeTest.php proves the permission boundary and the
 * fallback, which is everything a device with no credential can reach. What is
 * left is four differences between the two databases, and every one of them
 * fails SILENTLY:
 *
 *  - **`like` is case-sensitive in Postgres and not in SQLite.** A receipt search
 *    typed in lower case matches locally and returns an empty screen upstream.
 *    Nothing errors. `test_the_receipt_search_is_case_insensitive_upstream` is
 *    the only thing standing between that and a shipped build.
 *  - **`timestamptz` against business-local window bounds.** A naive bound is read
 *    in the session's UTC and moves the day eight hours, which sorts and renders
 *    perfectly and answers the wrong question.
 *  - **`numeric` arrives as an exact decimal string**, where SQLite hands back a
 *    float. A cast here would round money on one database and not the other.
 *  - **`withCount` does not exist on a query builder.** The line count is a second
 *    query, and a wrong join would have counted quantities or the whole table.
 *
 * Plus the one that is not a dialect difference at all: `cost` is Owner-only, and
 * `HidesCostFromNonOwners` is a MODEL hook that does not run on the query-builder
 * rows this path reads. The conditional select is the entire guard.
 *
 * ## Why the rows are seeded through the admin connection
 *
 * The push path is tests/Integration/PushTest.php's subject. Here the question is
 * what the screen READS, so both branches' sales are placed upstream directly —
 * including branch B's, which this device could never write.
 *
 * This phase adds nothing to `BranchGrants`: SELECT on all four sales tables has
 * been in the matrix since M2 because the push writes them. Nothing had read
 * them, and this test is what says so honestly.
 */
#[Group('supabase')]
class ConsolidatedSalesTest extends TestCase
{
    use ProvisionsABusiness, RefreshDatabase;

    /** Each test sets its device up for itself, through the real wizard. */
    protected bool $hydrated = false;

    /** The real probe, the real credential, the real grants. */
    protected ?bool $connected = null;

    private const OWNER_USERNAME = 'sales-owner';

    private const BUSINESS_NAME = 'Sales Store';

    /** The branch the reading device IS. */
    private const BRANCH_A = 'SAA';

    /** The branch it can only see by asking the business database. */
    private const BRANCH_B = 'SBB';

    /** The reported day, in business-local time. */
    private const DAY = '2026-05-20';

    /** @var array<string, mixed>|null */
    private static ?array $documentA = null;

    /** @var array<string, mixed>|null */
    private static ?array $documentB = null;

    private static ?string $schema = null;

    private static ?string $roleA = null;

    private static ?string $roleB = null;

    private static ?PDO $admin = null;

    /** The sale at branch B every detail test opens. */
    private string $remoteSale = '';

    private string $variantId = '';

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

        $this->directory = storage_path('framework/testing/consolidated-sales');
        File::ensureDirectoryExists($this->directory);

        if (self::$documentA === null) {
            self::$admin = $this->adminConnection($env);
            self::$documentA = $this->provisionBusiness(self::BUSINESS_NAME, self::OWNER_USERNAME, self::BRANCH_A, 'sales-test');
            self::$schema = self::$documentA['business']['schema'];
            self::$roleA = self::$documentA['role']['name'];

            self::$documentB = $this->provisionSecondBranch();
            self::$roleB = self::$documentB['role']['name'];
        }

        // The reading device is branch A throughout: the point of the phase is
        // that ONE till can see what every branch sold.
        $this->hydrateAs(self::$documentA);
        $this->createProduct();
        $this->seedTheBusinessDay();
    }

    protected function tearDown(): void
    {
        $this->resetSharedData();

        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_the_owner_sees_every_branchs_sales_attributed_to_the_right_branch(): void
    {
        $this->actingAs($this->owner())
            ->get(route('sales', ['scope' => 'all']))
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->component('sales', false)
                    ->where('scope', 'all')
                    ->where('consolidatedOnline', true);

                $props = $page->toArray()['props'];

                // Four sales across two branches, on a device whose own SQLite
                // transactions table is empty.
                $this->assertCount(4, $props['transactions']['data']);

                $byBranch = collect($props['transactions']['data'])->countBy('branch');

                $this->assertSame(2, $byBranch[self::BRANCH_A]);
                $this->assertSame(2, $byBranch[self::BRANCH_B]);

                // The roster minus this branch, which the picker already offers
                // as `local`.
                $this->assertSame(
                    [self::BRANCH_B],
                    collect($props['branches'])->pluck('code')->all(),
                );
            });
    }

    public function test_a_single_branch_scope_lists_only_that_branch(): void
    {
        $this->actingAs($this->owner())
            ->get(route('sales', ['scope' => self::$documentB['branch']['id']]))
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->where('scope', self::$documentB['branch']['id'])->where('consolidatedOnline', true);

                $rows = $page->toArray()['props']['transactions']['data'];

                $this->assertCount(2, $rows);
                $this->assertSame([self::BRANCH_B], collect($rows)->pluck('branch')->unique()->values()->all());
            });
    }

    public function test_the_item_count_is_the_number_of_lines_on_each_sale(): void
    {
        // The only exercise of the second `whereIn` query. `withCount` does not
        // exist on a query builder, and the two wrong answers a join would give —
        // the sum of quantities, or every line in the table — are both plausible
        // numbers on a sales list.
        $this->actingAs($this->owner())
            ->get(route('sales', ['scope' => 'all']))
            ->assertInertia(function ($page) {
                $counts = collect($page->toArray()['props']['transactions']['data'])
                    ->pluck('items_count', 'transaction_number');

                // Branch B's second sale has two lines of two units each; the
                // rest have one line. A quantity sum would read 4 and 2.
                $this->assertSame(2, $counts[self::BRANCH_B.'-0000000002']);
                $this->assertSame(1, $counts[self::BRANCH_B.'-0000000001']);
            });
    }

    public function test_the_receipt_search_is_case_insensitive_upstream(): void
    {
        // SQLite's LIKE is case-insensitive for ASCII and Postgres's is not, so a
        // remote path using `like` matches nothing here and errors nowhere. The
        // branch codes are upper case and this search is not.
        $this->actingAs($this->owner())
            ->get(route('sales', ['scope' => 'all', 'search' => strtolower(self::BRANCH_B)]))
            ->assertInertia(function ($page) {
                $rows = $page->toArray()['props']['transactions']['data'];

                $this->assertCount(2, $rows);
                $this->assertSame([self::BRANCH_B], collect($rows)->pluck('branch')->unique()->values()->all());
            });
    }

    public function test_the_day_filter_is_cut_on_the_business_day_not_the_utc_one(): void
    {
        // Branch B's second sale is at 00:30 the NEXT local day. In UTC it is the
        // same day as the 23:30 one (16:30 and 15:30), so a bound sent without its
        // offset would pull it into this day's list.
        $this->actingAs($this->owner())
            ->get(route('sales', ['scope' => 'all', 'date_from' => self::DAY, 'date_to' => self::DAY]))
            ->assertInertia(function ($page) {
                $rows = $page->toArray()['props']['transactions']['data'];

                // Branch A's two — the second at 23:59:59, the last instant this
                // schema can hold, which lands only if the upper bound includes
                // it — plus branch B's 23:30, and not its 00:30.
                $this->assertCount(3, $rows);

                $this->assertSame(
                    [self::DAY],
                    collect($rows)
                        ->map(fn (array $row) => substr((string) $row['created_at'], 0, 10))
                        ->unique()
                        ->values()
                        ->all(),
                );
            });

        $this->actingAs($this->owner())
            ->get(route('sales', ['scope' => 'all', 'date_from' => '2026-05-21', 'date_to' => '2026-05-21']))
            ->assertInertia(function ($page) {
                $rows = $page->toArray()['props']['transactions']['data'];

                $this->assertCount(1, $rows);
                $this->assertSame(self::BRANCH_B, $rows[0]['branch']);
            });
    }

    public function test_money_arrives_as_exact_decimal_strings(): void
    {
        // Postgres hands `numeric` back as a string and SQLite as a float. A cast
        // to float here would round differently on the two paths for the same
        // sale, which is the class of bug nobody finds by looking at a screen.
        $this->actingAs($this->owner())
            ->get(route('sales', ['scope' => self::$documentB['branch']['id']]))
            ->assertInertia(function ($page) {
                $row = collect($page->toArray()['props']['transactions']['data'])
                    ->firstWhere('transaction_number', self::BRANCH_B.'-0000000001');

                $this->assertIsString($row['total']);
                $this->assertSame('150.00', $row['total']);
                $this->assertIsString($row['discount']);
            });
    }

    public function test_a_remote_sale_opens_read_only_with_its_lines_and_returns(): void
    {
        $this->actingAs($this->owner())
            ->get(route('sales.show', ['transaction' => $this->remoteSale, 'scope' => 'all']))
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->component('sales/show', false)
                    ->where('readOnly', true)
                    ->where('branch', self::BRANCH_B);

                $transaction = $page->toArray()['props']['transaction'];

                $this->assertSame(self::BRANCH_B.'-0000000001', $transaction['transaction_number']);
                $this->assertCount(1, $transaction['items']);
                $this->assertSame('Consolidated Product', $transaction['items'][0]['label']);
                $this->assertSame(2, $transaction['items'][0]['quantity']);

                // The return against it, and the quantity it consumed — summed in
                // PHP from the lines already in hand rather than a sixth query.
                $this->assertCount(1, $transaction['returns']);
                $this->assertSame(1, $transaction['returns'][0]['quantity'] ?? 1);
                $this->assertSame(1, $transaction['items'][0]['returned_quantity']);
            });
    }

    public function test_cost_is_absent_for_an_admin_on_a_remote_sale(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('sales.show', ['transaction' => $this->remoteSale, 'scope' => 'all']))
            ->assertOk()
            ->assertInertia(function ($page) {
                $transaction = $page->toArray()['props']['transaction'];

                $page->where('canViewCost', false);

                // Absent, not null. `HidesCostFromNonOwners` is a model hook and
                // never runs on these rows, so the column list is the whole guard
                // — and a null would mean "no cost recorded", which is a
                // different and untrue statement.
                foreach ($transaction['items'] as $line) {
                    $this->assertArrayNotHasKey('cost', $line);
                }

                foreach ($transaction['returns'] as $return) {
                    foreach ($return['items'] as $line) {
                        $this->assertArrayNotHasKey('cost', $line);
                    }
                }
            });
    }

    public function test_cost_is_present_for_the_owner_on_a_remote_sale(): void
    {
        $this->actingAs($this->owner())
            ->get(route('sales.show', ['transaction' => $this->remoteSale, 'scope' => 'all']))
            ->assertInertia(function ($page) {
                $transaction = $page->toArray()['props']['transaction'];

                $page->where('canViewCost', true);

                $this->assertSame('40.00', $transaction['items'][0]['cost']);
                $this->assertSame('40.00', $transaction['returns'][0]['items'][0]['cost']);
            });
    }

    public function test_a_local_sale_still_opens_writable(): void
    {
        // The device holds no sales of its own, so this is the honest local case:
        // the id is not here, and without a scope that is a 404 rather than a
        // silent widening of the search to the business database.
        $this->actingAs($this->owner())
            ->get(route('sales.show', $this->remoteSale))
            ->assertNotFound();
    }

    public function test_this_branchs_own_id_is_the_local_scope(): void
    {
        // Not a consolidated read of branch A — the local mirror, which includes
        // sales this till has not pushed yet and survives the connection dropping.
        $this->actingAs($this->owner())
            ->get(route('sales', ['scope' => self::$documentA['branch']['id']]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'local')
                ->where('consolidatedOnline', null)
                ->has('transactions.data', 0)
            );
    }

    // ---------------------------------------------------------------- helpers

    private function owner(): User
    {
        return User::query()->where('username', self::OWNER_USERNAME)->sole();
    }

    /**
     * One product, created the way an Owner would — an online write, so it exists
     * in BOTH databases and the local catalog can name the upstream sale's lines.
     */
    private function createProduct(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('categories.store'), ['name' => 'Consolidated'])->assertSessionHasNoErrors();

        $this->actingAs($owner)->post(route('items.store'), [
            'name' => 'Consolidated Product',
            'unit' => 'pc',
            'category_id' => Category::query()->where('name', 'Consolidated')->value('id'),
            'variants' => [[
                'name' => null, 'sku' => 'CONS-1', 'barcode' => null,
                'selling_price' => '75.00', 'cost' => '40.00', 'low_stock_threshold' => 0,
            ]],
        ])->assertSessionHasNoErrors();

        $this->variantId = (string) Variant::query()->where('sku', 'CONS-1')->value('id');
    }

    /**
     * One business day of trading across two branches, written straight into
     * Postgres.
     *
     * Branch A: 09:00, and 23:59:59 — the last instant this schema can represent
     * (`timestampTz` takes Laravel's default precision of 0), which is what proves
     * the upper bound is inclusive right up to it.
     * Branch B: 23:30, with a return against it, and 00:30 the NEXT local day —
     * the row that catches a bound sent without its offset. Its second sale
     * carries two lines, which is what the count query is measured against.
     */
    private function seedTheBusinessDay(): void
    {
        $owner = (string) $this->owner()->getKey();
        $a = (string) self::$documentA['branch']['id'];
        $b = (string) self::$documentB['branch']['id'];

        $this->seedSale($a, self::BRANCH_A.'-0000000001', self::DAY.' 09:00:00', '75.00', $owner, 1);
        $this->seedSale($a, self::BRANCH_A.'-0000000002', self::DAY.' 23:59:59', '75.00', $owner, 1);

        $sale = $this->seedSale($b, self::BRANCH_B.'-0000000001', self::DAY.' 23:30:00', '150.00', $owner, 2);
        $this->remoteSale = $sale[0];

        $this->seedReturn($b, $sale, self::DAY.' 23:45:00', $owner);

        $this->seedSale($b, self::BRANCH_B.'-0000000002', '2026-05-21 00:30:00', '300.00', $owner, 2, lines: 2);
    }

    /**
     * @return array{0: string, 1: string} the transaction and its first line id
     */
    private function seedSale(
        string $branchId,
        string $number,
        string $localTime,
        string $total,
        string $userId,
        int $quantity,
        int $lines = 1,
    ): array {
        $transaction = $this->uuid();
        $at = $this->businessTime($localTime);

        $this->remoteExec(
            'insert into transactions (id, branch_id, transaction_number, payment_type, discount, total, user_id, created_at)
             values (?, ?, ?, ?, 0, ?, ?, ?)',
            [$transaction, $branchId, $number, 'cash', $total, $userId, $at],
        );

        $first = '';

        for ($i = 0; $i < $lines; $i++) {
            $line = $this->uuid();
            $first = $first === '' ? $line : $first;

            $this->remoteExec(
                'insert into transaction_items (id, transaction_id, variant_id, quantity, selling_price, cost, discount, total, created_at)
                 values (?, ?, ?, ?, ?, ?, 0, ?, ?)',
                [$line, $transaction, $this->variantId, $quantity, '75.00', '40.00', bcmul('75.00', (string) $quantity, 2), $at],
            );
        }

        return [$transaction, $first];
    }

    /** @param array{0: string, 1: string} $sale */
    private function seedReturn(string $branchId, array $sale, string $localTime, string $userId): void
    {
        $return = $this->uuid();
        $at = $this->businessTime($localTime);

        $this->remoteExec(
            'insert into sale_returns (id, branch_id, return_number, transaction_id, type, total_refund, performed_by, created_at)
             values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$return, $branchId, 'RET-20260520-000001', $sale[0], 'return', '75.00', $userId, $at],
        );

        $this->remoteExec(
            'insert into sale_return_items (id, sale_return_id, transaction_item_id, variant_id, quantity, selling_price, cost, refund_amount, created_at)
             values (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->uuid(), $return, $sale[1], $this->variantId, 1, '75.00', '40.00', '75.00', $at],
        );
    }

    /**
     * A business-local wall-clock time carrying its offset, which is what these
     * `timestamptz` columns are read against — a naive string would be taken as
     * UTC by the session and land eight hours out.
     */
    private function businessTime(string $localTime): string
    {
        return Carbon::parse($localTime, config('app.timezone'))->format('Y-m-d H:i:s.uP');
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
            '--branch-name=Second Sales Branch',
            '--branch-code='.self::BRANCH_B,
            '--branch-address=2 Test Street',
            '--out='.$out,
            '--actor=sales-test',
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

        // Truncate, not delete: the immutability triggers reject a DELETE on the
        // sales tables, which is the whole point of them.
        self::$admin->exec('truncate sale_return_items, sale_returns, transaction_items, transactions cascade');
        self::$admin->exec('truncate inventory_update_queue, inventory_ledger, stock_snapshots cascade');
        self::$admin->exec('delete from variants');
        self::$admin->exec('delete from items');
        self::$admin->exec('delete from categories');

        self::$admin->exec("delete from users where username <> '".self::OWNER_USERNAME."'");
    }
}
