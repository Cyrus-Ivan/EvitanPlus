<?php

namespace App\Support\Sync;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The one place that knows how a local SQLite row becomes a Postgres row.
 *
 * Extracted in M6.5 because a second caller appeared. {@see Pusher} sends
 * history up insert-only; {@see OnlineWrite} sends shared data up with update
 * semantics. They disagree about the conflict clause and agree about everything
 * else, and "everything else" is where the two silent bugs live — so it lives
 * once.
 *
 * ## Column-driven, not hand-listed
 *
 * The local `pragma table_info` names the columns and exactly those are sent.
 * That is safe only because M4's parity guard proves both schemas carry the same
 * columns for a mirrored table — the guard and this are the same decision seen
 * from two sides. A hand-written column list would be a fourth place to
 * remember, and the one nothing checks.
 *
 * ## Two conversions that fail quietly if skipped
 *
 *  - TIMESTAMPS. SQLite datetime columns hold naive BUSINESS-LOCAL strings, not
 *    UTC: `ApplyBusinessTimezone` sets the default timezone at boot and the app
 *    has stored local time since v1.0.0. Stamping `+00` on them would move every
 *    row by the business's offset — a shift that sorts correctly, imports
 *    cleanly, and reports wrong.
 *  - BOOLEANS. SQLite stores 0/1 in a `tinyint(1)`; Postgres wants a real
 *    boolean. `users.must_change_password` is one, which is why this is not
 *    hypothetical: the mirror-image bug on the way DOWN would force a password
 *    change on every user of a new device.
 */
class RowMapper
{
    /**
     * Guards identifiers interpolated into SQL. They come from
     * `pragma table_info` and from constants, never from a request — but a table
     * name reaching a query builder as a string deserves a hard stop rather than
     * a comment saying it is fine.
     */
    private const IDENTIFIER = '/^[a-z_][a-z0-9_]*$/';

    /**
     * Which remote tables carry `branch_id`, asked once per instance.
     *
     * @var array<string, bool>
     */
    private array $branchScoped = [];

    /**
     * Read local rows by id and convert them to what Postgres should store.
     *
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    public function outboundRows(Connection $remote, string $table, array $ids, string $branchId): array
    {
        $types = $this->localTypes($table);

        $stamp = $this->isBranchScoped($remote, $table) ? $branchId : null;

        return DB::table($table)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get(array_keys($types))
            ->map(fn (object $row): array => $this->outbound((array) $row, $types, $stamp))
            ->all();
    }

    /**
     * Does Postgres scope $table by branch?
     *
     * Asked of the far end rather than declared here. Five of the seven pushed
     * tables carry `branch_id`; the two line-item tables do not, because a line
     * belongs to whatever branch its parent does and repeating the fact on the
     * child would be a second place for it to be wrong. Nor do the shared tables
     * M6.5 writes — a product belongs to the business, not to whoever typed it.
     * Introspecting means a future table decides this for itself.
     */
    public function isBranchScoped(Connection $remote, string $table): bool
    {
        $this->assertIdentifier($table);

        return $this->branchScoped[$table] ??= $remote->selectOne(
            'select 1 as present from information_schema.columns
              where table_schema = current_schema() and table_name = ? and column_name = ?',
            [$table, 'branch_id'],
        ) !== null;
    }

    /**
     * Column => declared SQLite type, from the same source the parity guard
     * reads, so the type vocabulary here is the vocabulary that guard speaks.
     *
     * @return array<string, string>
     */
    public function localTypes(string $table): array
    {
        $this->assertIdentifier($table);

        $types = [];

        foreach (DB::select("pragma table_info(\"{$table}\")") as $column) {
            $this->assertIdentifier((string) $column->name);

            $types[$column->name] = strtolower((string) $column->type);
        }

        if ($types === []) {
            throw new RuntimeException("The local table [{$table}] does not exist.");
        }

        return $types;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $types
     * @param  string|null  $branchId  Null for a table Postgres does not scope by branch.
     * @return array<string, mixed>
     */
    public function outbound(array $row, array $types, ?string $branchId): array
    {
        foreach ($row as $column => $value) {
            if ($value === null) {
                continue;
            }

            $row[$column] = match ($types[$column] ?? '') {
                'datetime' => Carbon::parse((string) $value, config('app.timezone'))->format('Y-m-d H:i:sP'),
                'tinyint(1)' => (bool) $value,
                'integer' => (int) $value,
                default => $value,
            };
        }

        return $branchId === null ? $row : ['branch_id' => $branchId] + $row;
    }

    public function assertIdentifier(string $name): void
    {
        if (preg_match(self::IDENTIFIER, $name) !== 1) {
            throw new RuntimeException("Refusing to build SQL around the identifier [{$name}].");
        }
    }
}
