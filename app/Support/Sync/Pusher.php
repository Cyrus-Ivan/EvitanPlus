<?php

namespace App\Support\Sync;

use App\Models\Instance;
use App\Models\SyncOutboxEntry;
use Illuminate\Database\Connection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Step 1 of a sync cycle: this branch's work, going up
 * (MULTIBRANCH-BRAINSTORM.md §11.2, §11.4).
 *
 * Insert-only, UUID-keyed, and therefore idempotent — re-running a push is a
 * no-op, which is what lets a cycle fail anywhere without needing to know how
 * far it got.
 *
 * ## Why the INSERT is hand-written
 *
 * Both of Laravel's obvious helpers are wrong here, in opposite directions:
 *
 *  - `upsert()` compiles to `on conflict (...) do update set ...` on Postgres
 *    with no way to ask for anything else. Every table here carries a BEFORE
 *    UPDATE immutability trigger, so a re-push would not merely be wasteful —
 *    it would raise.
 *  - `insertOrIgnore()` compiles to a bare `on conflict do nothing`, with NO
 *    conflict target. That swallows a conflict on ANY unique constraint, and one
 *    of those is `(branch_id, transaction_number)`. A device that restarted its
 *    serial — the replacement-hardware case the serial floor exists for — would
 *    have its sales silently discarded, one per collision, with a green sync and
 *    a zero unsynced count to say everything was fine.
 *
 * So: `on conflict ("id") do nothing`, naming the id and only the id. A repeat
 * of the same row is absorbed; a DIFFERENT row wearing a serial that is already
 * taken raises, loudly, and the outbox keeps it for a human to look at.
 *
 * ## The payload
 *
 * Built by {@see RowMapper}, which is also what {@see OnlineWrite} uses — the
 * two differ in their conflict clause and in nothing else, so the column
 * discovery, the `branch_id` stamp and the two value conversions live in one
 * place rather than two.
 */
class Pusher
{
    /** Groups per Postgres transaction. A sale is 3–10 rows, so this is small. */
    private const GROUPS_PER_BATCH = 50;

    /** Rows per INSERT statement — well inside Postgres's 65535-parameter ceiling. */
    private const ROWS_PER_STATEMENT = 200;

    public function __construct(
        private readonly Outbox $outbox,
        private readonly RowMapper $rows,
        private readonly RemoteStock $stock,
    ) {}

    /**
     * Drain the outbox into $remote.
     *
     * @return array{groups: int, rows: int} what this cycle actually pushed
     */
    public function push(Connection $remote): array
    {
        $instance = Instance::current();

        if ($instance === null) {
            throw new RuntimeException('This device has no branch identity, so it has nothing to push as.');
        }

        $branchId = (string) $instance->branch_id;
        $groups = 0;
        $rows = 0;

        while (true) {
            $batch = $this->outbox->pendingGroups(self::GROUPS_PER_BATCH);

            if ($batch->isEmpty()) {
                break;
            }

            $sequences = $batch->flatten(1)->pluck('sequence')->all();

            try {
                // One Postgres transaction for the batch. The local `synced_at`
                // stamps land only after it commits, so a failure anywhere
                // leaves every entry pending and the next cycle starts over —
                // harmlessly, because the inserts are idempotent.
                $rows += $remote->transaction(fn () => $this->pushBatch($remote, $batch, $branchId));
            } catch (\Throwable $e) {
                $this->outbox->markFailed($sequences, $e->getMessage());

                throw $e;
            }

            $this->outbox->markSynced($sequences);

            $groups += $batch->count();
        }

        return ['groups' => $groups, 'rows' => $rows];
    }

    /**
     * @param  Collection<int, Collection<int, SyncOutboxEntry>>  $batch
     * @return int rows inserted or absorbed
     */
    private function pushBatch(Connection $remote, Collection $batch, string $branchId): int
    {
        $written = 0;

        /** @var list<string> $touchedVariants */
        $touchedVariants = [];

        foreach ($batch as $entries) {
            // Tables in the order their first row was written, which is FK
            // order: a transaction exists before its items, a sale_return
            // before its lines. Nothing else establishes that ordering, and
            // Postgres's foreign keys would reject the reverse.
            foreach ($entries->groupBy('table_name') as $table => $tableEntries) {
                $ids = $tableEntries->pluck('row_id')->all();

                $written += $this->pushTable($remote, (string) $table, $ids, $branchId);

                if ($table === 'inventory_ledger') {
                    $touchedVariants = array_merge(
                        $touchedVariants,
                        DB::table('inventory_ledger')->whereIn('id', $ids)->pluck('variant_id')->all(),
                    );
                }
            }
        }

        $this->stock->recompute($remote, $branchId, $touchedVariants);

        return $written;
    }

    /**
     * @param  list<string>  $ids
     */
    private function pushTable(Connection $remote, string $table, array $ids, string $branchId): int
    {
        $payload = $this->rows->outboundRows($remote, $table, $ids, $branchId);

        if ($payload === []) {
            // The outbox points at a row that is no longer there. Nothing in
            // this app deletes from these tables — they are immutable and
            // trigger-guarded — so the realistic cause is a database restore to
            // a point before the sale. Skipping is right: there is nothing to
            // push, and the entry is marked synced so it stops being asked
            // about.
            return 0;
        }

        foreach (array_chunk($payload, self::ROWS_PER_STATEMENT) as $chunk) {
            $this->insertIgnoringId($remote, $table, $chunk);
        }

        return count($payload);
    }

    /**
     * `insert ... on conflict ("id") do nothing`, compiled by the grammar so the
     * quoting and the placeholders are Laravel's, with only the conflict clause
     * appended. See the class docblock for why neither `upsert()` nor
     * `insertOrIgnore()` can express this.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertIgnoringId(Connection $remote, string $table, array $rows): void
    {
        $query = $remote->table($table);

        $sql = $query->getGrammar()->compileInsert($query, $rows).' on conflict ("id") do nothing';

        $remote->insert($sql, Arr::flatten($rows, 1));
    }
}
