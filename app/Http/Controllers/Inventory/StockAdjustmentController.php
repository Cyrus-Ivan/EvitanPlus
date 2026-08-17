<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockAdjustmentRequest;
use App\Models\Variant;
use App\Support\Audit\AuditRecorder;
use App\Support\Inventory\AdjustmentQueue;
use App\Support\Inventory\AdjustmentResolver;
use App\Support\Inventory\StockLedger;
use App\Support\Sync\OnlineWrite;
use App\Support\Sync\StockReconciler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Manual stock adjustment — a shelf recount, a write-off, a correction — asked
 * for here and applied when somebody confirms it.
 *
 * ## It no longer writes the ledger, and that is the change schema 21 made
 *
 * This used to write a `type = 'stock_adjustment'` entry outright. It now writes
 * a PENDING row and stops ({@see AdjustmentQueue}); the movement happens when a
 * person at the branch agrees it did ({@see AdjustmentResolver}).
 *
 * Including when the branch is this one, which is the part worth defending. An
 * adjustment aimed at another branch has always been mediated, on the grounds
 * that the issuer cannot see the target's unsynced sales — an argument about
 * arithmetic. The wider rule rests on something else: a recount typed here is
 * still a claim about what is on a shelf somebody has to go and look at, and one
 * queue that every stock movement passes through is a great deal easier to
 * reason about than a queue with an exception in it. It also leaves
 * `stock_adjustment` with exactly ONE writer, where it had three.
 *
 * ## Still online-only, since M7, and for the same reason
 *
 * Stock is the one quantity the business and the branch both influence, and an
 * adjustment is a statement about the BUSINESS's figure, not this device's. So
 * it is an {@see OnlineWrite}, refused when the device is offline.
 *
 * Until M7 this wrote local SQLite and stopped. `InventoryLedger::shouldPush()`
 * excludes `stock_adjustment` rows from the outbox — correctly, since they are
 * online-authoritative — so a recount typed at a till reached nothing. That was
 * merely invisible while nothing read the upstream number. The moment M7's
 * {@see StockReconciler} began pulling a baseline, it became destructive: the
 * baseline never contained the correction, so
 * `reconciled = baseline + unsynced sales` quietly restored the old count on the
 * next cycle, and on every cycle after that, with a green sync indicator
 * throughout.
 */
class StockAdjustmentController extends Controller
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly AuditRecorder $audit,
        private readonly OnlineWrite $online,
        private readonly AdjustmentQueue $queue,
    ) {}

    /**
     * Ask for a stock adjustment. All three input modes resolve to a signed
     * delta, and a zero-delta no-op is rejected as a validation error.
     *
     * ## The operator never types a sign
     *
     * `value` is positive whichever mode was picked and the direction is the mode
     * itself — see {@see StockAdjustmentRequest}. The minus below is the only
     * place a removal acquires one.
     *
     * ## The delta is snapshotted, and "set to count" can go stale
     *
     * `set` mode records `target - on hand right now`. If stock moves before
     * somebody confirms it, that delta no longer produces the count that was
     * typed — recount to 3 from 10 records −7, and if five sell first the branch
     * has 5 and −7 will not fit.
     *
     * Deliberately not modelled. Storing the mode and target and recomputing at
     * approval would make a partial approval meaningless ("approve 2 of set-to-3"
     * says nothing), and the drift already has an answer: the approver partially
     * approves −5, which is both correct and the number they can see. The dialog
     * shows what the count was when the request was made so the difference is
     * visible rather than silent.
     */
    public function store(StockAdjustmentRequest $request, Variant $variant): RedirectResponse
    {
        // A withdrawn product has no shelf to recount. Archiving is this app's
        // delete, so stock sent here is stock nobody can sell, on no screen
        // (`/inventory` filters to active items) and still in the ledger sum.
        // The three sibling paths refuse this in validation via
        // {@see SellableVariant}; this one takes its variant from the URL, where
        // "not addressable for this operation" is a 404. The item's flag counts
        // as much as the variant's — a variant of an archived item is not on sale
        // whatever its own says.
        abort_if($variant->archived_at !== null || $variant->item?->archived_at !== null, 404);

        $data = $request->validated();

        $onHand = $this->ledger->currentStock($variant);

        $delta = match ($data['mode']) {
            'add' => $data['value'],
            'remove' => -$data['value'],
            default => $data['value'] - $onHand,
        };

        // Only reachable from `set` now: `add` and `remove` are validated at
        // `min:1`, so the one remaining way to ask for nothing is to recount a
        // shelf to the number already on it.
        if ($delta === 0) {
            throw ValidationException::withMessages([
                'value' => 'This adjustment would not change the stock level.',
            ]);
        }

        $this->online->run(function () use ($variant, $delta, $data, $request): void {
            $this->queue->enqueue([[
                'branch_id' => $this->queue->thisBranch(),
                'variant_id' => (string) $variant->getKey(),
                'quantity' => $delta,
                'reference_type' => 'manual_adjustment',
                'notes' => $data['notes'] ?? null,
            ]], $request->user());
        }, 'Stock');

        // Nothing below zero is checked here any more: the request is not the
        // movement, and what stock allows is a question for the moment somebody
        // confirms it. StockLedger still refuses it, then.
        $this->audit->record(
            action: 'inventory.stock.queued',
            auditable: $variant,
            description: sprintf('Asked for a stock adjustment of %+d.', $delta),
            new: [
                'delta' => $delta,
                'mode' => $data['mode'],
                'on_hand_when_asked' => $onHand,
                'notes' => $data['notes'] ?? null,
            ],
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Adjustment queued. Confirm it under Pending stock adjustments to move the stock.',
        ]);
    }
}
