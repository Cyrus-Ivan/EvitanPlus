<?php

namespace Tests\Feature\Inventory;

use App\Models\Instance;
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

    /**
     * The branch scope (M11.8), which follows `LoginHistoryController` exactly.
     *
     * The wider scopes read Postgres and no test device holds a sealed
     * credential, so what is provable here is the SNAPPING — who gets which
     * scope, and that a read which cannot happen falls back and says so. The
     * arithmetic and the cross-branch read are
     * tests/Integration/InventoryQueueTest.php, against two real branches.
     */
    public function test_the_default_scope_is_local_and_names_this_branch_on_every_row()
    {
        $variant = Variant::factory()->create();
        app(StockLedger::class)->record($variant, 5, 'stock_adjustment', null, 'manual_adjustment', null, User::factory()->admin()->create());

        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('stock-history.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'local')
                // Null, not false: nothing cross-branch was asked for, so there
                // is nothing to report as unavailable.
                ->where('consolidatedOnline', null)
                // From `instance`, because the local table has no branch_id —
                // every row in it is this device's by construction.
                ->where('entries.data.0.branch', Instance::current()?->branch_code)
            );
    }

    public function test_a_cashier_cannot_widen_the_scope()
    {
        // Snapped back rather than refused: the local history is theirs by right
        // and sits on the same route.
        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('stock-history.index', ['scope' => 'all']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('scope', 'local')->where('canViewAll', false));
    }

    public function test_this_branchs_own_id_is_the_local_scope()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('stock-history.index', ['scope' => Instance::current()->branch_id]))
            ->assertOk()
            // `local` answers the same question offline AND includes rows this
            // device has not pushed yet, so the id is redundant rather than a
            // second answer. A value the picker does not offer is one it cannot
            // display.
            ->assertInertia(fn ($page) => $page->where('scope', 'local'));
    }

    public function test_a_wider_scope_that_cannot_be_read_falls_back_and_says_so()
    {
        $variant = Variant::factory()->create();
        app(StockLedger::class)->record($variant, 5, 'stock_adjustment', null, 'manual_adjustment', null, User::factory()->admin()->create());

        // No credential is sealed on a test device, so the online read fails.
        // Presenting one branch's movements under an "All branches" heading is
        // the one outcome worse than an error, so the scope comes back as what
        // was actually read.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('stock-history.index', ['scope' => 'all']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'local')
                ->where('consolidatedOnline', false)
                ->has('entries.data', 1)
            );
    }

    public function test_the_request_behind_a_movement_is_null_when_it_cannot_be_read()
    {
        $variant = Variant::factory()->create();
        $entry = app(StockLedger::class)
            ->record($variant, 5, 'stock_adjustment', null, 'manual_adjustment', null, User::factory()->admin()->create());

        // Offline, and that is not an error: the row's own notes already carry
        // the sentence version, which is why they are written that way.
        $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('stock-history.request', $entry->id))
            ->assertOk()
            ->assertExactJson(['request' => null]);
    }

    public function test_a_ledger_id_that_is_not_a_uuid_is_refused_without_a_read()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('stock-history.request', 'not-a-uuid'))
            ->assertOk()
            ->assertExactJson(['request' => null]);
    }
}
