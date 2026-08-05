<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryLedger;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A stock adjustment on a device with no connection.
 *
 * Its own class because `$connected` is decided in setUp, and this is the case
 * M7 exists to make impossible: a recount that reaches only local SQLite is
 * silently reverted by the next cycle's baseline, since
 * `InventoryLedger::shouldPush()` keeps `stock_adjustment` rows out of the
 * outbox and nothing else would ever carry them upward. Refusing is the only
 * honest answer — the alternative is a number that looks saved and is not.
 */
class OfflineStockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected ?bool $connected = false;

    public function test_an_adjustment_is_refused_offline_and_changes_nothing()
    {
        $variant = Variant::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock.adjust', $variant->id), ['mode' => 'add', 'value' => 12, 'notes' => 'Recount'])
            ->assertRedirect();

        // Not merely "no ledger row": the whole act rolls back, so the stock
        // figure the shop reads is unchanged and there is nothing half-done to
        // discover later.
        $this->assertSame(0, InventoryLedger::count());
        $this->assertSame(0, app(StockLedger::class)->currentStock($variant));
    }

    public function test_the_refusal_says_selling_is_unaffected()
    {
        $variant = Variant::factory()->create();

        $response = $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock.adjust', $variant->id), ['mode' => 'add', 'value' => 12]);

        $toast = $response->getSession()->get('toast');

        $this->assertSame('error', $toast['type']);
        // The person reading this is standing at a working till. If the message
        // does not say so in the first line, a refused recount reads as an outage.
        $this->assertStringContainsString('selling, returns and receipts', strtolower($toast['message']));
    }
}
