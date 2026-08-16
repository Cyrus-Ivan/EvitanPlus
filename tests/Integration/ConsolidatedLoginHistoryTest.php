<?php

namespace Tests\Integration;

use App\Models\Instance;
use App\Models\User;
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
 * The M11.5 milestone against two real provisioned branches: **who signed in,
 * and where** — the thing `MULTIBRANCH-BRAINSTORM.md` §6 promised and nothing
 * read until now.
 *
 * ## Why this cannot be proved anywhere else
 *
 * tests/Feature/LoginHistory/LoginHistoryDisplayTest.php proves the role scoping
 * and the local branch column, but the local table has no `branch_id` at all, so
 * every row it can see is this branch's. The cross-branch answer exists only
 * upstream, and reaching it needs three things a stand-in has none of:
 *
 *  - **`branch_id` and the roster.** `login_history` is local-write and pushed
 *    insert-only — it goes up and never comes back down — so no device holds
 *    another device's sessions and no pull will ever change that.
 *  - **`timestamptz` against business-local window bounds.** Postgres reads a
 *    naive bound in the SESSION timezone, which is UTC. Bind one without its
 *    offset and the day filter moves eight hours, which sorts and renders
 *    perfectly and answers the wrong question. Two sessions here sit either side
 *    of local midnight for exactly that reason.
 *  - **The branch role's grants.** Reading another branch's `login_history` is
 *    possible only because `BranchGrants::SELECT` enumerates it — the only
 *    boundary of its kind (docs/glossary.md §9). This phase adds nothing to that
 *    matrix, and this test is what says so honestly.
 *
 * ## Why the rows are seeded through the admin connection
 *
 * The push path is tests/Integration/PushTest.php's subject. Here the question
 * is what the screen READS, so both branches' sessions are placed upstream
 * directly — including branch B's, which this device could never write.
 */
#[Group('supabase')]
class ConsolidatedLoginHistoryTest extends TestCase
{
    use ProvisionsABusiness, RefreshDatabase;

    /** Each test sets its device up for itself, through the real wizard. */
    protected bool $hydrated = false;

    /** The real probe, the real credential, the real grants. */
    protected ?bool $connected = null;

    private const OWNER_USERNAME = 'history-owner';

    private const BUSINESS_NAME = 'History Store';

    /** The branch the reading device IS. */
    private const BRANCH_A = 'HAA';

    /** The branch it can only see by asking the business database. */
    private const BRANCH_B = 'HBB';

    /** The reported day, in business-local time. */
    private const DAY = '2026-04-14';

    /** The month the seeded day sits in — the screen's window is a month. */
    private const MONTH = ['month' => 4, 'year' => 2026];

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

        $this->directory = storage_path('framework/testing/login-history');
        File::ensureDirectoryExists($this->directory);

        if (self::$documentA === null) {
            self::$admin = $this->adminConnection($env);
            self::$documentA = $this->provisionBusiness(self::BUSINESS_NAME, self::OWNER_USERNAME, self::BRANCH_A, 'history-test');
            self::$schema = self::$documentA['business']['schema'];
            self::$roleA = self::$documentA['role']['name'];

            self::$documentB = $this->provisionSecondBranch();
            self::$roleB = self::$documentB['role']['name'];
        }

