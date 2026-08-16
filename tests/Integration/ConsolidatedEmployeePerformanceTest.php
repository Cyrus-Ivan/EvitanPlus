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
 * Employee performance across two real provisioned branches: **what one person
 * worked and sold, wherever they did it.**
 *
 * ## Why this cannot be proved anywhere else
 *
 * tests/Feature/EmployeePerformance/EmployeePerformanceTest.php proves the
 * arithmetic, the pairing and the permission boundary by pointing the online read
 * at the local connection. What is left is the half that fails SILENTLY:
 *
 *  - **`timestamptz` against business-local window bounds**, in TWO queries that
 *    derive their bounds independently: the attendance read and the sales
 *    recompute. Postgres reads a naive bound in the session's UTC, which moves
 *    the month eight hours — a calendar that renders perfectly and covers the
 *    wrong nights. The fixture puts a shift and a sale either side of local
 *    month-end for exactly that reason.
 *  - **The refund's employee join.** A refund is attributed to the cashier who
 *    made the SALE, which means `sale_returns` reaches the seller through
 *    `transactions` — a join that only exists when a user is scoped, and only
 *    runs against the branch-filtered upstream tables here.
 *  - **`numeric` arrives as an exact decimal string**, where SQLite hands back a
 *    float; every money figure is reconciled through integer centavos.
 *  - **`login_history` has a `branch_id` upstream and none locally**, so the
 *    branch code printed on every shift — and the hours-by-branch split under the
 *    summary — come from a join that only runs on Postgres and can only be seen
 *    to work here.
 *  - **That there is no local fallback left.** This device's SQLite is empty
 *    throughout, so a screen that still preferred the mirror would report a month
 *    of zeros instead of failing loudly.
 *
 * ## Why the rows are seeded through the admin connection
 *
 * The push path is tests/Integration/PushTest.php's subject. Here the question is
 * what the screen READS, so both branches' shifts and sales are placed upstream
 * directly — including branch B's, which this device could never write.
 *
 * This phase adds nothing to `BranchGrants`: SELECT on `login_history` and all
 * four sales tables has been in the matrix since M2. Nothing had asked this
 * question of them, and this test is what says so honestly.
 */
#[Group('supabase')]
class ConsolidatedEmployeePerformanceTest extends TestCase
{
    use ProvisionsABusiness, RefreshDatabase;

    /** Each test sets its device up for itself, through the real wizard. */
    protected bool $hydrated = false;

    /** The real probe, the real credential, the real grants. */
    protected ?bool $connected = null;

    private const OWNER_USERNAME = 'staff-owner';

    private const BUSINESS_NAME = 'Staff Store';

    /** The branch the reading device IS. */
    private const BRANCH_A = 'EAA';

    /** The branch it can only see by asking the business database. */
    private const BRANCH_B = 'EBB';

    /** The reported month, in business-local time. */
    private const MONTH = 6;

    private const YEAR = 2026;

    /** @var array<string, mixed>|null */
    private static ?array $documentA = null;

    /** @var array<string, mixed>|null */
    private static ?array $documentB = null;

    private static ?string $schema = null;

    private static ?string $roleA = null;

    private static ?string $roleB = null;

    private static ?PDO $admin = null;

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

        $this->directory = storage_path('framework/testing/employee-performance');
        File::ensureDirectoryExists($this->directory);

        if (self::$documentA === null) {
            self::$admin = $this->adminConnection($env);
            self::$documentA = $this->provisionBusiness(self::BUSINESS_NAME, self::OWNER_USERNAME, self::BRANCH_A, 'staff-test');
            self::$schema = self::$documentA['business']['schema'];
            self::$roleA = self::$documentA['role']['name'];

            self::$documentB = $this->provisionSecondBranch();
            self::$roleB = self::$documentB['role']['name'];
        }

