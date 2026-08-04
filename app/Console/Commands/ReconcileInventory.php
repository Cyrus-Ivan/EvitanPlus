<?php

namespace App\Console\Commands;

use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use Illuminate\Console\Command;

/**
 * Rebuild every stock snapshot from the ledger (the source of truth). The
 * snapshot cannot drift through the app — StockLedger::record() is the single
 * write path — so this exists for what the app cannot control: a hand-edited
 * database file.
 *
 * Safe on a hydrated till since M11.6, and it was not before. Hydration used to
 * bring snapshots down without the ledger behind them, so a rebuild summed an
 * empty ledger and zeroed every variant — which LaunchProvisioner runs on every
 * launch. Now the branch's ledger comes down with them, and upstream a snapshot
 * IS its ledger sum by construction (see RemoteStock::recompute()), so this is a
 * no-op on a freshly hydrated device rather than a wipe.
 */
class ReconcileInventory extends Command
{
    protected $signature = 'inventory:reconcile';

    protected $description = 'Rebuild all stock snapshots from the inventory ledger';

    public function handle(StockLedger $ledger): int
    {
        $drifted = 0;

        // Archived variants keep their history too — reconcile everything.
        Variant::query()->chunkById(200, function ($variants) use ($ledger, &$drifted) {
            foreach ($variants as $variant) {
                $before = $ledger->currentStock($variant);
                $after = $ledger->reconcile($variant);

                if ($before !== $after) {
                    $drifted++;
                    $this->warn("{$variant->sku}: snapshot {$before} → ledger {$after}");
                }
            }
        });

        $this->info($drifted === 0
            ? 'All snapshots already match the ledger.'
            : "Reconciled {$drifted} drifted snapshot(s).");

        return self::SUCCESS;
    }
}
