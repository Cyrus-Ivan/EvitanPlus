<?php

namespace Tests\Feature\Inventory;

use App\Models\Instance;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use App\Support\Search\LikeQuery;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Inventory\Concerns\FakesThePendingQueue;
use Tests\TestCase;

class StockHistoryTest extends TestCase
{
    use FakesThePendingQueue, RefreshDatabase;

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
     * "What else moved with this?" — the question the batch's name exists to
     * answer, asked by following the link in the entry dialog.
     *
     * The reference is read off the ROW, so this and the dialog that offers it
     * both work with no connection.
     *
     * A WHOLE reference still returns one batch and nothing else, which is what
     * makes the substring match below safe: the serial is padded to a fixed six
     * digits, so no reference is a prefix of another.
     */
    public function test_history_can_be_filtered_to_one_batch()
    {
        $admin = User::factory()->admin()->create();
        $this->seedBatches($admin);

        $this->actingAs($admin)
            ->get(route('stock-history.index', ['batch' => 'TST-B-000001']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 2)
                ->where('entries.data.0.batch_reference', 'TST-B-000001')
                ->where('entries.data.1.batch_reference', 'TST-B-000001')
                // Echoed back, because the search box is controlled from it.
                ->where('filters.batch', 'TST-B-000001')
            );
    }

    /**
     * The reason the filter has a box of its own: somebody holding a delivery
     * note types the part of the name they can read, which is the serial. A
     * prefix-only or equality match would refuse exactly that.
     */
    public function test_a_batch_is_found_by_part_of_its_name()
    {
        $admin = User::factory()->admin()->create();
        $this->seedBatches($admin);

        $this->actingAs($admin)
            ->get(route('stock-history.index', ['batch' => '000001']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 2)
                ->where('entries.data.0.batch_reference', 'TST-B-000001')
                ->where('entries.data.1.batch_reference', 'TST-B-000001')
            );
    }

    /**
     * Typed, so it arrives in whatever case somebody's keyboard was in. SQLite's
     * LIKE is case-insensitive for ASCII and Postgres's is not, which is why the
     * consolidated path inlines `ilike` — see the integration suite, which is the
     * only place that half can be proved.
     */
    public function test_a_batch_search_ignores_case()
    {
        $admin = User::factory()->admin()->create();
        $this->seedBatches($admin);

        $this->actingAs($admin)
            ->get(route('stock-history.index', ['batch' => 'tst-b-000001']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('entries.data', 2));
    }

    /**
     * The {@see LikeQuery::escape()} guarantee, asserted because the failure is
     * silent: without it a typed "%" is a wildcard, and a search for one batch
     * answers "every movement ever" — on both paths, since both spell the LIKE
     * out by hand.
     */
    public function test_a_wildcard_typed_into_the_batch_box_is_a_literal()
    {
        $admin = User::factory()->admin()->create();
        $this->seedBatches($admin);

        $this->actingAs($admin)
            ->get(route('stock-history.index', ['batch' => '%']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('entries.data', 0));
    }

    /**
     * An emptied box must clear the filter rather than search for "" — and a box
     * holding only spaces is an emptied box.
     */
    public function test_a_blank_batch_search_is_no_filter_at_all()
    {
        $admin = User::factory()->admin()->create();
        $this->seedBatches($admin);

        foreach (['', '   '] as $blank) {
            $this->actingAs($admin)
                ->get(route('stock-history.index', ['batch' => $blank]))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->has('entries.data', 4)
                    ->where('filters.batch', null)
                );
        }
    }

    /**
     * A movement made on its own belongs to no batch, so the dialog has nothing
     * to offer and prints no batch field.
     */
    public function test_a_movement_outside_a_batch_carries_no_batch_name()
    {
        $admin = User::factory()->admin()->create();
        app(StockLedger::class)->record(Variant::factory()->create(), 5, 'stock_adjustment', null,
            'manual_adjustment', null, $admin);

        $this->actingAs($admin)
            ->get(route('stock-history.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('entries.data.0.batch_reference', null));
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

    /**
     * The lookup carries the same branch authority as the page it opens from.
     *
     * The id names a Postgres row directly, so before this the one read in the
     * app that reaches another branch did it on the most permissive route in the
     * app — while index() is careful to snap a Cashier back to `local`. The
     * BranchGrants matrix is the only boundary of its kind now that RLS is a
     * settled non-goal, so an app-layer reach that outruns its own screen is the
     * erosion that matters.
     */
    public function test_a_cashier_cannot_read_the_request_behind_another_branchs_movement()
    {
        $remote = $this->fakeBusinessQueue();
        $id = $this->pendingElsewhere($remote);

        $this->actingAs(User::factory()->cashier()->create())
            ->getJson(route('stock-history.request', $id))
            ->assertOk()
            ->assertExactJson(['request' => null]);
    }

    /**
     * The other half, and the reason the filter is not unconditional: an Admin
     * may already read any branch's movements on the page this dialog opens
     * from, so filtering them here would break the drill-down under a wider
     * scope rather than protect anything.
     */
    public function test_an_admin_can_read_the_request_behind_another_branchs_movement()
    {
        $remote = $this->fakeBusinessQueue();
        $id = $this->pendingElsewhere($remote);

        $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('stock-history.request', $id))
            ->assertOk()
            ->assertJsonPath('request.requested', 4);
    }

    /** A pending row belonging to a branch that is not this device's. */
    private function pendingElsewhere(Connection $remote): string
    {
        $id = (string) Str::uuid();

        $remote->table('inventory_update_queue')->insert([
            'id' => $id,
            'branch_id' => '00000000-0000-0000-0000-00000000e15e',
            'variant_id' => Variant::factory()->create()->id,
            'quantity' => 4,
            'reference_type' => 'manual_adjustment',
            'status' => 'pending',
            'issued_by' => User::factory()->admin()->create()->id,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        return $id;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Four movements: two in one batch, one in another, one in none.
     */
    private function seedBatches(User $admin): void
    {
        $ledger = app(StockLedger::class);

        $ledger->record(Variant::factory()->create(), 4, 'stock_adjustment', null,
            'manual_adjustment', null, $admin, batchReference: 'TST-B-000001');
        $ledger->record(Variant::factory()->create(), 7, 'stock_adjustment', null,
            'manual_adjustment', null, $admin, batchReference: 'TST-B-000001');
        $ledger->record(Variant::factory()->create(), 2, 'stock_adjustment', null,
            'manual_adjustment', null, $admin, batchReference: 'TST-B-000002');
        $ledger->record(Variant::factory()->create(), 9, 'stock_adjustment', null,
            'manual_adjustment', null, $admin);
    }
}