        // The reading device is branch A throughout: the point of the scope is
        // that ONE till can report on a person who works somewhere else.
        $this->hydrateAs(self::$documentA);
        $this->createProduct();
        $this->seedTheBusinessMonth();
    }

    protected function tearDown(): void
    {
        $this->resetSharedData();

        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_the_month_covers_every_branch_the_employee_worked_at(): void
    {
        $this->month()
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->component('employee-performance', false)
                    ->where('online', true)
                    ->has('days', 30);

                $summary = $page->toArray()['props']['summary'];

                // Two shifts at two branches: 08:00–17:00 at A on the 10th (9h),
                // and 23:30–03:30 at B on the 30th (4h), which is ONE shift on
                // the 30th rather than two either side of midnight.
                $this->assertSame(2, $summary['days_worked']);
                $this->assertSame(2, $summary['shifts']);
                // assertEquals, not assertSame: json_encode drops the zero fraction of a
                // whole float, so 13.0 crosses the wire as 13 and comes back an int.
                $this->assertEquals(13.0, $summary['hours']);
                $this->assertSame(0, $summary['open_shifts']);

                // Three sales inside June (two at A, one at B), one 75.00 return
                // against the B sale, and the 00:30 July sale excluded.
                $this->assertSame(3, $summary['transactions']);
                $this->assertSame('300.00', $summary['gross_sales']);
                $this->assertSame('75.00', $summary['returns']);
                $this->assertSame('225.00', $summary['net_sales']);
                $this->assertSame('17.31', $summary['sales_per_hour']); // 225.00 over 13 hours
            });
    }

    public function test_each_shift_says_which_branch_it_was_worked_at(): void
    {
        // The reason the report has no branch filter at all: one person, one
        // month, two branches. A month that did not say which day was which
        // would read as one undifferentiated block.
        //
        // The code comes from a LEFT JOIN onto `branches` that only runs on
        // Postgres — the local `login_history` has no `branch_id` and never will
        // — so this is the only place it can be proved.
        $this->month()
            ->assertInertia(function ($page) {
                $days = collect($page->toArray()['props']['days'])->keyBy('date');

                $this->assertSame(self::BRANCH_A, $days['2026-06-10']['shifts'][0]['branch']);
                $this->assertSame(self::BRANCH_B, $days['2026-06-30']['shifts'][0]['branch']);

                // And the split of the total, which foots to it exactly: 9 hours
                // at A on the 10th, 4 at B on the 30th, 13 in the summary above.
                $this->assertSame(
                    [['code' => self::BRANCH_A, 'hours' => 9], ['code' => self::BRANCH_B, 'hours' => 4]],
                    $page->toArray()['props']['summary']['hours_by_branch'],
                );
            });
    }

    public function test_the_month_is_cut_on_the_business_clock_not_the_utc_one(): void
    {
        // Branch B's night shift starts at 23:30 on June 30 and ends at 03:30 on
        // July 1; its second sale is at 00:30 on July 1. In UTC all three are
        // hours apart from where they look, so a window bound sent without its
        // offset would pull July's sale into June and push the shift out of it.
        // Branch A's 23:59:59 sale is the other edge: the last instant this
        // schema can represent, which the upper bound must include.
        $this->month()
            ->assertInertia(function ($page) {
                $days = collect($page->toArray()['props']['days'])->keyBy('date');

                $thirtieth = $days['2026-06-30'];

                // One shift, on the day it began, ending the next day.
                $this->assertCount(1, $thirtieth['shifts']);
                $this->assertTrue($thirtieth['shifts'][0]['crosses_midnight']);
                $this->assertEquals(4.0, $thirtieth['hours']);

                // Both branches' June 30 sales, and not July's 300.00.
                $this->assertSame(2, $thirtieth['transactions']);
                $this->assertSame('225.00', $thirtieth['gross_sales']);
                $this->assertSame('75.00', $thirtieth['returns']);
                $this->assertSame('150.00', $thirtieth['net_sales']);
            });

        // And July's sale IS in July's, which is the other half of the same fact.
        $this->month(['month' => 7])
            ->assertInertia(function ($page) {
                $summary = $page->toArray()['props']['summary'];

                $this->assertSame(1, $summary['transactions']);
                $this->assertSame('300.00', $summary['net_sales']);
                // The shift that ended on July 1 belongs to June, which already
                // reported it. July sees a sign-out with no sign-in and discards
                // it rather than inventing a shift with no beginning.
                $this->assertSame(0, $summary['shifts']);
            });
    }

    public function test_the_shift_times_arrive_in_business_local_time(): void
    {
        // `timestamptz` against a UTC session: an unconverted read would show the
        // 23:30 sign-in as 15:30 — a plausible time that is simply the wrong one,
        // on the wrong day, in the wrong calendar cell.
        $this->month()
            ->assertInertia(function ($page) {
                $shift = collect($page->toArray()['props']['days'])->firstWhere('date', '2026-06-30')['shifts'][0];

                $this->assertSame(
                    '2026-06-30 23:30',
                    Carbon::parse($shift['start'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i'),
                );
                $this->assertSame(
                    '2026-07-01 03:30',
                    Carbon::parse($shift['end'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i'),
                );
            });
    }

    public function test_the_device_never_reports_its_own_empty_mirror(): void
    {
        // The proof that the local read is really gone. Nothing was written to
        // this device's SQLite — every row of this fixture was placed upstream —
        // so if any part of the screen still fell back to the mirror, or ever
        // preferred it, this month would come back empty. It does not.
        //
        // This is the failure the whole design refuses: a calendar that renders
        // perfectly and says a person who worked two branches never came in.
        $this->assertSame(0, DB::table('login_history')->count());
        $this->assertSame(0, DB::table('transactions')->count());

        $this->month()
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->where('online', true);

                $summary = $page->toArray()['props']['summary'];

                $this->assertSame(2, $summary['days_worked']);
                $this->assertSame(3, $summary['transactions']);
                $this->assertSame('225.00', $summary['net_sales']);
            });
    }

    // ---------------------------------------------------------------- helpers

    private function month(array $params = [])
    {
        return $this->actingAs($this->owner())->get(route('employee-performance', array_merge([
            'user' => $this->owner()->getKey(),
            'month' => self::MONTH,
            'year' => self::YEAR,
        ], $params)));
    }

    private function owner(): User
    {
        return User::query()->where('username', self::OWNER_USERNAME)->sole();
    }

    /**
     * One product, created the way an Owner would — an online write, so it exists
     * in BOTH databases and the upstream sale's lines have something to point at.
     */
    private function createProduct(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('categories.store'), ['name' => 'Staffed'])->assertSessionHasNoErrors();

        $this->actingAs($owner)->post(route('items.store'), [
            'name' => 'Staffed Product',
            'unit' => 'pc',
            'category_id' => Category::query()->where('name', 'Staffed')->value('id'),
            'variants' => [[
                'name' => null, 'sku' => 'STAFF-1', 'barcode' => null,
                'selling_price' => '75.00', 'cost' => '40.00', 'low_stock_threshold' => 0,
            ]],
        ])->assertSessionHasNoErrors();

        $this->variantId = (string) Variant::query()->where('sku', 'STAFF-1')->value('id');
    }

    /**
     * One month of work across two branches, written straight into Postgres.
     *
     * Branch A: a day shift on the 10th (08:00–17:00) with a sale inside it, and
     * a sale at 23:59:59 on the 30th — the last instant this schema can represent
     * (`timestampTz` takes Laravel's default precision of 0), which is what proves
     * the upper bound is inclusive right up to it.
     * Branch B: a night shift starting 23:30 on the 30th and ending 03:30 the next
     * day, a sale inside it with a return against it, and a second sale at 00:30
     * on July 1 — the row that catches a bound sent without its offset.
     */
    private function seedTheBusinessMonth(): void
    {
        $owner = (string) $this->owner()->getKey();
        $a = (string) self::$documentA['branch']['id'];
        $b = (string) self::$documentB['branch']['id'];

        $this->seedSession($a, $owner, 'login', '2026-06-10 08:00:00');
        $this->seedSession($a, $owner, 'logout', '2026-06-10 17:00:00');
        $this->seedSession($b, $owner, 'login', '2026-06-30 23:30:00');
        $this->seedSession($b, $owner, 'logout', '2026-07-01 03:30:00');

        $this->seedSale($a, self::BRANCH_A.'-0000000001', '2026-06-10 09:00:00', '75.00', $owner, 1);
        $this->seedSale($a, self::BRANCH_A.'-0000000002', '2026-06-30 23:59:59', '75.00', $owner, 1);

        $sale = $this->seedSale($b, self::BRANCH_B.'-0000000001', '2026-06-30 23:45:00', '150.00', $owner, 2);
        $this->seedReturn($b, $sale, '2026-06-30 23:50:00', $owner);

        $this->seedSale($b, self::BRANCH_B.'-0000000002', '2026-07-01 00:30:00', '300.00', $owner, 4);
    }

    private function seedSession(string $branchId, string $userId, string $event, string $localTime): void
    {
        $at = $this->businessTime($localTime);

        $this->remoteExec(
            'insert into login_history (id, branch_id, user_id, event_type, occurred_at, created_at)
             values (?, ?, ?, ?, ?, ?)',
            [$this->uuid(), $branchId, $userId, $event, $at, $at],
        );
    }

    /**
     * @return array{0: string, 1: string} the transaction and its first line id
     */
    private function seedSale(string $branchId, string $number, string $localTime, string $total, string $userId, int $quantity): array
    {
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
            [$line, $transaction, $this->variantId, $quantity, '75.00', '40.00', $total, $at],
        );

        return [$transaction, $line];
    }

    /** @param array{0: string, 1: string} $sale */
    private function seedReturn(string $branchId, array $sale, string $localTime, string $userId): void
    {
        $return = $this->uuid();
        $at = $this->businessTime($localTime);

        $this->remoteExec(
            'insert into sale_returns (id, branch_id, return_number, transaction_id, type, total_refund, performed_by, created_at)
             values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$return, $branchId, 'RET-20260630-000001', $sale[0], 'return', '75.00', $userId, $at],
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
            '--branch-name=Second Staff Branch',
            '--branch-code='.self::BRANCH_B,
            '--branch-address=2 Test Street',
            '--out='.$out,
            '--actor=staff-test',
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

        // Truncate, not delete: the immutability triggers reject a DELETE on
        // login_history and the sales tables, which is the whole point of them.
        self::$admin->exec('truncate login_history cascade');
        self::$admin->exec('truncate sale_return_items, sale_returns, transaction_items, transactions cascade');
        self::$admin->exec('truncate inventory_update_queue, inventory_ledger, stock_snapshots cascade');
        self::$admin->exec('delete from variants');
        self::$admin->exec('delete from items');
        self::$admin->exec('delete from categories');

        self::$admin->exec("delete from users where username <> '".self::OWNER_USERNAME."'");
    }
}
