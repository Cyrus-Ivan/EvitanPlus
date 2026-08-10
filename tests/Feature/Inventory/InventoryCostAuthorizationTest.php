<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryCostAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array<string, mixed>>  $variants
     * @return array<string, mixed>
     */
    private function payload(array $variants): array
    {
        return [
            'name' => 'Widget',
            'unit' => 'pc',
            'category_id' => null,
            'description' => null,
            'variants' => $variants,
        ];
    }

    public function test_cost_is_present_for_owner_and_hidden_for_others_on_index()
    {
        $item = Item::factory()->create();
        Variant::factory()->for($item)->create(['cost' => '4.00']);

        $this->actingAs(User::factory()->owner()->create())
            ->get(route('items.index'))
            ->assertInertia(fn ($page) => $page->where('items.data.0.variants.0.cost', '4.00'));

        foreach (['admin', 'cashier'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create())
                ->get(route('items.index'))
                ->assertInertia(fn ($page) => $page->missing('items.data.0.variants.0.cost'));
        }
    }

    public function test_owner_can_set_cost()
    {
        $this->actingAs(User::factory()->owner()->create())
            ->post(route('items.store'), $this->payload([
                ['name' => 'Default', 'sku' => 'C-1', 'barcode' => null, 'selling_price' => '10.00', 'low_stock_threshold' => 0, 'cost' => '3.50'],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('3.50', Variant::first()->cost);
    }

    public function test_admin_cannot_set_cost()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('items.store'), $this->payload([
                ['name' => 'Default', 'sku' => 'C-2', 'barcode' => null, 'selling_price' => '10.00', 'low_stock_threshold' => 0, 'cost' => '3.50'],
            ]))
            ->assertSessionHasNoErrors();

        // Cost was stripped before persistence.
        $this->assertNull(Variant::first()->cost);
    }

    public function test_cashier_cannot_create_items()
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('items.store'), $this->payload([
                ['name' => 'Default', 'sku' => 'C-3', 'barcode' => null, 'selling_price' => '10.00', 'low_stock_threshold' => 0],
            ]))
            ->assertForbidden();

        $this->assertSame(0, Item::count());
    }

    public function test_cashier_cannot_archive_items()
    {
        $item = Item::factory()->create();

        $this->actingAs(User::factory()->cashier()->create())
            ->patch(route('items.archive', $item->id))
            ->assertForbidden();

        $this->assertNull($item->fresh()->archived_at);
    }

    public function test_cashier_can_view_inventory()
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('items.index'))
            ->assertOk();
    }

    public function test_guests_are_redirected_to_login()
    {
        // A user must exist — on a fresh install the setup wizard answers.
        User::factory()->cashier()->create();

        $this->get(route('items.index'))->assertRedirect(route('login'));
    }
}