        // The reading device is branch A throughout: the point of the phase is
        // that ONE till can see who signed in at every branch.
        $this->hydrateAs(self::$documentA);
        $this->seedTheBusinessDay();
    }

    protected function tearDown(): void
    {
        $this->resetSharedData();

        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_the_owner_sees_every_branchs_sessions_attributed_to_the_right_branch(): void
    {
        $this->actingAs($this->owner())
            ->get(route('login-history', ['scope' => 'all'] + self::MONTH))
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->component('login-history', false)
                    ->where('scope', 'all')
                    ->where('consolidatedOnline', true);

                $props = $page->toArray()['props'];

                // Five sessions across two branches, on a device that holds none
                // of them: its own SQLite login_history is empty (nothing has
                // logged in on it but the acting-as above, which writes no row).
                $this->assertSame(5, $props['total']);
                $this->assertCount(5, $this->entriesOf($props));

                $byBranch = collect($this->entriesOf($props))->countBy('branch');

                $this->assertSame(3, $byBranch[self::BRANCH_A]);
                $this->assertSame(2, $byBranch[self::BRANCH_B]);

                // The roster is offered so the screen can narrow to one branch —
                // minus THIS branch, which the picker already offers as `local`.
                $this->assertSame(
                    [self::BRANCH_B],
                    collect($props['branches'])->pluck('code')->sort()->values()->all(),
                );
            });
    }

    public function test_a_single_branch_scope_reports_only_that_branch(): void
    {
        $this->actingAs($this->owner())
            ->get(route('login-history', ['scope' => self::$documentB['branch']['id']] + self::MONTH))
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];

                $page->where('scope', self::$documentB['branch']['id'])->where('consolidatedOnline', true);

                $this->assertSame(2, $props['total']);
                $this->assertSame(
                    [self::BRANCH_B],
                    collect($this->entriesOf($props))->pluck('branch')->unique()->values()->all(),
                );
            });
    }

    public function test_a_row_is_bucketed_on_the_business_day_not_the_utc_one(): void
    {
        // Branch B's two sessions sit either side of local midnight: 23:30 on the
        // reported day and 00:30 on the next. In UTC both are the SAME day
        // (15:30 and 16:30), so a row localised late — or not at all — would put
        // the second in the earlier cell. Branch A's 23:59:59 is the other edge:
        // the last second the column can hold, which must stay on its own day.
        $this->actingAs($this->owner())
            ->get(route('login-history', ['scope' => 'all'] + self::MONTH))
            ->assertOk()
            ->assertInertia(function ($page) {
                $days = collect($page->toArray()['props']['days'])->keyBy('date');

                // Branch A's three — the last of them at 23:59:59, which stays on
                // this day only if the cell is chosen from the business-local
                // moment — plus branch B's 23:30, and NOT branch B's 00:30.
                $this->assertCount(4, $days[self::DAY]['entries']);

                // And it IS in the next day's cell, which is the other half of
                // the same fact.
                $next = $days['2026-04-15']['entries'];

                $this->assertCount(1, $next);
                $this->assertSame(self::BRANCH_B, $next[0]['branch']);
            });
    }

    public function test_a_day_reads_forward_and_every_day_of_the_month_is_a_cell(): void
    {
        $this->actingAs($this->owner())
            ->get(route('login-history', ['scope' => 'all'] + self::MONTH))
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];

                // April has thirty days and every one of them is a cell, seeded
                // or not — a day missing from the grid reads as "no data", an
                // empty one reads as "nobody signed in".
                $this->assertCount(30, $props['days']);

                $times = collect($props['days'])->firstWhere('date', self::DAY)['entries'];

                // Oldest first, including the 23:59:59 row last. The `id`
                // tiebreak is what keeps this stable: `timestamptz` is precision
                // 0 upstream, so two events can share a second.
                $this->assertSame(
                    ['08:00', '17:00', '23:30', '23:59'],
                    collect($times)
                        ->map(fn (array $row) => Carbon::parse((string) $row['occurred_at'])
                            ->setTimezone(config('app.timezone'))
                            ->format('H:i'))
                        ->all(),
                );
            });
    }

    /**
     * The window bounds are the other half of the same offset trap, and the
     * month's first instant is where it bites hardest: 00:30 on 1 April in
     * Manila is 16:30 on 31 MARCH in UTC, so a lower bound sent without its
     * offset drops the row out of the month entirely.
     */
    public function test_the_months_first_local_hour_is_inside_the_month(): void
    {
        $this->seedSession(
            (string) self::$documentB['branch']['id'],
            (string) $this->owner()->getKey(),
            'login',
            '2026-04-01 00:30:00',
        );

        $this->actingAs($this->owner())
            ->get(route('login-history', ['scope' => 'all'] + self::MONTH))
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];

                $this->assertSame(6, $props['total']);
                $this->assertCount(1, collect($props['days'])->firstWhere('date', '2026-04-01')['entries']);
            });

        // And it is NOT in March's, which is the half a missing offset would
        // otherwise satisfy on its own.
        $this->actingAs($this->owner())
            ->get(route('login-history', ['scope' => 'all', 'month' => 3, 'year' => 2026]))
            ->assertInertia(fn ($page) => $page->where('total', 0));
    }

    public function test_the_timestamps_arrive_in_business_local_time(): void
    {
        // The columns are `timestamptz` and the session is UTC, so an unconverted
        // read would show branch B's 23:30 session as 15:30 — a plausible time
        // that is simply the wrong one, on the wrong day.
        $this->actingAs($this->owner())
            ->get(route('login-history', ['scope' => self::$documentB['branch']['id']] + self::MONTH))
            ->assertInertia(function ($page) {
                $times = collect($this->entriesOf($page->toArray()['props']))
                    ->map(fn (array $row) => Carbon::parse((string) $row['occurred_at'])
                        ->setTimezone(config('app.timezone'))
                        ->format('Y-m-d H:i'))
                    ->sort()
                    ->values()
                    ->all();

                $this->assertSame([self::DAY.' 23:30', '2026-04-15 00:30'], $times);
            });
    }

    public function test_the_branch_local_history_still_sees_only_this_device(): void
    {
        // The other half of the milestone: the default scope is the local mirror
        // and works offline. Nothing was ever written to this device's SQLite, so
        // its own history is empty even though the business database is full.
        $this->actingAs($this->owner())
            ->get(route('login-history', self::MONTH))
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->where('scope', 'local')
                    ->where('consolidatedOnline', null)
                    ->where('total', 0);

                // ...and yet the picker is already filled. The roster is read on
                // every render for a viewer who may widen the scope, or naming
                // one branch would mean first asking for all of them. Branch B
                // only: this branch is the picker's `local` entry, and offering
                // its id as well would be the same branch twice.
                $this->assertSame(
                    [self::BRANCH_B],
                    collect($page->toArray()['props']['branches'])->pluck('code')->all(),
                );
            });
    }

    public function test_this_branchs_own_id_is_the_local_scope(): void
    {
        // Not a consolidated read of branch A — the local mirror, which is the
        // better answer to the same question and the only one that survives the
        // connection dropping.
        $this->actingAs($this->owner())
            ->get(route('login-history', ['scope' => self::$documentA['branch']['id']] + self::MONTH))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'local')
                ->where('consolidatedOnline', null)
                ->where('total', 0)
            );
    }

    // ---------------------------------------------------------------- helpers

    private function owner(): User
    {
        return User::query()->where('username', self::OWNER_USERNAME)->sole();
    }

    /**
     * Every event on the page, flattened out of its day cells.
     *
     * The screen is a calendar, so the rows arrive grouped and zero-filled. What
     * most of these assertions are about is the SET of rows a scope returned,
     * which the grouping is orthogonal to.
     *
     * @param  array<string, mixed>  $props
     * @return list<array<string, mixed>>
     */
    private function entriesOf(array $props): array
    {
        return collect($props['days'])->flatMap(fn (array $day) => $day['entries'])->values()->all();
    }

    /**
     * One business day of sessions across two branches, written straight into
     * Postgres.
     *
     * Branch A: a login at 08:00, a logout at 17:00, and one at 23:59:59 — the
     * last instant this schema can represent, which is what proves the upper
     * bound is inclusive right up to it. (`timestampTz` takes Laravel's default
     * precision of 0, so these columns are `timestamp(0) with time zone` and
     * hold whole seconds; a sub-second fixture would round into the next day
     * rather than test anything.)
     * Branch B: a login at 23:30, and one at 00:30 the NEXT local day — the row
     * that catches a bound sent without its offset.
     */
    private function seedTheBusinessDay(): void
    {
        $owner = (string) $this->owner()->getKey();
        $a = (string) self::$documentA['branch']['id'];
        $b = (string) self::$documentB['branch']['id'];

        $this->seedSession($a, $owner, 'login', self::DAY.' 08:00:00');
        $this->seedSession($a, $owner, 'logout', self::DAY.' 17:00:00');
        $this->seedSession($a, $owner, 'logout', self::DAY.' 23:59:59');
        $this->seedSession($b, $owner, 'login', self::DAY.' 23:30:00');
        $this->seedSession($b, $owner, 'login', '2026-04-15 00:30:00');
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
            '--branch-name=Second History Branch',
            '--branch-code='.self::BRANCH_B,
            '--branch-address=2 Test Street',
            '--out='.$out,
            '--actor=history-test',
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

        // Truncate, not delete: the immutability trigger rejects a DELETE on
        // login_history, which is the whole point of it.
        self::$admin->exec('truncate login_history cascade');

        self::$admin->exec("delete from users where username <> '".self::OWNER_USERNAME."'");
    }
}
