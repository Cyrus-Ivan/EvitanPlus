<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_view_stock_history()
    {
        $variant = Variant::factory()->create();
        app(StockLedger::class)->record($variant, 5, 'stock_adjustment', null, 'manual_adjustment', null, User::factory()->admin()->create());

        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('stock-history.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('entries.data', 1));
    }

    public function test_history_can_be_scoped_to_a_single_variant()
    {
        $admin = User::factory()->admin()->create();
        $ledger = app(StockLedger::class);

        $target = Variant::factory()->create();
        $other = Variant::factory()->create();
        $ledger->record($target, 5, 'stock_adjustment', null, 'manual_adjustment', null, $admin);
        $ledger->record($other, 9, 'stock_adjustment', null, 'manual_adjustment', null, $admin);

        $this->actingAs($admin)
            ->get(route('stock-history.index', ['variant' => $target->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.variant.id', $target->id)
            );
    }

    public function test_history_can_be_filtered_by_movement()
    {
        $admin = User::factory()->admin()->create();
        $ledger = app(StockLedger::class);
        $variant = Variant::factory()->create();

        $ledger->record($variant, 10, 'stock_adjustment', null, 'manual_adjustment', null, $admin);
        $ledger->record($variant, -2, 'sale', 'sale', 'transaction', null, $admin);

        $this->actingAs($admin)
            ->get(route('stock-history.index', ['movement' => 'sale']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.type', 'sale')
            );
    }

    /**
     * The case the old `type` filter could not express at all: every one of these
     * is a `stock_adjustment`, and asking for one used to return all three — on
     * the day after a CSV import, effectively the whole page.
     */
    public function test_adjustments_are_filtered_apart_by_how_they_were_asked_for()
    {
        $admin = User::factory()->admin()->create();
        $ledger = app(StockLedger::class);
        $variant = Variant::factory()->create();

        $ledger->record($variant, 10, 'stock_adjustment', null, 'manual_adjustment', null, $admin);
        $ledger->record($variant, 7, 'stock_adjustment', null, 'csv_import', null, $admin);
        $ledger->record($variant, -3, 'stock_adjustment', null, 'transfer', null, $admin, counterpartyBranchCode: 'CEB');

        foreach (['manual_adjustment', 'csv_import', 'transfer'] as $movement) {
            $this->actingAs($admin)
                ->get(route('stock-history.index', ['movement' => $movement]))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->has('entries.data', 1)
                    ->where('entries.data.0.reference_type', $movement)
                );
        }
    }

    /**
     * Both carry `reference_type = 'return'`, so only `sale_type` tells them
     * apart — and a defect that restored stock would be a real inventory bug.
     */
    public function test_returns_and_defects_are_filtered_apart()
    {
        $admin = User::factory()->admin()->create();
        $ledger = app(StockLedger::class);
        $variant = Variant::factory()->create();

        $ledger->record($variant, 4, 'sale', 'return', 'return', null, $admin);
        $ledger->record($variant, 0, 'sale', 'defect', 'return', null, $admin);

        $this->actingAs($admin)
            ->get(route('stock-history.index', ['movement' => 'defect']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.sale_type', 'defect')
            );
    }

    /**
     * Including `?type=stock_adjustment`, the vocabulary this filter spoke before
     * movement kinds: an unrecognised filter shows everything rather than nothing,
     * which is what a stale bookmark should do.
     */
    public function test_an_unknown_movement_filters_nothing()
    {
        $admin = User::factory()->admin()->create();
        $ledger = app(StockLedger::class);
        $variant = Variant::factory()->create();

        $ledger->record($variant, 10, 'stock_adjustment', null, 'manual_adjustment', null, $admin);
        $ledger->record($variant, -2, 'sale', 'sale', 'transaction', null, $admin);

        $this->actingAs($admin)
            ->get(route('stock-history.index', ['movement' => 'stock_adjustment']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 2)
                ->where('filters.movement', null)
            );
    }
}
