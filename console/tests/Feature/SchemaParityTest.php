<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SchemaDiff;
use Tests\TestCase;

/**
 * The anti-drift guard for the two hand-written schemas
 * (MULTIBRANCH-BRAINSTORM.md §13).
 *
 * The app's SQLite migrations and the console's Postgres migrations are
 * deliberately separate — each dialect expresses its own triggers and
 * constraints naturally, and Postgres carries tables the till never mirrors.
 * The cost of that choice is that a column added to one side can silently go
 * missing on the other, and nothing would notice until a sync tried to move a
 * field that does not exist at the far end.
 *
 * So: build the app's SQLite schema from its real migrations, build the
 * Postgres schema from ours, and require every mirrored column to exist on
 * both sides with a compatible type. Forget either side and this fails.
 *
 * The databases are NOT required to be identical — that is the point of the
 * three allowlists below.
 *
 * ## Why this had never run, and what M4 changed
 *
 * It was written in M1 and, until M4, had never run once. Not because it was
 * hard to run — everything here is stock Postgres, and the console's 19
 * migrations use no extension, no `auth.` schema and no Supabase-only
 * function — but because no command invoked it. `composer verify:console` was
 * `--testsuite=Unit`, and `composer verify` does not touch the console at all.
 * A guard nothing calls is indistinguishable from one that was never written.
 *
 * M4's fix was to point `verify:console` at the whole suite. That is the entire
 * mechanism; there is no CI, and adding one would not have helped, because the
 * gap was never about which machine ran the tests.
 *
 * The comparison itself lives in Tests\Support\SchemaDiff and is tested
 * separately against invented, disagreeing schemas. A green run here proves the
 * schemas match; it cannot prove the comparison would have noticed if they did
 * not.
 */
class SchemaParityTest extends TestCase
{
    /**
     * Tables the till mirrors, and which must therefore agree.
     *
     * "Mirrored" is one of FOUR memberships a table can hold and is the
     * narrowest of them (`docs/glossary.md` §3). It is not *readable*
     * (`BranchGrants::SELECT`, 18 tables), not *pulled* (online-authoritative),
     * and not *local-only* (SQLITE_LOCAL_ONLY, below). The sets differ in size
     * and in membership; `branch_status` and `inventory_update_queue` are
     * readable and deliberately not mirrored.
     *
     * @var list<string>
     */
    private const MIRRORED = [
        'users', 'categories', 'items', 'variants', 'settings',
        'transactions', 'transaction_items', 'inventory_ledger', 'stock_snapshots',
        'sale_returns', 'sale_return_items', 'login_history', 'audit_logs', 'import_history',
    ];

    /**
     * Postgres-only tables, with the reason each has no SQLite counterpart.
     *
     * @var array<string, string>
     */
    private const POSTGRES_ONLY = [
        'branches' => 'the till knows only which branch it is, never the roster',
        'schema_meta' => 'the version gate lives server-side',
        'inventory_update_queue' => 'coordination between the issuing user and a branch, not branch data',
        'branch_status' => 'per-branch heartbeat consumed by the console monitor',
        'migrations' => "Laravel's own ledger",
    ];

