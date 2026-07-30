<?php

namespace App\Http\Controllers\Inventory;

use App\Exceptions\NegativeInventoryException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockAdjustmentRequest;
use App\Models\Instance;
use App\Models\Variant;
use App\Support\Audit\AuditRecorder;
use App\Support\Inventory\StockLedger;
use App\Support\Sync\OnlineWrite;
use App\Support\Sync\RemoteStock;
use App\Support\Sync\StockReconciler;
use Illuminate\Database\Connection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Manual stock adjustment — a shelf recount, a write-off, a correction — as a
 * single `type = 'stock_adjustment'` ledger entry.
 *
 * ## Online-only, since M7
 *
 * Stock is the one quantity the business and the branch both influence, and an
 * adjustment is a statement about the BUSINESS's figure, not this device's. So
 * it is an {@see OnlineWrite}: Postgres and SQLite in one act, refused when the
 * device is offline — exactly like the CSV import, and for a reason that is
 * sharper here than anywhere else it applies.
 *
 * Until M7 this wrote local SQLite and stopped. `InventoryLedger::shouldPush()`
 * excludes `stock_adjustment` rows from the outbox — correctly, since they are
 * online-authoritative — so a recount typed at a till reached nothing. That was
 * merely invisible while nothing read the upstream number. The moment M7's
 * {@see StockReconciler} began pulling a baseline, it became
 * destructive: the baseline never contained the correction, so
 * `reconciled = baseline + unsynced sales` quietly restored the old count on the
 * next cycle, and on every cycle after that, with a green sync indicator
 * throughout. This is the same defect M6.5 found in the catalog and staff
 * screens, on the one write path that phase did not reach.
 */
class StockAdjustmentController extends Controller
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly AuditRecorder $audit,
        private readonly OnlineWrite $online,
        private readonly RemoteStock $stock,
    ) {}

    /**
     * Record a manual stock adjustment as a single ledger entry. Both input
     * modes resolve to a signed delta; a zero-delta no-op and any change that
     * would drive stock below zero are rejected as validation errors.
     */
    public function store(StockAdjustmentRequest $request, Variant $variant): RedirectResponse
    {
        $data = $request->validated();

        $delta = $data['mode'] === 'set'
            ? $data['value'] - $this->ledger->currentStock($variant)
            : $data['value'];

        if ($delta === 0) {
            throw ValidationException::withMessages([
                'value' => 'This adjustment would not change the stock level.',
            ]);
        }

        try {
            $this->online->run(function () use ($variant, $delta, $data, $request): void {
                $entry = $this->ledger->record(
                    variant: $variant,
                    quantity: $delta,
                    type: 'stock_adjustment',
                    saleType: null,
                    referenceType: 'manual_adjustment',
                    referenceId: null,
                    performedBy: $request->user(),
                    notes: $data['notes'] ?? null,
                );

                $this->online->mirror($entry);

                // Postgres keeps no trigger on stock_snapshots, so the ledger
                // row just sent changes the sum without changing the cached
                // total. Recomputed upstream from Postgres's OWN ledger once the
                // row has landed — which is what afterMirroring() is for, and
                // which is what makes the next cycle's baseline include this
                // adjustment instead of reverting it.
                $branchId = (string) Instance::current()?->branch_id;

                $this->online->afterMirroring(
                    fn (Connection $remote) => $this->stock->recompute($remote, $branchId, [(string) $variant->getKey()]),
                );
            }, 'Stock');
        } catch (NegativeInventoryException $e) {
            throw ValidationException::withMessages([
                'value' => $e->getMessage(),
            ]);
        }

        $this->audit->record(
            action: 'inventory.stock.adjusted',
            auditable: $variant,
            description: sprintf('Adjusted stock for variant by %+d.', $delta),
            new: [
                'delta' => $delta,
                'mode' => $data['mode'],
                'notes' => $data['notes'] ?? null,
            ],
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Stock adjusted.',
        ]);
    }
}
