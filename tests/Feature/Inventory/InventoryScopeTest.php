<?php

namespace Tests\Feature\Inventory;

use App\Models\Instance;
use App\Models\Item;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The `scope` parameter on the inventory list (M12) — who may see another
 * branch's stock, and what a device that cannot reach it shows.
 *
 * The arithmetic (that the figure on screen is the named branch's rather than
 * this device's) is provable only against real Postgres:
 * `tests/Integration/ConsolidatedStockTest.php`.
 *
 * What IS provable here is the boundary and the fallback — and, most of all,
 * that the fallback is INDISTINGUISHABLE from an ordinary local render. The
 * whole reason the catalog query stayed local is that there is no
 * half-consolidated state to design a screen for, and a `scoped_stock` key
 * surviving a failed read would be exactly that.
 */
class InventoryScopeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Any branch that is not this one — enough to send the request down the
     * cross-branch path, which is all these tests need, because with no sealed
     * credential the read cannot open a connection and fails before the id is
     * ever looked up.
     */
    private const OTHER_BRANCH = '00000000-0000-0000-0000-00000000e15e';

    public function test_the_local_shelf_is_the_default_and_carries_no_scoped_figures(): void
    {
        $this->seedProduct();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('inventory'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'local')
                ->where('canViewAll', true)
                ->where('consolidatedOnline', null)
                ->where('items.data.0.variants.0.current_stock', 0)
                ->missing('items.data.0.variants.0.scoped_stock')
            );
    }

    /**
     * Snapped back, not refused: a Cashier's own shelf is the thing this screen
     * is for, and it is on the same route.
     */
    public function test_a_cashier_asking_for_another_branch_is_snapped_back(): void
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('inventory', ['scope' => self::OTHER_BRANCH]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'local')
                ->where('canViewAll', false)
                ->where('branches', [])
                ->where('consolidatedOnline', null)
            );
    }

    /**
     * This screen has no `all`, so the strongest role gets the same `local` an
     * unparseable scope gets — and gets it without an online read being attempted
     * at all, which is what `consolidatedOnline: null` says.
     *
     * The picker stopped offering the option; this is what stops it coming back
     * by URL. See `ItemController`'s class docblock for why it went.
     */
    public function test_the_all_scope_does_not_exist_on_this_screen(): void
    {
        $owner = User::factory()->owner()->create();

        foreach (['all', 'junk'] as $scope) {
            $this->actingAs($owner)
                ->get(route('inventory', ['scope' => $scope]))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('scope', 'local')
                    ->where('canViewAll', true)
                    ->where('consolidatedOnline', null)
                );
        }
    }

    public function test_this_branchs_own_id_is_the_local_scope(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('inventory', ['scope' => Instance::current()->branch_id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'local')
                // `local` is the better read of the same branch: it includes
                // movements this device has not pushed yet.
                ->where('consolidatedOnline', null)
            );
    }

    public function test_a_failed_cross_branch_read_leaves_the_page_exactly_as_it_was(): void
    {
        $this->seedProduct();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('inventory', ['scope' => self::OTHER_BRANCH]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'local')
                ->where('consolidatedOnline', false)
                // The point of the whole split: nothing was attached, so the
                // Stock column falls back to this device's own figure and the
                // scoped key is not merely null but absent.
                ->where('items.data.0.variants.0.current_stock', 0)
                ->missing('items.data.0.variants.0.scoped_stock')
            );
    }

    /**
     * `online` answers "can this screen WRITE" (Add product, the stock dialogs);
     * `consolidatedOnline` answers "did this one read arrive". Folding them
     * together would grey out the edit buttons because a roster read failed.
     */
    public function test_the_write_probe_and_the_read_outcome_are_separate_props(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('inventory', ['scope' => self::OTHER_BRANCH]))
            ->assertInertia(fn ($page) => $page
                ->where('online', true)
                ->where('consolidatedOnline', false)
            );
    }

    private function seedProduct(): void
    {
        $item = Item::factory()->create();
        Variant::factory()->for($item)->create();
    }
}
