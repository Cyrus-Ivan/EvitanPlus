<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryLedger;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issuing a stock adjustment against ANOTHER branch (M8), from this side.
 *
 * What can be proved without a Postgres: who may do it, what is refused, and —
 * the one that matters most — that issuing one changes NOTHING on this device.
 * A queued adjustment is a request aimed elsewhere; if it moved local stock or
 * wrote a local ledger row, the branch it was aimed at would end up applying it
 * a second time when the entry came back down.
 *
 * The row landing in `inventory_update_queue` for real, under the branch role's
 * new INSERT grant, is tests/Integration/InventoryQueueTest.php.
 */
class StockUpdateQueueTest extends TestCase
{
    use RefreshDatabase;

    private const TARGET = '00000000-0000-0000-0000-00000000e15e';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Variant $variant, array $overrides = []): array
    {
        return array_merge([
            'branch_id' => self::TARGET,
            'variant_id' => $variant->id,
            // Positive, with the direction in `mode`. The row this becomes still
            // stores −5; applying the sign is InventoryQueueController::store()'s
            // job, not the caller's.
            'mode' => 'remove',
            'quantity' => 5,
            'notes' => 'Written off at the other branch',
        ], $overrides);
    }

    public function test_a_cashier_may_see_the_list_but_not_issue(): void
    {
        $variant = Variant::factory()->create();
        $cashier = User::factory()->cashier()->create();

        // The two halves part company at schema 21. Reading and ANSWERING the
        // list is Cashier-level, because whether a delivery arrived is known by
        // whoever is in the shop (AdjustmentResolutionTest). Deciding what SHOULD
        // move — especially at another branch — is still Admin+.
        $this->actingAs($cashier)->get(route('stock-updates.index'))->assertOk();
        $this->actingAs($cashier)->post(route('stock-updates.store'), $this->payload($variant))->assertForbidden();
    }

    public function test_an_admin_can_read_the_pending_list(): void
    {
        // No credential is sealed on a test device, so the online read cannot
        // happen — and the page says so rather than failing. Everything it
        // renders lives in the business database, so there is genuinely nothing
        // to show, and `online: false` is what makes the page say that instead
        // of claiming the queue is empty.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('stock-updates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('inventory/stock-updates', false)
                ->where('requests', [])
                ->where('branches', [])
                ->where('online', false)
                ->where('filters.scope', 'local')
            );
    }

    public function test_issuing_leaves_this_device_untouched(): void
    {
        $variant = Variant::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock-updates.store'), $this->payload($variant))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // The point of the whole phase: nothing local moved. The adjustment is
        // for another branch, and only that branch may turn it into a ledger
        // entry — on its own side, against its own reconciled stock.
        $this->assertSame(0, InventoryLedger::query()->count());
        $this->assertDatabaseMissing('stock_snapshots', ['variant_id' => $variant->id, 'quantity' => -5]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'inventory.stock.queued',
            'auditable_id' => $variant->id,
        ]);
    }

    public function test_a_zero_change_is_not_an_adjustment(): void
    {
        $variant = Variant::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('stock-updates.store'), $this->payload($variant, ['quantity' => 0]))
            ->assertSessionHasErrors('quantity');

        // And a negative is refused too, rather than read as a removal. The
        // operator picks a direction; a minus sign typed into the quantity is a
        // mistake, and one that used to mean the opposite of what it looked like
        // when combined with `mode`.
        $this->actingAs($admin)
            ->post(route('stock-updates.store'), $this->payload($variant, ['quantity' => -5]))
            ->assertSessionHasErrors('quantity');
    }

    public function test_the_direction_is_the_mode_and_it_is_required(): void
    {
        $variant = Variant::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('stock-updates.store'), $this->payload($variant, ['mode' => null]))
            ->assertSessionHasErrors('mode');

        // There is no "set to count" for another branch: the only on-hand figure
        // this device has for it is a baseline missing its unsynced sales.
        $this->actingAs($admin)
            ->post(route('stock-updates.store'), $this->payload($variant, ['mode' => 'set']))
            ->assertSessionHasErrors('mode');
    }

    public function test_removing_stock_needs_an_explanation(): void
    {
        $variant = Variant::factory()->create();
        $admin = User::factory()->admin()->create();

        // The ledger entry this may become has to say why, exactly as a local
        // removal does — and the person reading it is at a different branch.
        $this->actingAs($admin)
            ->post(route('stock-updates.store'), $this->payload($variant, ['notes' => null]))
            ->assertSessionHasErrors('notes');

        // Adding stock needs no such justification.
        $this->actingAs($admin)
            ->post(route('stock-updates.store'), $this->payload($variant, ['mode' => 'add', 'notes' => null]))
            ->assertSessionHasNoErrors();
    }

    public function test_the_target_branch_and_variant_must_be_real_shaped(): void
    {
        $variant = Variant::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('stock-updates.store'), $this->payload($variant, ['branch_id' => 'not-a-branch']))
            ->assertSessionHasErrors('branch_id');

        // The variant IS checked against the local mirror: the catalog is shared
        // and every branch holds all of it, so a variant this device has never
        // heard of is a mistake rather than a remote row.
        $this->actingAs($admin)
            ->post(route('stock-updates.store'), $this->payload($variant, [
                'variant_id' => '00000000-0000-0000-0000-0000000000ff',
            ]))
            ->assertSessionHasErrors('variant_id');
    }
}
