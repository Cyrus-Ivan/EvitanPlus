<?php

namespace App\Support\Sync;

use App\Models\Instance;
use Illuminate\Database\Connection;

/**
 * Step 4 of a cycle: what this branch tells the business about itself
 * (MULTIBRANCH-BRAINSTORM.md §14, docs/glossary.md §5).
 *
 * One row per branch, `branch_id` as the primary key — current state, never
 * history. A cycle that ran an hour ago is not worth keeping once a newer one
 * has run, and the console's monitor asks "what is true now".
 *
 * ## It is the only way a device's version reaches anyone
 *
 * `schema:migrate` can say which app builds a raised floor has just frozen; it
 * cannot say which TILLS are on them, because nothing else the branch writes
 * carries its app version. That gap is the reason this table exists, and it is
 * why the heartbeat writes the versions on every path rather than only on the
 * happy one — a frozen branch is exactly the branch someone is looking for.
 *
 * ## Two timestamps that are not the same fact
 *
 * `last_seen_at` is "this branch reached Postgres", written on every beat.
 * `last_sync_at` is "a cycle got all the way through", written only when one
 * did — a failure leaves the previous value standing, which is what makes
 * "online but stuck since Tuesday" readable at all. Fusing them would erase
 * precisely the branch worth calling about.
 *
 * ## What it cannot report
 *
 * `connection_state` carries the same four words as the local `sync_status`, but
 * only two of them can ever be written from here: `online` and
 * `version_blocked`. `offline` and `unauthorized` are diagnosed by the
 * connection FAILING TO OPEN, and a branch that cannot connect cannot file a
 * report saying so. So the monitor's primary signal is the AGE of
 * `last_seen_at`, and the state word is secondary. The four-value vocabulary
 * still belongs in the column: it keeps the two sides speaking one language, and
 * a value written here is never a lossy translation of the one on screen at the
 * till.
 */
class Heartbeat
{
    public function __construct(private readonly Outbox $outbox) {}

    /**
     * Upsert this branch's row, describing the cycle that just finished.
     *
     * Raw SQL with `CURRENT_TIMESTAMP` rather than the query builder and
     * `now()`: identical in Postgres, standard SQL, and the difference between
     * the fast tests exercising the statement that ships and a rewritten one
     * (the same reasoning as {@see RemoteStock}).
     *
     * @param  bool  $synced  Did this cycle complete? Only then is `last_sync_at`
     *                        touched — on every other path it is left out of the
     *                        SET list entirely, so the last real success stands.
     */
    public function upsert(
        Connection $remote,
        string $branchId,
        string $state,
        ?string $error,
        bool $synced,
    ): void {
        $instance = Instance::current();

        $bindings = [
            $branchId,
            (string) config('nativephp.version'),
            $instance?->schema_version,
            // Read here rather than passed in, and read AFTER the push, so it is
            // the backlog that remains rather than the one the cycle started
            // with. `count(*) where synced_at is null` is the one definition of
            // unsynced there is (§14) — there is no counter column to drift.
            $this->outbox->unsyncedCount(),
            $state,
            $error,
        ];

        $remote->statement(
            'insert into branch_status
                 (branch_id, app_version, schema_version, unsynced_count, connection_state,
                  last_error, last_seen_at, last_sync_at, created_at, updated_at)
             values (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP,
                     '.($synced ? 'CURRENT_TIMESTAMP' : 'null').', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             on conflict (branch_id) do update set
                 app_version = excluded.app_version,
                 schema_version = excluded.schema_version,
                 unsynced_count = excluded.unsynced_count,
                 connection_state = excluded.connection_state,
                 last_error = excluded.last_error,
                 last_seen_at = excluded.last_seen_at,
                 updated_at = excluded.updated_at'
             .($synced ? ', last_sync_at = excluded.last_sync_at' : ''),
            $bindings,
        );
    }
}
