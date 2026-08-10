<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Item;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array<string, mixed>>  $variants
     * @return array<string, mixed>
     */
    private function itemPayload(array $overrides = [], array $variants = []): array
    {
        return array_merge([
            'name' => 'Widget',
            'unit' => 'pc',
            'category_id' => null,
            'description' => 'A widget',
            'variants' => $variants ?: [
                [
                    'name' => 'Default',
                    'sku' => 'WID-001',
                    'barcode' => null,
                    'selling_price' => '10.00',
                    'low_stock_threshold' => 5,
                    'cost' => '4.00',
                ],
            ],
        ], $overrides);
    }

    public function test_owner_can_create_an_item_with_a_variant()
    {
        $this->actingAs(User::factory()->owner()->create())
            ->post(route('items.store'), $this->itemPayload())
            ->assertRedirect(route('items.index'))
            ->assertSessionHasNoErrors();

        $item = Item::first();
        $this->assertNotNull($item);
        $this->assertSame('Widget', $item->name);
        $this->assertSame(1, $item->variants()->count());
        $this->assertSame('WID-001', $item->variants()->first()->sku);
    }

    public function test_owner_can_create_a_no_variant_item()
    {
        $this->actingAs(User::factory()->owner()->create())
            ->post(route('items.store'), $this->itemPayload([], [
                [
                    'name' => null,
                    'sku' => 'SOLO-1',
                    'barcode' => null,
                    'selling_price' => '10.00',
                    'low_stock_threshold' => 0,
                    'cost' => '4.00',
                ],
            ]))
            ->assertRedirect(route('items.index'))
            ->assertSessionHasNoErrors();

        $variant = Item::first()->variants()->sole();
        $this->assertNull($variant->name);
        $this->assertSame('SOLO-1', $variant->sku);
    }

    public function test_blank_variant_name_persists_as_null()
    {
        $this->actingAs(User::factory()->owner()->create())
            ->post(route('items.store'), $this->itemPayload([], [
                [
                    'name' => '',
                    'sku' => 'SOLO-2',
                    'barcode' => null,
                    'selling_price' => '10.00',
                    'low_stock_threshold' => 0,
                    'cost' => null,
                ],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertNull(Item::first()->variants()->sole()->name);
    }

    public function test_editing_keeps_a_no_variant_row_matched_not_recreated()
    {
        $item = Item::factory()->create(['name' => 'Widget']);
        $variant = Variant::factory()->for($item)->create(['name' => null, 'sku' => 'SOLO-3']);

        $this->actingAs(User::factory()->owner()->create())
            ->patch(route('items.update', $item->id), $this->itemPayload([], [
                [
                    'id' => $variant->id,
                    'name' => null,
                    'sku' => 'SOLO-3',
                    'barcode' => null,
                    'selling_price' => '15.00',
                    'low_stock_threshold' => 0,
                    'cost' => null,
                ],
            ]))
            ->assertSessionHasNoErrors();

        // Same row updated in place — never archived+recreated.
        $this->assertSame(1, $item->variants()->count());
        $variant->refresh();
        $this->assertNull($variant->archived_at);
        $this->assertNull($variant->name);
        $this->assertSame('15.00', $variant->selling_price);
    }

    public function test_duplicate_item_name_is_rejected_case_insensitively()
    {
        Item::factory()->create(['name' => 'Coca-Cola']);

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('items.store'), $this->itemPayload(['name' => 'coca-cola']))
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Item::count());
    }

    public function test_item_name_uniqueness_spans_archived_items()
    {
        Item::factory()->archived()->create(['name' => 'Retired Product']);

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('items.store'), $this->itemPayload(['name' => 'Retired Product']))
            ->assertSessionHasErrors('name');
    }

    public function test_item_name_is_trimmed_before_the_uniqueness_check()
    {
        Item::factory()->create(['name' => 'Coca-Cola']);

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('items.store'), $this->itemPayload(['name' => '  Coca-Cola  ']))
            ->assertSessionHasErrors('name');
    }

    public function test_updating_an_item_keeps_its_own_name()
    {
        $item = Item::factory()->create(['name' => 'Widget']);
        Variant::factory()->for($item)->create();

        $this->actingAs(User::factory()->owner()->create())
            ->patch(route('items.update', $item->id), $this->itemPayload(['name' => 'Widget']))
            ->assertSessionHasNoErrors();
    }

    public function test_renaming_an_item_is_rejected()
    {
        $item = Item::factory()->create(['name' => 'Original']);
        Variant::factory()->for($item)->create();

        $this->actingAs(User::factory()->owner()->create())
            ->patch(route('items.update', $item->id), $this->itemPayload(['name' => 'A Different Name']))
            ->assertSessionHasErrors('name');

        $this->assertSame('Original', $item->fresh()->name);
    }

    public function test_resubmitting_the_items_own_name_with_different_case_is_accepted_and_name_unchanged()
    {
        $item = Item::factory()->create(['name' => 'Widget']);
        Variant::factory()->for($item)->create();

        $this->actingAs(User::factory()->owner()->create())
            ->patch(route('items.update', $item->id), $this->itemPayload(['name' => 'WIDGET']))
            ->assertSessionHasNoErrors();

        // Immutable means even casing never drifts.
        $this->assertSame('Widget', $item->fresh()->name);
    }

    public function test_variant_sku_is_required()
    {
        $this->actingAs(User::factory()->owner()->create())
            ->post(route('items.store'), $this->itemPayload([], [
                ['name' => 'Default', 'sku' => '', 'barcode' => null, 'selling_price' => '10.00', 'low_stock_threshold' => 0, 'cost' => null],
            ]))
            ->assertSessionHasErrors('variants.0.sku');

        $this->assertSame(0, Item::count());
    }

    public function test_variant_sku_is_stored_uppercase()
    {
        $this->actingAs(User::factory()->owner()->create())
            ->post(route('items.store'), $this->itemPayload([], [
                ['name' => 'Default', 'sku' => 'wid-001', 'barcode' => null, 'selling_price' => '10.00', 'low_stock_threshold' => 0, 'cost' => null],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('WID-001', Item::first()->variants()->sole()->sku);
    }

    public function test_create_form_renders()
    {
        Category::factory()->create();

        $this->actingAs(User::factory()->owner()->create())
            ->get(route('items.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('categories', 1));
    }

    public function test_edit_form_renders_with_active_variants()
    {
        $item = Item::factory()->create();
        Variant::factory()->for($item)->create();
        Variant::factory()->for($item)->archived()->create();

        $this->actingAs(User::factory()->owner()->create())
            ->get(route('items.edit', $item->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('item.id', $item->id)
                ->has('item.variants', 1) // archived variant excluded
            );
    }

    public function test_admin_can_create_an_item()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('items.store'), $this->itemPayload())
            ->assertRedirect(route('items.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Item::count());
    }

    public function test_item_requires_at_least_one_variant()
    {
        $this->actingAs(User::factory()->owner()->create())
            ->post(route('items.store'), $this->itemPayload(['variants' => []]))
            ->assertSessionHasErrors('variants');

        $this->assertSame(0, Item::count());
    }

    public function test_editing_updates_a_matched_variant()
    {
        $item = Item::factory()->create(['name' => 'Widget']);
        $variant = Variant::factory()->for($item)->create(['selling_price' => '10.00']);

        $this->actingAs(User::factory()->owner()->create())
            ->patch(route('items.update', $item->id), $this->itemPayload([], [
                [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'selling_price' => '12.50',
                    'low_stock_threshold' => 2,
                    'cost' => '4.00',
                ],
            ]))
            ->assertRedirect(route('items.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('12.50', $variant->fresh()->selling_price);
    }

    public function test_omitting_a_variant_archives_it_and_adds_new_one()
    {
        $item = Item::factory()->create(['name' => 'Widget']);
        $keep = Variant::factory()->for($item)->create(['sku' => 'KEEP']);
        $drop = Variant::factory()->for($item)->create(['sku' => 'DROP']);

        $this->actingAs(User::factory()->owner()->create())
            ->patch(route('items.update', $item->id), $this->itemPayload([], [
                [
                    'id' => $keep->id,
                    'name' => $keep->name,
                    'sku' => 'KEEP',
                    'barcode' => null,
                    'selling_price' => '10.00',
                    'low_stock_threshold' => 0,
                    'cost' => null,
                ],
                [
                    'name' => 'Brand new',
                    'sku' => 'NEW-001',
                    'barcode' => null,
                    'selling_price' => '20.00',
                    'low_stock_threshold' => 0,
                    'cost' => null,
                ],
            ]))
            ->assertSessionHasNoErrors();

        // Dropped variant is archived, never deleted.
        $this->assertNotNull($drop->fresh()->archived_at);
        $this->assertNull($keep->fresh()->archived_at);
        $this->assertDatabaseHas('variants', ['sku' => 'NEW-001', 'item_id' => $item->id, 'archived_at' => null]);
        $this->assertSame(2, $item->variants()->active()->count());
    }

    public function test_multi_variant_item_rejects_a_blank_variant_name()
    {
        $this->actingAs(User::factory()->owner()->create())
            ->post(route('items.store'), $this->itemPayload([], [
                ['name' => 'Small', 'sku' => 'S-1', 'barcode' => null, 'selling_price' => '10.00', 'low_stock_threshold' => 0, 'cost' => null],
                ['name' => null, 'sku' => 'S-2', 'barcode' => null, 'selling_price' => '10.00', 'low_stock_threshold' => 0, 'cost' => null],
            ]))
            ->assertSessionHasErrors('variants.1.name');

        $this->assertSame(0, Item::count());
    }

    public function test_adding_a_variant_to_a_no_variant_item_requires_naming_the_existing_one()
    {
        $item = Item::factory()->create(['name' => 'Bottled Water']);
        $existing = Variant::factory()->for($item)->create(['name' => null, 'sku' => 'WATER-1']);

        $this->actingAs(User::factory()->owner()->create())
            ->patch(route('items.update', $item->id), $this->itemPayload(['name' => 'Bottled Water'], [
                [
                    'id' => $existing->id,
                    'name' => null, // left unnamed while a second variant is added
                    'sku' => 'WATER-1',
                    'barcode' => null,
                    'selling_price' => '10.00',
                    'low_stock_threshold' => 0,
                    'cost' => null,
                ],
                [
                    'name' => '500ml',
                    'sku' => 'WATER-500',
                    'barcode' => null,
                    'selling_price' => '12.00',
                    'low_stock_threshold' => 0,
                    'cost' => null,
                ],
            ]))
            ->assertSessionHasErrors('variants.0.name');

        $this->assertSame(1, $item->variants()->active()->count());
    }

    public function test_naming_the_existing_variant_and_adding_another_succeeds()
    {
        $item = Item::factory()->create(['name' => 'Bottled Water']);
        $existing = Variant::factory()->for($item)->create(['name' => null, 'sku' => 'WATER-1']);

        $this->actingAs(User::factory()->owner()->create())
            ->patch(route('items.update', $item->id), $this->itemPayload(['name' => 'Bottled Water'], [
                [
                    'id' => $existing->id,
                    'name' => '330ml',
                    'sku' => 'WATER-1',
                    'barcode' => null,
                    'selling_price' => '10.00',
                    'low_stock_threshold' => 0,
                    'cost' => null,
                ],
                [
                    'name' => '500ml',
                    'sku' => 'WATER-500',
                    'barcode' => null,
                    'selling_price' => '12.00',
                    'low_stock_threshold' => 0,
                    'cost' => null,
                ],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $item->variants()->active()->count());
        $this->assertSame('330ml', $existing->fresh()->name);
    }

    public function test_item_can_be_archived_and_restored()
    {
        $item = Item::factory()->create();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->patch(route('items.archive', $item->id))->assertSessionHasNoErrors();
        $this->assertNotNull($item->fresh()->archived_at);

        $this->actingAs($owner)->patch(route('items.restore', $item->id))->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->archived_at);
    }

    public function test_index_hides_archived_by_default_and_shows_them_when_filtered()
    {
        Item::factory()->create(['name' => 'Active Item']);
        Item::factory()->archived()->create(['name' => 'Archived Item']);

        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get(route('items.index'))
            ->assertInertia(fn ($page) => $page->has('items.data', 1)->where('items.data.0.name', 'Active Item'));

        $this->actingAs($owner)->get(route('items.index', ['status' => 'archived']))
            ->assertInertia(fn ($page) => $page->has('items.data', 1)->where('items.data.0.name', 'Archived Item'));

        $this->actingAs($owner)->get(route('items.index', ['status' => 'all']))
            ->assertInertia(fn ($page) => $page->has('items.data', 2));
    }

    public function test_search_matches_name_and_variant_sku()
    {
        $apple = Item::factory()->create(['name' => 'Apple Juice']);
        Variant::factory()->for($apple)->create(['sku' => 'APL-1']);
        $bread = Item::factory()->create(['name' => 'Whole Bread']);
        Variant::factory()->for($bread)->create(['sku' => 'BRD-9']);

        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get(route('items.index', ['search' => 'Apple']))
            ->assertInertia(fn ($page) => $page->has('items.data', 1)->where('items.data.0.name', 'Apple Juice'));

        $this->actingAs($owner)->get(route('items.index', ['search' => 'BRD-9']))
            ->assertInertia(fn ($page) => $page->has('items.data', 1)->where('items.data.0.name', 'Whole Bread'));
    }

    public function test_index_can_filter_by_category()
    {
        $drinks = Category::factory()->create(['name' => 'Drinks']);
        Item::factory()->create(['name' => 'Cola', 'category_id' => $drinks->id]);
        Item::factory()->create(['name' => 'Chips']);

        $this->actingAs(User::factory()->owner()->create())
            ->get(route('items.index', ['category' => $drinks->id]))
            ->assertInertia(fn ($page) => $page->has('items.data', 1)->where('items.data.0.name', 'Cola'));
    }

    public function test_index_paginates()
    {
        Item::factory()->count(20)->create();

        $this->actingAs(User::factory()->owner()->create())
            ->get(route('items.index'))
            ->assertInertia(fn ($page) => $page->has('items.data', 15)->where('items.total', 20)->where('items.last_page', 2));
    }
}
