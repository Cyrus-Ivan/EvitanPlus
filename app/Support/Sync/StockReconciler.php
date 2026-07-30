<?php

namespace App\Support\Sync;

use App\Models\SyncCursor;
use App\Support\Inventory\StockLedger;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The second half of a cycle's pull: this branch's stock, agreed with the
 * business rather than merely asserted (MULTIBRANCH-BRAINSTORM.md §10;
 * docs/glossary.md §4).
 *
 * ## Three numbers, and they are deliberately different
 *
 *  - **Stock baseline** — what Postgres hands back. It reflects online entries
 *    and already-pushed local movements, and does NOT include this till's
 *    unsynced sales.
 *  - **Stock snapshot** — a `stock_snapshots` row, updated on every local ledger
 *    insert.
 *  - **Reconciled stock** — the sum below, and the figure the till actually
 *    sells against.
 *
 * ```
 * reconciled = baseline + Σ(unsynced local ledger movements)
 * ```
 *
 * §10 words this as "baseline − local unsynced out-movements", which is the same
 * arithmetic said in absolute values. It is implemented as an ADDITION of SIGNED
 * quantities because the local ledger is signed — a sale is negative, a return
 * positive, a defect zero — and subtracting absolute values would get returns
 * exactly backwards, adding stock the branch never regained.
 *
 * ## It is not conflict resolution
 *
 * Both sides only ever INSERT into the ledger, so the two contributions never
 * contest one another; they add. There is no last-write-wins anywhere in this
 * system, and this is the one place someone would be tempted to introduce it.
 * Blindly overwriting the local figure with the baseline would discard every
 * sale not yet pushed — which on a branch that has been offline all morning is
 * the morning's takings.
 *
 * ## Self-healing
 *
 * Once the unsynced sales push, {@see Pusher} recomputes the upstream snapshot
 * from Postgres's own ledger, the next baseline already contains them, and the
 * unsynced set is empty. The subtraction converges to zero on its own and no
 * state has to be remembered between cycles.
 */
class StockReconciler
{
    /** The cursor key for the baseline. Its own table, so its own bookmark. */
    private const CURSOR = 'stock_snapshots';

    /** Rows per round trip. */
    private const CHUNK = 500;

    /**
     * Pull the baseline and reconcile every variant it names.
     *
     * @return int variants reconciled
     */
    public function reconcile(Connection $remote, string $branchId): int
    {
        $position = SyncCursor::positionOf(self::CURSOR);

        $query = $remote->table(self::CURSOR)
            ->select(['variant_id', 'quantity', 'updated_at'])
            ->where('branch_id', $branchId)
            ->orderBy('updated_at')
            ->orderBy('variant_id');

        if ($position !== null) {
            // `>=`, not `>` — two snapshots recomputed in the same instant share
            // a timestamp, and `>` would strand the second behind the bookmark.
            $query->where('updated_at', '>=', $position);
        }

        $reconciled = 0;
        $highest = null;

        DB::transaction(function () use ($query, &$reconciled, &$highest): void {
            foreach ($query->cursor()->chunk(self::CHUNK) as $chunk) {
                foreach ($chunk as $row) {
                    // Ordered by this column, so the last row seen is the max.
                    $highest = $row->updated_at === null ? $highest : (string) $row->updated_at;

                    $this->apply((string) $row->variant_id, (int) $row->quantity);

                    $reconciled++;
                }
            }

            SyncCursor::advance(self::CURSOR, $highest);
        });

        return $reconciled;
    }

    /**
     * Write one variant's reconciled figure.
     *
     * Through the query builder rather than {@see StockLedger},
     * and that is not a shortcut: StockLedger's job is to append a ledger row and
     * move the snapshot by it. There is no movement here — nothing happened, this
     * is the same stock counted against a better source — so recording one would
     * invent inventory history that never occurred and double-count against the
     * pulled adjustment rows {@see Puller} has already inserted.
     */
    private function apply(string $variantId, int $baseline): void
    {
        // Every unsynced local movement for this variant, signed. The join is
        // what makes "unsynced" mean the outbox's answer rather than a second
        // opinion: `synced_at is null` is the one definition of the backlog
        // anywhere in this system (glossary §5).
        $unsynced = (int) DB::table('inventory_ledger')
            ->join('sync_outbox', function (JoinClause $join): void {
                $join->on('sync_outbox.row_id', '=', 'inventory_ledger.id')
                    ->where('sync_outbox.table_name', '=', 'inventory_ledger');
            })
            ->whereNull('sync_outbox.synced_at')
            ->where('inventory_ledger.variant_id', $variantId)
            ->sum('inventory_ledger.quantity');

        // Clamped, because a negative snapshot violates a first-order invariant
        // and this column is what the POS reads to decide what may be sold. It
        // should be unreachable: nothing subtracts this branch's stock remotely
        // without M8's queue, which exists precisely so an adjustment is
        // validated against reconciled stock before it becomes a ledger entry.
        // The clamp is the honest floor, not a design.
        $reconciled = max(0, $baseline + $unsynced);

        $existing = DB::table('stock_snapshots')->where('variant_id', $variantId)->first();

        if ($existing === null) {
            DB::table('stock_snapshots')->insert([
                'id' => (string) Str::uuid(),
                'variant_id' => $variantId,
                'quantity' => $reconciled,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        if ((int) $existing->quantity === $reconciled) {
            return;
        }

        DB::table('stock_snapshots')
            ->where('variant_id', $variantId)
            ->update(['quantity' => $reconciled, 'updated_at' => now()]);
    }
}
