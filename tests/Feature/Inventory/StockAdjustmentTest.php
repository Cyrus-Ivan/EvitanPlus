<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryLedger;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Inventory\Concerns\FakesThePendingQueue;
use Tests\TestCase;

/**
 * Asking for a manual stock adjustment.
 *
 * Since schema 21 that is all this screen does: it writes a PENDING row and
 * stops. The stock moves when somebody at the branch confirms it happened, which
 * is {@see AdjustmentResolutionTest}.
 *
 * So these tests come in pairs — what the request records, and what confirming
 * it then does — because the interesting failure is no longer "the ledger row is
 * wrong" but "the request moved stock on its own" or "confirming it lost what was
 * asked for".
 */
class StockAdjustmentTest extends TestCase
{
    use FakesThePendingQueue, RefreshDatabase;

    private function stockOf(Variant $variant): int
    {
        return app(StockLedger::class)->currentStock($variant);
    }

    public function test_asking_for_stock_moves_nothing_until_it_is_confirmed(): void
    {
        $remote = $this->fakeBusinessQueue();
        $variant = Variant::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('stock.adjust', $variant->id), ['mode' => 'add', 'value' => 12, 'notes' => 'Restock'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // The whole point of the change: asking is not moving. A shelf that has
        // not been looked at holds what it held.
        $this->assertSame(0, $this->stockOf($variant));
        $this->assertSame(0, InventoryLedger::count());

        $queued = $remote->table('inventory_update_queue')->first();

        $this->assertSame('pending', $queued->status);
        $this->assertSame(12, (int) $queued->quantity);
        $this->assertSame('manual_adjustment', $queued->reference_type);
        $this->assertSame('Restock', $queued->notes);
        // Aimed at THIS branch, and with no counterparty: nobody else is involved
        // in a recount somebody does in their own shop.
        $this->assertSame('00000000-0000-0000-0000-0000000b4a4c', $queued->branch_id);
        $this->assertNull($queued->counterparty_branch_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'inventory.stock.queued',
            'auditable_id' => $variant->id,
        ]);
    }

    public function test_confirming_it_moves_the_stock_and_writes_the_ledger_row(): void
    {
        $this->fakeBusinessQueue();
        $variant = Variant::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('stock.adjust', $variant->id), ['mode' => 'add', 'value' => 12, 'notes' => 'Restock'])
            ->assertSessionHasNoErrors();

        $this->approveEverythingPending($admin);

