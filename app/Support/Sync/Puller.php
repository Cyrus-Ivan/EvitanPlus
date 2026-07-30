<?php

namespace App\Support\Sync;

use App\Models\SyncCursor;
use App\Support\Settings\BusinessSettings;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Step 3 of a sync cycle: what the business shares, coming down
 * (MULTIBRANCH-BRAINSTORM.md §11.2, §5.1; docs/glossary.md §5 "pull").
 *
 * A PULL is not a HYDRATE. A hydrate is the one-time first fill of a new device
 * and copies everything; this is the recurring delta, and it reads only what has
 * changed since the last cycle. {@see Hydrator} runs once per device; this runs
 * every few minutes for the life of the install.
 *
 * ## The cursor, and why it is a bookmark rather than a clock
 *
 * Without one, every cycle would re-read the whole catalog, every user and every
 * setting — 288 times a day per till, over a shop's internet, to discover
 * nothing changed. `sync_cursor` holds one watermark per table so the question
 * becomes "what changed since?", which normally answers with no rows at all.
 *
 * Two properties of that bookmark are load-bearing and neither is obvious:
 *
 *  - **The comparison is `>=`, not `>`.** Two rows edited in the same instant
 *    carry the same timestamp. With `>` the second one lands *behind* the
 *    bookmark the first one set and is never read again — no error, no gap
 *    anything could notice. `>=` re-reads the boundary row each cycle, which the
 *    upserts below absorb for free.
 *  - **The bookmark is the newest row actually READ, never `now()`.** In
 *    Postgres a row's `updated_at` is stamped when its transaction begins but
 *    becomes visible when it commits. An edit starting at :00 and committing at
 *    :09 carries :00. A cycle running at :05 does not see it — and if it
 *    bookmarked :05, that row would sit permanently behind the mark, leaving the
 *    branch on a stale price with a green sync indicator forever. Bookmarking
 *    the last line actually read means the next cycle sweeps that window again.
 *
 * Per table, not one global watermark: a failure on `variants` must not discard
 * the progress just made on `users`, and each table's rows and its cursor move
 * in the same local transaction so they cannot disagree.
 *
 * ## Why deletions need no handling
 *
 * They do not happen. Categories, items and variants archive (`archived_at`),
 * users soft-delete (`deleted_at`) — both are UPDATEs, so both arrive as
 * ordinary deltas. The never-delete invariant is precisely what makes a
 * watermark pull sufficient; a schema that really deleted rows would need a
 * tombstone table and this class would not work.
 *
 * ## Why the query builder and never a model
 *
 * Five reasons, four of them shared with {@see Hydrator}: models would fire the
 * single-Owner guard on users arriving from the authority, re-hash
 * already-hashed passwords through the `hashed` cast, regenerate UUIDs the
 * business owns, and hide behind `SoftDeletes` exactly the `deleted_at` updates
 * being pulled. The fifth belongs to this class alone — an Eloquent write fires
 * `PushedUpward::created`, which would enqueue every pulled row into the outbox
 * and push the business's own data straight back at it.
 */
class Puller
{
    /**
     * The pulled tables, in FK order, with the column each is watermarked on.
     *
     * `inventory_ledger` is watermarked on `created_at` because it has no
     * `updated_at` — it is append-only on both sides (see its migration). Only
     * its `type = 'stock_adjustment'` rows come down: `type = 'sale'` rows are
     * this branch's own work and travel the other way (glossary §4).
     *
     * @var array<string, string>
     */
    private const TABLES = [
        'settings' => 'updated_at',
        'users' => 'updated_at',
        'categories' => 'updated_at',
        'items' => 'updated_at',
        'variants' => 'updated_at',
        'inventory_ledger' => 'created_at',
    ];

    /** Rows per round trip. */
    private const CHUNK = 500;

    public function __construct(private readonly RowMapper $rows) {}

    /**
     * Apply every pulled table's delta.
     *
     * @return array<string, int> table => rows applied
     */
    public function pull(Connection $remote, string $branchId): array
    {
        $applied = [];

        foreach (self::TABLES as $table => $watermark) {
            $applied[$table] = $this->pullTable($remote, $table, $watermark, $branchId);
        }

        return $applied;
    }

    /**
     * @return int rows applied
     */
    private function pullTable(Connection $remote, string $table, string $watermark, string $branchId): int
    {
        $types = $this->rows->localTypes($table);
        $position = SyncCursor::positionOf($table);

        $query = $remote->table($table)
            ->select(array_keys($types))
            ->orderBy($watermark)
            ->orderBy('id');

        if ($position !== null) {
            // The bookmark. `>=`, not `>` — see the class docblock.
            $query->where($watermark, '>=', $position);
        }

        $this->scope($query, $table, $branchId);

        $applied = 0;
        $highest = null;

        // One local transaction for the whole table, so the rows and the cursor
        // advance together. A failure part-way leaves the cursor where it was
        // and the next cycle re-reads the table from there — harmlessly, because
        // every write below is idempotent.
        DB::transaction(function () use ($query, $table, $types, $watermark, &$applied, &$highest): void {
            foreach ($query->cursor()->chunk(self::CHUNK) as $chunk) {
                $rows = [];

                foreach ($chunk as $row) {
                    $raw = (array) $row;

                    // The RAW Postgres value, before conversion. The cursor is
                    // Postgres's own rendering kept opaque, so the round trip is
                    // exact to the microsecond — and because the query is
                    // ordered by this column, the last row seen carries the
                    // highest value. Taken by position rather than by comparison
                    // on purpose: comparing `timestamptz` renderings as strings
                    // happens to work for Postgres's current format and would
                    // break silently if that ever changed.
                    $highest = $raw[$watermark] === null ? $highest : (string) $raw[$watermark];

                    $rows[] = $this->rows->inbound($raw, $types, (string) config('app.timezone'));
                }

                $applied += $this->apply($table, $rows);
            }

            SyncCursor::advance($table, $highest);
        });

        return $applied;
    }

    /**
     * Narrow $table to what this branch may hold locally.
     */
    private function scope(Builder $query, string $table, string $branchId): void
    {
        if ($table === 'settings') {
            // The local settings table is deliberately WIDER than Postgres — it
            // also holds this device's printer configuration, which is hardware
            // and has no business-wide meaning. Restricting the read is half of
            // what stops a pull erasing it; upserting by `key` below is the
            // other half.
            $query->whereIn('key', BusinessSettings::KEYS);
        }

        if ($table === 'inventory_ledger') {
            // Stock adjustments issued against THIS branch. Sale rows are the
            // branch's own work and travel the other way.
            $query->where('branch_id', $branchId)->where('type', 'stock_adjustment');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return int rows applied
     */
    private function apply(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        match ($table) {
            // Matched on `key`, not `id`: a device already carrying a row for
            // this key — the defaults a fresh install writes — must be updated
            // rather than collided with.
            'settings' => $this->applySettings($rows),
            // Append-only, and the local table carries a BEFORE UPDATE
            // immutability trigger, so an upsert on the re-read boundary row
            // would RAISE rather than no-op. There is nothing to update anyway:
            // a ledger entry never changes.
            'inventory_ledger' => DB::table($table)->insertOrIgnore($rows),
            default => DB::table($table)->upsert($rows, ['id']),
        };

        return count($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function applySettings(array $rows): void
    {
        foreach ($rows as $row) {
            DB::table('settings')->updateOrInsert(
                ['key' => $row['key']],
                array_diff_key($row, ['key' => null]),
            );
        }
    }
}
