<?php

namespace App\Support\Inventory;

use App\Exceptions\NegativeInventoryException;
use App\Models\InventoryLedger;
use App\Models\StockSnapshot;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;

/**
 * The single write path for all inventory movement (CLAUDE.md: "There is no
 * stock-adjustment table" — every sale, return, defect, manual adjustment and
 * CSV import is expressed as an append-only inventory_ledger entry).
 *
 * Each write is atomic: it locks the variant's stock snapshot, computes the
 * resulting on-hand, refuses to go negative, appends the ledger row, and
 * updates the snapshot. Later phases (POS, Returns, CSV import) funnel through
 * record() so this invariant lives in exactly one place.
 */
class StockLedger
{
    /**
     * Append one ledger entry for the variant and update its stock snapshot.
     *
     * @param  int  $quantity  Signed movement (+ adds stock, - removes). Defect
     *                         audit rows pass 0 (stock unchanged).
     * @param  string  $type  inventory_ledger.type — 'sale' | 'stock_adjustment'.
     * @param  string|null  $saleType  'sale' | 'return' | 'defect' (null for adjustments).
     * @param  string  $referenceType  'transaction' | 'manual_adjustment' | 'csv_import' | 'return' | 'transfer'.
     * @param  string|null  $referenceId  UUID of the source record, when any.
     * @param  string|null  $id  Force the row's UUID. One caller: an approved
     *                           pending adjustment, whose ledger entry takes the
     *                           queue row's own id so re-approving the same row
     *                           collides with itself instead of moving stock
     *                           twice ({@see AdjustmentResolver}).
     * @param  string|null  $counterpartyBranchCode  The other branch involved, by
     *                                               code because this is read offline. Null unless
     *                                               another branch asked for the movement or it is a
     *                                               leg of a transfer.
     * @param  string|null  $batchId  The stock batch this movement was one line of,
     *                                if any. Not a foreign key and not looked up:
     *                                its whole job is to make sibling rows equal to
     *                                each other, so Stock History can say they came
     *                                off the shelf together on a till with no
     *                                connection. Null for anything issued singly.
     * @param  string|null  $batchReference  The readable name of that same batch —
     *                                       `MNL-B-000001` ({@see StockBatch}). Carried for the
     *                                       same reason and stored beside the uuid, because the
     *                                       dialog that names a batch and the filter that finds
     *                                       its siblings both have to work with no connection.
     *
     * @throws NegativeInventoryException when the movement would drive stock below zero.
     */
    public function record(
        Variant $variant,
        int $quantity,
        string $type,
        ?string $saleType,
        string $referenceType,
        ?string $referenceId,
        User $performedBy,
        ?string $notes = null,
        ?string $id = null,
        ?string $counterpartyBranchCode = null,
        ?string $batchId = null,
        ?string $batchReference = null,
    ): InventoryLedger {
        return DB::transaction(function () use (
            $variant, $quantity, $type, $saleType, $referenceType, $referenceId, $performedBy, $notes,
            $id, $counterpartyBranchCode, $batchId, $batchReference,
        ) {
            // Lock (or create) the snapshot row so concurrent writes serialize.
            $snapshot = StockSnapshot::query()
                ->lockForUpdate()
                ->firstOrCreate(['variant_id' => $variant->getKey()], ['quantity' => 0]);

            $resulting = $snapshot->quantity + $quantity;

            if ($resulting < 0) {
                throw new NegativeInventoryException;
            }

            $entry = new InventoryLedger([
                'variant_id' => $variant->getKey(),
                'quantity' => $quantity,
                'resulting_snapshot' => $resulting,
                'type' => $type,
                'sale_type' => $saleType,
                'reference_id' => $referenceId,
                'batch_id' => $batchId,
                'batch_reference' => $batchReference,
                'reference_type' => $referenceType,
                'notes' => $notes,
                'counterparty_branch_code' => $counterpartyBranchCode,
                'performed_by' => $performedBy->getKey(),
            ]);

            // Assigned rather than filled: `id` is deliberately not fillable, and
            // HasUuidPrimaryKey only generates one when the attribute is empty.
            if ($id !== null) {
                $entry->id = $id;
            }

            $entry->save();

            $snapshot->update(['quantity' => $resulting]);

            return $entry;
        });
    }

    /**
     * Current on-hand for a variant, read from the snapshot cache (0 if none).
     */
    public function currentStock(Variant $variant): int
    {
        return (int) (StockSnapshot::where('variant_id', $variant->getKey())->value('quantity') ?? 0);
    }

    /**
     * Recompute on-hand directly from the ledger and rebuild the snapshot to
     * match. The ledger is the source of truth; this is the "reconciled against"
     * check from CLAUDE.md and a repair path after a restore.
     */
    public function reconcile(Variant $variant): int
    {
        return DB::transaction(function () use ($variant) {
            $total = (int) InventoryLedger::where('variant_id', $variant->getKey())->sum('quantity');

            StockSnapshot::updateOrCreate(
                ['variant_id' => $variant->getKey()],
                ['quantity' => $total],
            );

            return $total;
        });
    }
}
