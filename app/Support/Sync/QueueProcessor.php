<?php

namespace App\Support\Sync;

use Illuminate\Database\Connection;
use stdClass;

/**
 * Step 2 of a sync cycle: the adjustments other people issued against this
 * branch, answered (MULTIBRANCH-BRAINSTORM.md §10, docs/glossary.md §4 "inventory
 * update queue", M8).
 *
 * ## Why an adjustment cannot simply be applied
 *
 * An Owner or Admin, online, may subtract stock from a branch — a write-off at a
 * shop that is closed, a correction to a till that has been offline all
 * afternoon. Writing that straight into the ledger would be a statement about
 * stock the issuer cannot see: the target may be holding unsynced sales that have
 * already consumed it, and the two together drive reconciled stock negative. So
 * the issuer writes a **pending** row and the TARGET decides, here, with the only
 * copy of the facts that includes its own backlog.
 *
 * That is why this step sits between the push and the pull and why the order is
 * load-bearing: the push has just delivered the movements that really happened,
 * so the baseline read below is as close to true as it can be, and whatever is
 * still unsynced is added on top.
 *
 * ## The arithmetic is M7's, deliberately
 *
 *     available  = upstream stock_snapshots.quantity + Σ(signed unsynced local ledger)
 *     accept iff available + quantity >= 0
 *
 * The first term is the **stock baseline** and the sum is **reconciled stock** —
 * the same two terms {@see StockReconciler} uses, taken from the same method so
 * there is one definition of "unsynced" and not two. Validating against
 * reconciled stock is strictly stricter than Postgres's own
 * `stock_snapshots_non_negative`, because the unsynced term is where the negative
 * movements are: if reconciled stock survives the adjustment then the upstream
 * figure survives it too. Neither side can go negative, which is the whole claim
 * §10 makes for this table.
 *
 * ## Accepting writes UPSTREAM ONLY
 *
 * The accepted ledger entry is inserted into Postgres and nowhere else. It comes
 * back down moments later in the same cycle, through the ordinary pull —
 * {@see Puller} already selects this branch's `type = 'stock_adjustment'` rows —
 * and {@see StockReconciler} then sets the local snapshot from the refreshed
 * baseline. Writing it locally as well would give `stock_adjustment` rows two
 * writers, which is the single-writer rule that keeps last-write-wins out of this
 * system; it would also enqueue the row into the outbox and hand the business
 * back its own data.
 *
 * ## Two Postgres details that are easy to get wrong
 *
 *  - **The resolution must not touch `updated_at`.** The branch role holds
 *    column-level UPDATE on exactly four columns (`BranchGrants::UPDATE_COLUMNS`);
 *    adding a fifth to the SET list is `permission denied for table
 *    inventory_update_queue`, which reads as a missing grant and is in fact the
 *    matrix being exactly right.
 *  - **Timestamps come from the server's clock, never PHP's.** These columns are
 *    `timestamptz` and this app's convention is a naive local string; stamping one
 *    with the other is the eight-hour drift M6 spent a phase finding. Nothing here
 *    goes through {@see RowMapper}, so the safe move is to let the server do it.
 *    `CURRENT_TIMESTAMP` rather than `now()` for no deeper reason than that it is
 *    the same thing in standard SQL, which lets the fast tests run this exact
 *    statement against a SQLite stand-in.
 */
class QueueProcessor
{
    public function __construct(
        private readonly StockReconciler $reconciler,
        private readonly RemoteStock $stock,
    ) {}

    /**
     * Answer every pending adjustment aimed at this branch, oldest first.
     *
     * @return int rows resolved
     */
    public function process(Connection $remote, string $branchId): int
    {
        $pending = $remote->table('inventory_update_queue')
            ->where('branch_id', $branchId)
            ->where('status', 'pending')
            // §10 says "walks the queue IN ORDER", and it matters: two
            // subtractions that fit one at a time may not fit together, so which
            // one is rejected has to be decided by age rather than by whatever
            // Postgres felt like returning first.
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $resolved = 0;

        foreach ($pending as $row) {
            $this->resolve($remote, $branchId, $row);

            $resolved++;
        }

        return $resolved;
    }

