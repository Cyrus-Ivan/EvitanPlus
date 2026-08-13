<?php

namespace App\Support\Inventory;

use App\Models\Instance;
use App\Models\User;
use App\Support\Sync\OnlineWrite;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/**
 * Asking for stock to move (docs/glossary.md §4 "pending stock adjustment").
 *
 * Every stock adjustment now starts here. A recount typed at this till, a CSV
 * import, an adjustment aimed at another branch, and both legs of a transfer all
 * write a `pending` row and stop; none of them touches the ledger. What turns one
 * into a real movement is a person at the affected branch agreeing that it
 * happened ({@see AdjustmentResolver}).
 *
 * That is a wider rule than the one M8 shipped, which mediated only the
 * cross-branch case on the grounds that the issuer could not see the target's
 * unsynced sales. True, and not the main point: the arithmetic was never what
 * was in doubt. Stock that moved on paper and stock that moved on the shelf are
 * different things — three were sent and two arrived, the delivery never came —
 * and no sum can tell them apart. Only somebody standing at the branch can.
 *
 * ## Why this class does not open a connection
 *
 * `enqueue()` registers its insert on the online write ALREADY RUNNING, and the
 * caller owns `OnlineWrite::run()`. That is not a style choice: a CSV import is
 * one online write around the whole file, so a class that opened its own would
 * nest a second Postgres connection and a second version gate inside the first
 * one's open transaction. Registering instead means an import's hundreds of rows
 * are one insert inside the cycle the import already has.
 *
 * `mirror()` cannot be used for the same reason it could not in M8: it re-reads
 * rows from SQLite, and there is no local row here. `inventory_update_queue` is
 * Postgres-only by decision — mirroring coordination between two devices would
 * give it a third copy on each of them.
 */
class AdjustmentQueue
{
    public function __construct(private readonly OnlineWrite $online) {}

    /**
     * Register one or more pending adjustments on the ambient online write.
     *
     * @param  list<array{
     *     branch_id: string,
     *     variant_id: string,
     *     quantity: int,
     *     reference_type: string,
     *     reference_id?: string|null,
     *     notes?: string|null,
     *     counterparty_branch_id?: string|null,
     *     parent_id?: string|null,
     * }>  $rows
     * @param  StockBatch|null  $batch  The one act these rows were issued as, if they were
     *                                  issued as one. A property of the CALL and not of a
     *                                  row: every row of a batch carries the same two values.
     *
     * @throws \RuntimeException when called outside an OnlineWrite::run().
     */
    public function enqueue(array $rows, User $issuedBy, ?StockBatch $batch = null): void
    {
        if ($rows === []) {
            return;
        }

        $issuerBranch = $this->thisBranch();

        $this->online->afterMirroring(function (Connection $remote) use ($rows, $issuedBy, $issuerBranch, $batch): void {
            // Here rather than in the caller, because this is the first moment a
            // connection to the business database exists and the serial is read
            // from the queue. It runs once for the whole enqueue — the mapping
            // below is what runs per row — so a batch gets one name, not one per
            // line, and the caller can read it back off the object afterwards.
            $batch?->mint($remote);

            $remote->table('inventory_update_queue')->insert(array_map(
                fn (array $row): array => [
                    'id' => (string) Str::uuid(),
                    'branch_id' => $row['branch_id'],
                    'counterparty_branch_id' => $row['counterparty_branch_id'] ?? null,
                    'parent_id' => $row['parent_id'] ?? null,
                    // Null on anything asked for on its own, which is most of
                    // what comes through here.
                    'batch_id' => $batch?->id,
                    'batch_reference' => $batch?->reference,
                    'variant_id' => $row['variant_id'],
                    'quantity' => $row['quantity'],
                    'reference_type' => $row['reference_type'],
                    // Only a CSV import sets one — its import_history row — so a
                    // confirmed movement can still be traced back to the file
                    // that asked for it. The resolver falls back to the queue
                    // row's own id when this is null.
                    'reference_id' => $row['reference_id'] ?? null,
                    'notes' => $row['notes'] ?? null,
                    'status' => 'pending',
                    'issued_by' => $issuedBy->getKey(),
                    // A person at a place, and the place is not in any of the
                    // other columns: `branch_id` is who must ANSWER, and a
                    // transfer can be arranged by a branch that is neither end of
                    // it. Stamped from `instance` here rather than passed in, so
                    // no caller can get it wrong or forget it. Null only on a
                    // device that has not been hydrated, which cannot reach this
                    // code — the online write needs the sealed credential.
                    'issued_by_branch_id' => $issuerBranch,
                    // Postgres's clock, not PHP's: these are `timestamptz`, and
                    // this app's convention everywhere else is a naive local
                    // string. Stamping one with the other is the eight-hour drift
                    // M6 spent a phase finding. The order the branch works its
                    // queue in depends on these.
                    'created_at' => $remote->raw('CURRENT_TIMESTAMP'),
                    'updated_at' => $remote->raw('CURRENT_TIMESTAMP'),
                ],
                $rows,
            ));
        });
    }

    /**
     * This device's branch, which is the target for anything it asks of itself.
     */
    public function thisBranch(): string
    {
        return (string) Instance::current()?->branch_id;
    }
}
