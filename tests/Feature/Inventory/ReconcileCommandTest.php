<?php

namespace Tests\Feature\Inventory;

use App\Models\StockSnapshot;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 18 S8 (F-11) — the ledger-is-source-of-truth repair path is reachable:
 * `inventory:reconcile` rebuilds every snapshot from the ledger sum, for
 * databases the app didn't write (restored or hand-edited files).
 */
class ReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_rebuilds_a_drifted_snapshot_from_the_ledger(): void
    {
        $variant = Variant::factory()->create();
        app(StockLedger::class)->record(
            $variant, 5, 'stock_adjustment', null, 'manual_adjustment', null,
            User::factory()->admin()->create(),
        );

        // Simulate out-of-band drift (snapshots are the one mutable stock store).
        StockSnapshot::where('variant_id', $variant->id)->update(['quantity' => 99]);

        $this->artisan('inventory:reconcile')
            ->expectsOutputToContain('Reconciled 1 drifted snapshot(s).')
            ->assertSuccessful();

        $this->assertSame(5, app(StockLedger::class)->currentStock($variant));
    }

    public function test_reconcile_reports_clean_when_nothing_drifted(): void
    {
        $variant = Variant::factory()->create();
        app(StockLedger::class)->record(
            $variant, 3, 'stock_adjustment', null, 'manual_adjustment', null,
            User::factory()->admin()->create(),
        );

        $this->artisan('inventory:reconcile')
            ->expectsOutputToContain('All snapshots already match the ledger.')
            ->assertSuccessful();
    }

    /**
     * A snapshot with NO ledger behind it reconciles to zero, not to itself.
     *
     * This is the case the command's own reason for existing turns on, and the
     * one it had no coverage for. `LaunchProvisioner` runs `inventory:reconcile`
     * on every launch, and before M11.6 a device hydrated from Postgres came up
     * holding `stock_snapshots` rows with no `inventory_ledger` behind them — so
     * the first launch after setup zeroed every variant in the shop. Hydration
     * copies this branch's ledger now, which is what makes the pairing hold; this
     * pins the arithmetic that made the absence so expensive.
     */
    public function test_a_snapshot_with_no_ledger_behind_it_reconciles_to_zero(): void
    {
        $variant = Variant::factory()->create();

        // Written directly, the way a partial restore or an older hydrate left
        // them: a figure with no movements to support it.
        StockSnapshot::query()->create(['variant_id' => $variant->id, 'quantity' => 42]);

        $this->artisan('inventory:reconcile')
            ->expectsOutputToContain('Reconciled 1 drifted snapshot(s).')
            ->assertSuccessful();

        $this->assertSame(0, app(StockLedger::class)->currentStock($variant));
    }

    /**
     * Reconciling twice changes nothing the second time.
     *
     * The command runs on EVERY launch, so "converges and then stops" is a
     * property of the boot path rather than a nicety — a repair that kept
     * reporting drift would either be repairing nothing or breaking something.
     */
    public function test_reconciling_is_idempotent(): void
    {
        $variant = Variant::factory()->create();
        app(StockLedger::class)->record(
            $variant, 7, 'stock_adjustment', null, 'manual_adjustment', null,
            User::factory()->admin()->create(),
        );

        StockSnapshot::where('variant_id', $variant->id)->update(['quantity' => 1]);

        $this->artisan('inventory:reconcile')->assertSuccessful();
        $this->assertSame(7, app(StockLedger::class)->currentStock($variant));

        $this->artisan('inventory:reconcile')
            ->expectsOutputToContain('All snapshots already match the ledger.')
            ->assertSuccessful();

        $this->assertSame(7, app(StockLedger::class)->currentStock($variant));
    }
}