        $this->assertSame(12, $this->stockOf($variant));
        $this->assertDatabaseHas('inventory_ledger', [
            'variant_id' => $variant->id,
            'quantity' => 12,
            'type' => 'stock_adjustment',
            'reference_type' => 'manual_adjustment',
            // Labelled, because a movement's notes can hold several people's
            // words and the row has to say whose are whose with no connection.
            'notes' => 'Requested: Restock',
        ]);
    }

    public function test_adding_stock_does_not_require_notes(): void
    {
        $this->fakeBusinessQueue();
        $variant = Variant::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('stock.adjust', $variant->id), ['mode' => 'add', 'value' => 8])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->approveEverythingPending($admin);

        $this->assertSame(8, $this->stockOf($variant));
        $this->assertDatabaseHas('inventory_ledger', ['variant_id' => $variant->id, 'quantity' => 8, 'notes' => null]);
    }

    public function test_set_mode_records_the_delta_between_the_count_and_current_stock(): void
    {
        $remote = $this->fakeBusinessQueue();
        $variant = Variant::factory()->create();
        $admin = User::factory()->admin()->create();

        // Seed to 40, then recount to 37 → delta of -3.
        app(StockLedger::class)->record($variant, 40, 'stock_adjustment', null, 'manual_adjustment', null, $admin);

        $this->actingAs($admin)
            ->post(route('stock.adjust', $variant->id), ['mode' => 'set', 'value' => 37, 'notes' => 'Recount'])
            ->assertSessionHasNoErrors();

        // The DELTA is what is stored, not the count — which is why a "set to 37"
        // that waits while five sell will not produce 37 when confirmed. That is
        // deliberate; the approver partially approves what fits.
        $this->assertSame(-3, (int) $remote->table('inventory_update_queue')->value('quantity'));

        $this->approveEverythingPending($admin);

        $this->assertSame(37, $this->stockOf($variant));
        $this->assertDatabaseHas('inventory_ledger', ['variant_id' => $variant->id, 'quantity' => -3]);
    }

    public function test_notes_are_required_when_setting_a_count(): void
    {
        $remote = $this->fakeBusinessQueue();
        $variant = Variant::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock.adjust', $variant->id), ['mode' => 'set', 'value' => 5])
            ->assertSessionHasErrors('notes');

        $this->assertSame(0, InventoryLedger::count());
        $this->assertSame(0, $remote->table('inventory_update_queue')->count());
    }

    public function test_notes_are_required_when_removing_stock(): void
    {
        $remote = $this->fakeBusinessQueue();
        $variant = Variant::factory()->create();
        $admin = User::factory()->admin()->create();

        app(StockLedger::class)->record($variant, 10, 'stock_adjustment', null, 'manual_adjustment', null, $admin);

        $this->actingAs($admin)
            ->post(route('stock.adjust', $variant->id), ['mode' => 'remove', 'value' => 4])
            ->assertSessionHasErrors('notes');

        $this->assertSame(0, $remote->table('inventory_update_queue')->count());
        $this->assertSame(10, $this->stockOf($variant));
    }

    public function test_asking_to_remove_more_than_there_is_is_allowed_and_refused_on_confirmation(): void
    {
        $remote = $this->fakeBusinessQueue();
        $variant = Variant::factory()->create();
        $admin = User::factory()->admin()->create();

        app(StockLedger::class)->record($variant, 3, 'stock_adjustment', null, 'manual_adjustment', null, $admin);

        // Accepted as a REQUEST. Whether stock allows it is a question about the
        // moment it is confirmed, not the moment it is asked — and between the
        // two, a delivery may arrive.
        $this->actingAs($admin)
            ->post(route('stock.adjust', $variant->id), ['mode' => 'remove', 'value' => 10, 'notes' => 'oops'])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, $this->stockOf($variant));
        $this->assertSame(1, $remote->table('inventory_update_queue')->count());
    }

    public function test_adding_or_removing_nothing_is_rejected(): void
    {
        $remote = $this->fakeBusinessQueue();
        $variant = Variant::factory()->create();
        $admin = User::factory()->admin()->create();

        // Caught by the request's `min:1` rather than by the controller: add and
        // remove are quantities of MOVEMENT, and there is no such thing as moving
        // none. A negative is refused by the same rule — the direction is the
        // mode, not the sign, and nothing here reinterprets a minus.
        foreach ([['add', 0], ['remove', 0], ['add', -5], ['remove', -5]] as [$mode, $value]) {
            $this->actingAs($admin)
                ->post(route('stock.adjust', $variant->id), ['mode' => $mode, 'value' => $value, 'notes' => 'nothing'])
                ->assertSessionHasErrors('value');
        }

        $this->assertSame(0, $remote->table('inventory_update_queue')->count());
    }

    public function test_recounting_a_shelf_to_what_is_already_on_it_is_rejected(): void
    {
        $remote = $this->fakeBusinessQueue();
        $variant = Variant::factory()->create();
        $admin = User::factory()->admin()->create();

        app(StockLedger::class)->record($variant, 9, 'stock_adjustment', null, 'manual_adjustment', null, $admin);

        // The one remaining way to ask for a zero movement, and the only case the
        // controller's own guard still catches: `set` is an absolute count, so 0
        // is a legitimate entry and the no-op is only visible after subtracting.
        $this->actingAs($admin)
            ->post(route('stock.adjust', $variant->id), ['mode' => 'set', 'value' => 9, 'notes' => 'Recount'])
            ->assertSessionHasErrors('value');

        $this->assertSame(0, $remote->table('inventory_update_queue')->count());
        $this->assertSame(9, $this->stockOf($variant));
    }

    public function test_cashier_cannot_ask_for_an_adjustment(): void
    {
        $remote = $this->fakeBusinessQueue();
        $variant = Variant::factory()->create();

        // Deciding what SHOULD move is still Admin+. Reporting what DID is not —
        // see AdjustmentResolutionTest.
        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('stock.adjust', $variant->id), ['mode' => 'add', 'value' => 5, 'notes' => 'nope'])
            ->assertForbidden();

        $this->assertSame(0, $remote->table('inventory_update_queue')->count());
        $this->assertSame(0, InventoryLedger::count());
    }

    public function test_inventory_index_exposes_current_stock_on_variants(): void
    {
        $variant = Variant::factory()->create();
        app(StockLedger::class)->record($variant, 7, 'stock_adjustment', null, 'manual_adjustment', null, User::factory()->admin()->create());

        $this->actingAs(User::factory()->owner()->create())
            ->get(route('inventory'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('variants.data.0.current_stock', 7));
    }
}