    /**
     * SQLite-only tables, with the reason each has no Postgres counterpart.
     *
     * The fourth membership, and the one that did not exist before M4. Until
     * then this test read only the MIRRORED tables out of SQLite, so the local
     * side had no equivalent of the Postgres check below: a new local table was
     * invisible to the guard entirely. That gap was about to matter — M6 adds
     * `sync_outbox` and `sync_status`, M7 adds `sync_cursor`, and M8 wants a
     * local `inventory_update_queue` (§11.6). Each of those belongs HERE rather
     * than in MIRRORED, because a local-only table is intentionally a different,
     * narrower table and not a mirror; forcing one into MIRRORED would make this
     * test demand a similarity nobody wants. That settles §11.6's open item.
     *
     * M5 put the first non-Laravel entries on it: `instance` and
     * `instance_secret`. Both are local-only in the strict sense — Postgres has
     * no counterpart to add, because the roster of branches (`branches`) and the
     * record of which credential is live (`credential_issuance`, in the console
     * schema) answer those questions from the other side.
     *
     * M6 added the next two, and this check caught both on the first run — the
     * gap the paragraph above describes, closing on the exact tables it named.
     *
     * @var array<string, string>
     */
    private const SQLITE_LOCAL_ONLY = [
        'instance' => 'branch identity, from the credentials JSON — Postgres has a branches roster instead (M5)',
        'instance_secret' => 'the branch credential sealed with the per-install APP_KEY; it exists nowhere else (M5)',
        'sync_outbox' => "this device's backlog of rows waiting to push; once pushed there is nothing left to record (M6)",
        'sync_status' => 'why this device is or is not syncing; its counterpart is branch_status, a different, wider table (M6)',
        'cache' => "Laravel's own, rebuilt by a re-install",
        'cache_locks' => "Laravel's own, rebuilt by a re-install",
        'jobs' => "Laravel's own, rebuilt by a re-install",
        'job_batches' => "Laravel's own, rebuilt by a re-install",
        'failed_jobs' => "Laravel's own, rebuilt by a re-install",
        'sessions' => "Laravel's own, rebuilt by a re-install",
        'migrations' => "Laravel's own ledger — and the app's migration set is not the console's",
        'sqlite_sequence' => 'created by SQLite itself, because the migrations table is AUTOINCREMENT',
    ];

    /**
     * Columns Postgres adds to a mirrored table.
     *
     * branch_id is the whole reason multi-branch works: one Postgres schema
     * holds every branch's rows, while a till's SQLite holds only its own and
     * therefore has nothing to scope by.
     *
     * @var list<string>
     */
    private const POSTGRES_ONLY_COLUMNS = ['branch_id'];

    /**
     * SQLite declared type => Postgres types that faithfully carry it.
     *
     * @var array<string, list<string>>
     */
    private const TYPE_MAP = [
        'varchar' => ['character varying', 'uuid'],
        'text' => ['text'],
        'integer' => ['integer', 'bigint'],
        'numeric' => ['numeric'],
        'datetime' => ['timestamp with time zone'],
        'tinyint(1)' => ['boolean'],
    ];

    /** @var array<string, array<string, string>>|null */
    private static ?array $sqliteColumns = null;

    /** @var array<string, array<string, string>>|null */
    private static ?array $postgresColumns = null;

    private function diff(): SchemaDiff
    {
        return new SchemaDiff(self::TYPE_MAP, self::POSTGRES_ONLY_COLUMNS);
    }

