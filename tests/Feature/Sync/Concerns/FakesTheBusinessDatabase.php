<?php

namespace Tests\Feature\Sync\Concerns;

use App\Support\Sync\BranchCycle;
use App\Support\Sync\Puller;
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
