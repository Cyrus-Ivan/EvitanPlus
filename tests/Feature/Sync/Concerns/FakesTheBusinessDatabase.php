<?php

namespace Tests\Feature\Sync\Concerns;

use App\Support\Inventory\AdjustmentResolver;
use App\Support\Sync\BranchCycle;
use App\Support\Sync\Puller;
use App\Support\Sync\RemoteStock;
use App\Support\Sync\RowMapper;
use App\Support\Sync\StockReconciler;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * A stand-in business database for the tests that are about the PULL rather than
 * about Postgres.
 *
 * {@see Puller} and {@see StockReconciler}
 * take a `Connection` and never name a driver — deliberately, because a cycle's
 * connection is opened by {@see BranchCycle} and handed in. So
 * the cursor arithmetic, the scoping and the local write semantics can all be
 * exercised against a second SQLite connection, credential-free, in
 * milliseconds.
 *
 * ## What this cannot prove, and where that is proved instead
 *
 * Everything about Postgres being Postgres: `timestamptz` rendering and its
 * microseconds, `pdo_pgsql` handing booleans back as 't'/'f', the real grants.
 * Those are exactly the things that fail silently, so they are covered for real
 * in tests/Integration/PullTest.php against a provisioned business. Treat this
 * trait as testing the algorithm and that file as testing the round trip; a
 * finding that belongs to the round trip must not be asserted here, or it will
 * pass while the shipped path is broken.
 *
 * ## The schema is copied from the local one, not written out
 *
 * Columns come from the running app's own `pragma table_info`, plus `branch_id`
 * where Postgres scopes by branch. A mirrored column added in a later migration
 * therefore appears here with no edit — the same column-driven reasoning
 * {@see RowMapper} uses, and for the same reason: a hand-kept
 * list is a place to forget.
 */
trait FakesTheBusinessDatabase
{
    /** The connection name the stand-in is registered under. */
    private const REMOTE = 'business_stand_in';

    /**
     * Tables Postgres scopes by branch, which the local mirror has no column for.
     *
     * @var list<string>
     */
    private const BRANCH_SCOPED = ['inventory_ledger', 'stock_snapshots'];

    /**
     * The two tables that have NO local counterpart to copy, and so must be
     * spelled out here.
     *
     * They are `SchemaParityTest::POSTGRES_ONLY` names for the app: `branches`
     * because "the till knows only which branch it is, never the roster",
     * `inventory_update_queue` because it is coordination between the issuing
     * user and a branch rather than branch data, and `branch_status` because a
     * branch reports upward and never reads its own report back. A hand-kept
     * list is a place to forget, which is why everything else is derived — but a
     * table the app has never had cannot be derived from a schema that has never
     * described it.
     *
     * @var array<string, string>
     */
    private const POSTGRES_ONLY = [
        'branches' => '"id" varchar, "code" varchar, "name" varchar, "address" varchar,
                       "disabled_at" datetime, "created_at" datetime, "updated_at" datetime',
        'branch_status' => '"branch_id" varchar, "app_version" varchar, "schema_version" integer,
                            "last_sync_at" datetime, "unsynced_count" integer, "last_seen_at" datetime,
                            "connection_state" varchar, "last_error" text,
                            "created_at" datetime, "updated_at" datetime',
        'inventory_update_queue' => '"id" varchar, "parent_id" varchar, "branch_id" varchar,
                                     "counterparty_branch_id" varchar, "variant_id" varchar,
                                     "quantity" integer, "approved_quantity" integer,
                                     "reference_type" varchar, "reference_id" varchar,
                                     "notes" varchar, "status" varchar,
                                     "resolution_reason" varchar, "ledger_entry_id" varchar,
                                     "issued_by" varchar, "issued_by_branch_id" varchar,
                                     "resolved_by" varchar, "resolved_at" datetime,
                                     "created_at" datetime, "updated_at" datetime',
    ];

    /**
     * The ONLY constraints carried, and only because SQL requires them.
     *
     * `ON CONFLICT (cols)` needs a matching unique index to target, on both
     * engines — so a stand-in without these would refuse the very statements
     * {@see RemoteStock} and the mirroring in
     * {@see AdjustmentResolver} really run, and the
     * alternative would be testing a rewritten query instead of the shipped one.
     * Everything else stays constraint-free for the reason given on
     * {@see createTableSql()}.
     *
     * @var array<string, list<string>>
     */
    private const UPSERT_KEYS = [
        'stock_snapshots' => ['"branch_id", "variant_id"'],
        'inventory_ledger' => ['"id"'],
        'branch_status' => ['"branch_id"'],
    ];

    /**
     * Build the stand-in and return its connection.
     *
     * @param  list<string>  $tables
     */
    private function businessDatabase(array $tables = [
        'settings', 'users', 'categories', 'items', 'variants', 'inventory_ledger', 'stock_snapshots',
    ]): Connection
    {
        config(['database.connections.'.self::REMOTE => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        DB::purge(self::REMOTE);

        $remote = DB::connection(self::REMOTE);

        foreach ($tables as $table) {
            $remote->statement($this->createTableSql($table));

            foreach (self::UPSERT_KEYS[$table] ?? [] as $index) {
                $remote->statement("create unique index \"{$table}_upsert\" on \"{$table}\" ({$index})");
            }
        }

        return $remote;
    }

    /**
     * Mirror $table's local definition, plus `branch_id` where Postgres has one.
     *
     * No constraints are carried: no primary keys, no foreign keys, no NOT NULL.
     * The stand-in is a source to read FROM, and reproducing the local table's
     * guards on it would only make fixtures harder to write without testing
     * anything — the constraints that matter to a pull are the LOCAL ones, and
     * those are the real thing here.
     */
    private function createTableSql(string $table): string
    {
        if (isset(self::POSTGRES_ONLY[$table])) {
            return "create table \"{$table}\" (".self::POSTGRES_ONLY[$table].')';
        }

        $columns = [];

        foreach (DB::select("pragma table_info(\"{$table}\")") as $column) {
            $columns[] = "\"{$column->name}\" {$column->type}";
        }

        if (in_array($table, self::BRANCH_SCOPED, true)) {
            $columns[] = '"branch_id" varchar';
        }

        return "create table \"{$table}\" (".implode(', ', $columns).')';
    }
}
