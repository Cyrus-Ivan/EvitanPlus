<?php

namespace App\Support\Sync;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/**
 * Keeps Postgres's `stock_snapshots` true for this branch.
 *
 * Postgres has **no trigger** behind that table. The branch role holds INSERT
 * and UPDATE on it (`BranchGrants`) precisely because the till is what maintains
 * it, and until something calls this it sits at whatever value it was last
 * given while the ledger moves underneath it — with M7's baseline, M8's queue
 * acceptance and M10's monitor all reading it as though it meant something.
 *
 * Two callers, for the two ways stock moves upward: {@see Pusher} after a batch
 * of sale rows lands, and {@see OnlineWrite} after a CSV import's adjustment
 * rows do. Extracted in M6.5 when the second appeared.
 *
 * ## Recomputed, never copied
 *
 * The figure comes from Postgres's OWN ledger, not from the local snapshot.
 * That makes it idempotent — a repeat converges instead of double-counting —
 * and authoritative, which matters from M7 on, when the local number becomes
 * *reconciled* stock (baseline minus unsynced out-movements) and is deliberately
 * a different number.
 *
 * ## It depends on Postgres's ledger being complete
 *
 * If a snapshot exists upstream without the movements that produced it, this
 * sums only what is there, goes negative, and `stock_snapshots_non_negative`
 * refuses the whole cycle. That is the right failure — writing a stock figure
 * known to be wrong would be worse — but it makes one rule binding on everything
 * that ever puts opening stock into a business schema: **write a ledger entry,
 * not just a snapshot.** M6.5's online CSV import is the first path that does.
 */
class RemoteStock
{
    /**
     * One branch's stock for a known set of variants, keyed by variant id.
     *
     * No aggregate and no GROUP BY, because there is nothing to aggregate:
     * `stock_snapshots` is keyed `(branch_id, variant_id)`, so one branch answers
     * with at most one row per variant and the figure is already the figure.
     *
     * A variant the branch holds no row for is simply ABSENT from the result
     * rather than present as zero — callers decide what a missing shelf means,
     * and every one of them so far reads it as zero for the reason upstream
     * gives: a snapshot is written the first time stock moves, so no row means
     * none has.
     *
     * `variants` is never touched here, which is also how `variants.cost` stays
     * out of reach: `HidesCostFromNonOwners` is a model hook and would not run on
     * a query-builder row.
     *
     * Static and connection-taking, like everything else that reads the business
     * database: the connection is opened by {@see OnlineRead} or {@see BranchCycle}
     * and handed in, never acquired here.
     *
     * @param  list<string>  $variantIds
     * @return array<string, int>
     */
    public static function read(Connection $remote, string $branchId, array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        return $remote->table('stock_snapshots')
            ->whereIn('variant_id', $variantIds)
            ->where('branch_id', $branchId)
            ->get(['variant_id', 'quantity'])
            ->mapWithKeys(fn (object $row): array => [(string) $row->variant_id => (int) $row->quantity])
            ->all();
    }

    /**
     * @param  list<string>  $variantIds
     */
    public function recompute(Connection $remote, string $branchId, array $variantIds): void
    {
        foreach (array_values(array_unique($variantIds)) as $variantId) {
            $remote->statement(
                'insert into stock_snapshots (id, branch_id, variant_id, quantity, created_at, updated_at)
                 select ?, ?, ?, coalesce(sum(quantity), 0), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                   from inventory_ledger
                  where branch_id = ? and variant_id = ?
                 on conflict (branch_id, variant_id)
                 do update set quantity = excluded.quantity, updated_at = excluded.updated_at',
                [(string) Str::uuid(), $branchId, $variantId, $branchId, $variantId],
            );
        }
    }
}
