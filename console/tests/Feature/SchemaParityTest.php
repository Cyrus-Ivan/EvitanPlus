<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
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
 * two allowlists below.
 */
class SchemaParityTest extends TestCase
{
    /**
     * Tables the till mirrors, and which must therefore agree.
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
        'inventory_update_queue' => 'coordination between HQ and a branch, not branch data',
        'branch_status' => 'per-branch heartbeat consumed by the console monitor',
        'migrations' => "Laravel's own ledger",
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

    /**
     * Column name => type, per table, for the APP's SQLite schema.
     *
     * Built by running the app's own migrations into a throwaway file, so the
     * comparison is against what the app actually creates rather than a
     * hand-maintained description of it that could itself drift.
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

            foreach (self::MIRRORED as $table) {
                $columns[$table] = [];

                foreach (DB::connection('sqlite_parity')->select("pragma table_info(\"{$table}\")") as $column) {
                    $columns[$table][$column->name] = strtolower($column->type);
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

        foreach ($sqlite as $column => $sqliteType) {
            $this->assertArrayHasKey(
                $column,
                $postgres,
                "[{$table}.{$column}] exists in the app but not in Postgres — a sync would drop it."
            );

            $allowed = self::TYPE_MAP[$sqliteType] ?? null;

            $this->assertNotNull($allowed, "No type mapping for SQLite type [{$sqliteType}] ({$table}.{$column}).");

            $this->assertContains(
                $postgres[$column],
                $allowed,
                "[{$table}.{$column}] is {$sqliteType} in SQLite but {$postgres[$column]} in Postgres."
            );
        }

        // ...and nothing extra on the Postgres side that the app does not know
        // how to fill, beyond the documented additions.
        $extra = array_diff(array_keys($postgres), array_keys($sqlite), self::POSTGRES_ONLY_COLUMNS);

        $this->assertSame(
            [],
            array_values($extra),
            "[{$table}] has Postgres-only columns the app cannot populate: ".implode(', ', $extra)
        );
    }

    public function test_the_postgres_only_tables_are_the_documented_ones(): void
    {
        $postgres = array_keys($this->postgresColumns());

        $unexpected = array_diff($postgres, self::MIRRORED, array_keys(self::POSTGRES_ONLY));

        $this->assertSame(
            [],
            array_values($unexpected),
            'Undocumented Postgres-only tables: '.implode(', ', $unexpected)
            .' — add them to POSTGRES_ONLY with a reason, or mirror them.'
        );

        // The reverse: a table that was supposed to be Postgres-only but is
        // missing entirely means a migration was dropped.
        foreach (array_keys(self::POSTGRES_ONLY) as $table) {
            $this->assertContains($table, $postgres, "Postgres-only table [{$table}] is missing.");
        }
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
