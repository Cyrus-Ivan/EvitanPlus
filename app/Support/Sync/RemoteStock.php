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
