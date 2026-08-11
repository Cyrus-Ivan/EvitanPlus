<?php

namespace App\Support\Inventory;

use App\Models\InventoryLedger;

/**
 * THE OPENING WINDOW: the stretch of a branch's life before it has sold
 * anything (docs/glossary.md §4).
 *
 * Since schema 21 every stock movement is asked for and answered by a person at
 * the affected branch, on the grounds that a stock figure is a claim about a
 * shelf somebody has to go and look at. That is right about a shelf with a
 * history and empty about a shelf with none. `business:provision` seeds no
 * catalog and no stock, and hydration copies down whatever the business database
 * already holds — so a newly onboarded device arrives with every variant at
 * zero, and the only way to move it was one dialog line per variant, capped at a
 * hundred per batch.
 *
 * While this window is open, {@see OpeningStockImporter} may write the ledger
 * directly. What makes that safe is exactly what the window tests: there are no
 * sales to contradict, nothing has been reconciled against these figures, and
 * the person uploading the file is the person standing at the branch. The moment
 * the branch sells one item, the shelf has a past and every later movement goes
 * back through {@see AdjustmentQueue} and {@see AdjustmentResolver}.
 *
 * ## Derived, never stored
 *
 * A flag would have to be set by something and could drift from the truth. This
 * asks the ledger, which is the truth. Two consequences fall out for free:
 *
 * - A REBUILT device at an established branch hydrates that branch's sales
 *   history (M11.6), so it correctly reports the window shut — a replacement
 *   till cannot re-open the door by being new hardware.
 * - A genuinely new branch hydrates no sales, so it is open, and stays open
 *   across as many sittings as onboarding takes. Import half the catalog on
 *   Monday and the rest on Wednesday; re-upload a file with a typo corrected.
 *   None of that is stock moving behind anyone's back, because nobody has
 *   sold from these numbers yet.
 *
 * `type = 'sale'` covers sales, returns and defects — the three things that
 * split on `sale_type`, not on `type`. Any of them means the branch is trading.
 */
final class OpeningWindow
{
    /**
     * True while this branch has never recorded a sale, return or defect.
     */
    public static function isOpen(): bool
    {
        return ! InventoryLedger::query()->where('type', 'sale')->exists();
    }
}