    /**
     * Accept or reject one row.
     *
     * Wrapped in a transaction so the ledger entry, the recomputed snapshot and
     * the resolution land together — a crash between them would otherwise leave
     * an applied adjustment still marked pending.
     */
    private function resolve(Connection $remote, string $branchId, stdClass $row): void
    {
        $id = (string) $row->id;
        $variantId = (string) $row->variant_id;
        $quantity = (int) $row->quantity;

        $remote->transaction(function () use ($remote, $branchId, $row, $id, $variantId, $quantity): void {
            // Already applied, and this is the one thing the shared id buys:
            // if a previous cycle inserted the entry but lost the connection
            // before recording the resolution, re-deciding could REJECT a row
            // whose ledger entry already exists — the baseline now contains it,
            // so the same subtraction may no longer fit. Recognising it costs one
            // primary-key lookup.
            if ($remote->table('inventory_ledger')->where('id', $id)->exists()) {
                $this->accepted($remote, $id);

                return;
            }

            $available = $this->available($remote, $branchId, $variantId);
            $resulting = $available + $quantity;

            if ($resulting < 0) {
                $this->rejected($remote, $id, $available, $quantity);

                return;
            }

            $this->applyUpstream($remote, $branchId, $row, $resulting);
            $this->stock->recompute($remote, $branchId, [$variantId]);
            $this->accepted($remote, $id);
        });
    }

    /**
     * Reconciled stock for one variant, from this branch's point of view.
     */
    private function available(Connection $remote, string $branchId, string $variantId): int
    {
        $baseline = (int) ($remote->table('stock_snapshots')
            ->where('branch_id', $branchId)
            ->where('variant_id', $variantId)
            ->value('quantity') ?? 0);

        return $baseline + $this->reconciler->unsyncedMovement($variantId);
    }

    /**
     * Insert the accepted adjustment as a real ledger entry, upstream.
     *
     * The entry's id IS the queue row's id. That makes the insert idempotent for
     * free — a retry collides with itself and does nothing — and it makes
     * `ledger_entry_id` a link anyone can follow without a lookup table. A
     * rejected row that is later re-issued is a NEW queue row, so the ids never
     * contend.
     */
    private function applyUpstream(Connection $remote, string $branchId, stdClass $row, int $resulting): void
    {
        $remote->insert(
            'insert into inventory_ledger
               (id, branch_id, variant_id, quantity, resulting_snapshot, type, sale_type,
                reference_id, reference_type, notes, performed_by, created_at)
             values (?, ?, ?, ?, ?, ?, null, ?, ?, ?, ?, CURRENT_TIMESTAMP)
             on conflict ("id") do nothing',
            [
                (string) $row->id,
                $branchId,
                (string) $row->variant_id,
                (int) $row->quantity,
                $resulting,
                // The three-column vocabulary allows nothing else (glossary §4):
                // this is an adjustment, made by hand, by a person.
                'stock_adjustment',
                (string) $row->id,
                'manual_adjustment',
                $row->notes,
                (string) $row->issued_by,
            ],
        );
    }

    private function accepted(Connection $remote, string $id): void
    {
        $remote->table('inventory_update_queue')->where('id', $id)->update([
            'status' => 'accepted',
            'resolved_at' => $remote->raw('CURRENT_TIMESTAMP'),
            'ledger_entry_id' => $id,
        ]);
    }

    /**
     * Mark a row rejected, saying what the numbers were.
     *
     * The reason is read by whoever issued it, on another device, possibly days
     * later, to decide whether to re-issue — so it carries the figures rather than
     * a category. "Would drive stock negative" tells them nothing they can act on.
     */
    private function rejected(Connection $remote, string $id, int $available, int $quantity): void
    {
        $remote->table('inventory_update_queue')->where('id', $id)->update([
            'status' => 'rejected',
            'resolved_at' => $remote->raw('CURRENT_TIMESTAMP'),
            'rejection_reason' => sprintf(
                'This branch had %d on hand when it answered, so removing %d would have left %d. '
                .'Re-issue it for %d or less if the stock is still meant to come off.',
                $available,
                abs($quantity),
                $available + $quantity,
                max(0, $available),
            ),
        ]);
    }
}