    /**
     * Column name => type, per table, for the APP's SQLite schema.
     *
     * Built by running the app's own migrations into a throwaway file, so the
     * comparison is against what the app actually creates rather than a
     * hand-maintained description of it that could itself drift.
     *
     * EVERY table is read, not just the mirrored ones — an unclassified table
     * is the thing the SQLite-side check below exists to catch.
     *
     * @return array<string, array<string, string>>
     */
    private function sqliteColumns(): array
    {
        if (self::$sqliteColumns !== null) {
            return self::$sqliteColumns;
        }

        $file = tempnam(sys_get_temp_dir(), 'parity_').'.sqlite';
        touch($file);

        config(['database.connections.sqlite_parity' => [
            'driver' => 'sqlite',
            'database' => $file,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        DB::purge('sqlite_parity');

        try {
            Artisan::call('migrate', [
                '--database' => 'sqlite_parity',
                // The POS app lives one level up. This is a dev-time read of
                // migration FILES only — no console code ever references the
                // app at runtime, which is what the build boundary requires.
                '--path' => dirname(base_path()).'/database/migrations',
                '--realpath' => true,
                '--force' => true,
            ]);

            $columns = [];

            $tables = DB::connection('sqlite_parity')
                ->select("select name from sqlite_master where type = 'table'");

            foreach ($tables as $table) {
                $columns[$table->name] = [];

                foreach (DB::connection('sqlite_parity')->select("pragma table_info(\"{$table->name}\")") as $column) {
                    $columns[$table->name][$column->name] = strtolower($column->type);
                }
            }

            return self::$sqliteColumns = $columns;
        } finally {
            DB::purge('sqlite_parity');
            @unlink($file);
        }
    }

    /**
     * Column name => type, per table, for the console's Postgres schema.
     *
     * @return array<string, array<string, string>>
     */
    private function postgresColumns(): array
    {
        if (self::$postgresColumns !== null) {
            return self::$postgresColumns;
        }

        $connection = $this->migratedSchema();
        $schema = $this->schemaName();

        $rows = $connection->select(
            'select table_name, column_name, data_type
             from information_schema.columns
             where table_schema = ?',
            [$schema],
        );

        $columns = [];

        foreach ($rows as $row) {
            $columns[$row->table_name][$row->column_name] = strtolower($row->data_type);
        }

        return self::$postgresColumns = $columns;
    }

    /**
     * @return list<array{0: string}>
     */
    public static function mirroredTables(): array
    {
        return array_map(static fn (string $t): array => [$t], self::MIRRORED);
    }

    #[DataProvider('mirroredTables')]
    public function test_every_mirrored_column_exists_on_both_sides(string $table): void
    {
        $sqlite = $this->sqliteColumns()[$table] ?? [];
        $postgres = $this->postgresColumns()[$table] ?? [];

        $this->assertNotEmpty($sqlite, "[{$table}] is missing from the app's SQLite schema.");
        $this->assertNotEmpty($postgres, "[{$table}] is missing from the Postgres schema.");

        $this->assertSame(
            [],
            $this->diff()->compareTable($table, $sqlite, $postgres),
            "[{$table}] has drifted between the app's SQLite schema and Postgres.",
        );
    }

    public function test_the_postgres_only_tables_are_the_documented_ones(): void
    {
        $postgres = array_keys($this->postgresColumns());

        $this->assertSame(
            [],
            $this->diff()->undocumentedTables($postgres, self::MIRRORED, array_keys(self::POSTGRES_ONLY)),
            'Undocumented Postgres-only tables — add them to POSTGRES_ONLY with a reason, or mirror them.'
        );

        // The reverse: a table that was supposed to be Postgres-only but is
        // missing entirely means a migration was dropped.
        $this->assertSame(
            [],
            $this->diff()->missingTables($postgres, array_keys(self::POSTGRES_ONLY)),
            'Postgres-only tables that have gone missing.'
        );
    }

    /**
     * The same check in the other direction, added in M4.
     *
     * Without it a table could be added to the app and reach every till while
     * belonging to no category at all — neither mirrored nor consciously local.
     * The decision is what is being forced here: mirror it, or write down why
     * it stays local.
     */
    public function test_the_sqlite_only_tables_are_the_documented_ones(): void
    {
        $sqlite = array_keys($this->sqliteColumns());

        $this->assertSame(
            [],
            $this->diff()->undocumentedTables($sqlite, self::MIRRORED, array_keys(self::SQLITE_LOCAL_ONLY)),
            'Undocumented SQLite-only tables — add them to SQLITE_LOCAL_ONLY with a reason, or mirror them.'
        );

        $this->assertSame(
            [],
            $this->diff()->missingTables($sqlite, array_keys(self::SQLITE_LOCAL_ONLY)),
            'SQLite-only tables that have gone missing.'
        );
    }

    public function test_branch_scoped_tables_carry_branch_id(): void
    {
        $postgres = $this->postgresColumns();

        // The till's own rows are branch-scoped; catalog, users and settings
        // are business-wide and must NOT be scoped, or a branch would only see
        // the products someone happened to file under it.
        foreach ([
            'transactions', 'inventory_ledger', 'stock_snapshots',
            'sale_returns', 'login_history', 'audit_logs', 'import_history',
        ] as $table) {
            $this->assertArrayHasKey('branch_id', $postgres[$table], "[{$table}] must be branch-scoped.");
        }

        foreach (['users', 'categories', 'items', 'variants', 'settings'] as $table) {
            $this->assertArrayNotHasKey(
                'branch_id',
                $postgres[$table],
                "[{$table}] is business-wide and must not be branch-scoped."
            );
        }
    }
}
