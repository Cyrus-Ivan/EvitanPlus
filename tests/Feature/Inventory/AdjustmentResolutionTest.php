<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryLedger;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Inventory\Concerns\FakesThePendingQueue;
use Tests\TestCase;

/**
 * Answering a pending stock adjustment — the step schema 21 added.
 *
 * A person at the branch says what actually happened. All of it arrived, some of
 * it did, or none of it did; only the first two move stock. What this file is
 * about is the DECISION: who may make it, which quantities are legal, and what
 * each answer leaves behind for the branch that asked.
 *
 * The round trip — the six-column UPDATE grant, the check constraint tying
 * `status` to `resolved_at`, whether the branch role may write these columns at
 * all — is tests/Integration/InventoryQueueTest.php, against two really
 * provisioned branches. Nothing that belongs there is asserted here.
 */
class AdjustmentResolutionTest extends TestCase
{
    use FakesThePendingQueue, RefreshDatabase;

    /** This device's branch, from the identity Tests\TestCase seeds. */
    private const HERE = '00000000-0000-0000-0000-0000000b4a4c';

    private const OTHER_BRANCH = '00000000-0000-0000-0000-00000000e15e';

    private function pending(Connection $remote, Variant $variant, int $quantity, array $overrides = []): string
    {
        $id = (string) Str::uuid();

        $remote->table('inventory_update_queue')->insert(array_merge([
            'id' => $id,
            'branch_id' => self::HERE,
            'variant_id' => $variant->id,
            'quantity' => $quantity,
            'reference_type' => 'manual_adjustment',
            'status' => 'pending',
            'issued_by' => User::query()->value('id'),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ], $overrides));

        return $id;
    }

    private function stocked(int $quantity = 0): Variant
    {
        $variant = Variant::factory()->create();

        if ($quantity !== 0) {
            app(StockLedger::class)->record(
                $variant, $quantity, 'stock_adjustment', null, 'manual_adjustment', null,
                User::factory()->admin()->create(),
            );
        }

        return $variant;
    }

    public function test_approving_moves_the_stock_and_records_who_agreed(): void
    {
        $remote = $this->fakeBusinessQueue();
        $variant = $this->stocked();
        $cashier = User::factory()->cashier()->create();
        $id = $this->pending($remote, $variant, 6, ['notes' => 'Delivery']);

        $this->actingAs($cashier)
            ->post(route('stock-updates.resolve'), ['adjustments' => [['id' => $id, 'outcome' => 'approve']]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(6, app(StockLedger::class)->currentStock($variant));

        // The ledger row takes the QUEUE ROW's id, which is what makes a second
        // press of Approve collide with itself instead of moving stock twice.
        $entry = InventoryLedger::query()->findOrFail($id);
        $this->assertSame(6, $entry->quantity);
        $this->assertSame('stock_adjustment', $entry->type);
        $this->assertSame('Delivery', $entry->notes);

        $answered = $remote->table('inventory_update_queue')->find($id);
        $this->assertSame('accepted', $answered->status);
        $this->assertSame(6, (int) $answered->approved_quantity);
        $this->assertSame($cashier->id, $answered->resolved_by);
        $this->assertNotNull($answered->resolved_at);
    }

    public function test_a_cashier_may_answer_because_the_shop_floor_is_who_knows(): void
    {
        $remote = $this->fakeBusinessQueue();
        $variant = $this->stocked();
        $id = $this->pending($remote, $variant, 4);

        // Not an oversight and not a loosened permission: issuing an adjustment
        // decides what SHOULD move and stays Admin+, but whether the delivery
        // turned up is known by whoever is standing there.
        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('stock-updates.resolve'), ['adjustments' => [['id' => $id, 'outcome' => 'approve']]])
            ->assertSessionHasNoErrors();

        $this->assertSame(4, app(StockLedger::class)->currentStock($variant));
    }

    public function test_a_partial_approval_moves_only_what_arrived_and_says_so(): void
    {
        $remote = $this->fakeBusinessQueue();
        $variant = $this->stocked();
        $admin = User::factory()->admin()->create();
        $id = $this->pending($remote, $variant, 3, ['notes' => 'Transfer in']);

        $this->actingAs($admin)
            ->post(route('stock-updates.resolve'), ['adjustments' => [[
                'id' => $id,
                'outcome' => 'partial',
                'quantity' => 2,
                'reason' => 'One was crushed in transit.',
            ]]])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, app(StockLedger::class)->currentStock($variant));

        // Both numbers survive onto the ledger row. Without that, the history
        // shows two arriving and nothing anywhere says three were sent — which is
        // the whole record of a unit that no longer exists.
        $entry = InventoryLedger::query()->findOrFail($id);
        $this->assertSame(2, $entry->quantity);
        $this->assertStringContainsString('+2 of +3', (string) $entry->notes);
        $this->assertStringContainsString('crushed in transit', (string) $entry->notes);

        $answered = $remote->table('inventory_update_queue')->find($id);
        $this->assertSame('accepted', $answered->status);
        $this->assertSame(2, (int) $answered->approved_quantity);
        $this->assertSame('One was crushed in transit.', $answered->resolution_reason);
    }

    public function test_rejecting_moves_nothing_and_keeps_the_reason_where_the_issuer_can_read_it(): void
    {
        $remote = $this->fakeBusinessQueue();
        $variant = $this->stocked(5);
        $admin = User::factory()->admin()->create();
        $id = $this->pending($remote, $variant, -5, ['notes' => 'Write-off']);

        $this->actingAs($admin)
            ->post(route('stock-updates.resolve'), ['adjustments' => [[
                'id' => $id,
                'outcome' => 'reject',
                'reason' => 'No delivery ever came here.',
            ]]])
            ->assertSessionHasNoErrors();

        $this->assertSame(5, app(StockLedger::class)->currentStock($variant));

        // No ledger row, deliberately. A rejection is not a movement, and one
        // written here could never reach the branch that asked: the pull filters
        // the ledger to a single branch. The queue row is the record.
        $this->assertNull(InventoryLedger::query()->find($id));

        $answered = $remote->table('inventory_update_queue')->find($id);
        $this->assertSame('rejected', $answered->status);
        $this->assertSame('No delivery ever came here.', $answered->resolution_reason);
        $this->assertNull($answered->approved_quantity);
        $this->assertNull($answered->ledger_entry_id);
    }

    public function test_turning_one_down_needs_a_reason(): void
    {
        $remote = $this->fakeBusinessQueue();
        $id = $this->pending($remote, $this->stocked(), 3);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock-updates.resolve'), ['adjustments' => [['id' => $id, 'outcome' => 'reject']]])
            ->assertSessionHasErrors('adjustments.0.reason');

        $this->assertSame('pending', $remote->table('inventory_update_queue')->find($id)->status);
    }

    public function test_a_partial_approval_must_be_part_of_what_was_asked_for(): void
    {
        $remote = $this->fakeBusinessQueue();
        $variant = $this->stocked();
        $admin = User::factory()->admin()->create();
        $id = $this->pending($remote, $variant, 3);

        // More than was sent, and the other direction. A branch that could
        // approve either would be inventing stock nobody shipped it.
        foreach ([5, -2] as $quantity) {
            $this->actingAs($admin)
                ->post(route('stock-updates.resolve'), ['adjustments' => [[
                    'id' => $id, 'outcome' => 'partial', 'quantity' => $quantity, 'reason' => 'why not',
                ]]])
                ->assertSessionHasErrors('adjustments');
        }

        $this->assertSame(0, app(StockLedger::class)->currentStock($variant));
        $this->assertSame('pending', $remote->table('inventory_update_queue')->find($id)->status);
    }

    public function test_a_device_cannot_answer_another_branchs_row(): void
    {
        $remote = $this->fakeBusinessQueue();
        $variant = $this->stocked();
        $id = $this->pending($remote, $variant, 9, ['branch_id' => self::OTHER_BRANCH]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock-updates.resolve'), ['adjustments' => [['id' => $id, 'outcome' => 'approve']]])
            ->assertSessionHas('toast.type', 'error');

        // The one that would be silent and serious. OnlineWrite stamps
        // `branch_id` from `instance`, so answering a row aimed elsewhere would
        // write that branch's stock movement onto THIS branch's ledger — the
        // stock would land in the wrong shop, and both branches' figures would
        // be wrong in opposite directions.
        $this->assertSame(0, app(StockLedger::class)->currentStock($variant));
        $this->assertSame(0, InventoryLedger::count());
        $this->assertSame('pending', $remote->table('inventory_update_queue')->find($id)->status);
    }

    public function test_a_row_already_answered_is_not_answered_again(): void
    {
        $remote = $this->fakeBusinessQueue();
        $variant = $this->stocked();
        $admin = User::factory()->admin()->create();
        $id = $this->pending($remote, $variant, 4, [
            'status' => 'accepted',
            'resolved_at' => now()->toDateTimeString(),
            'approved_quantity' => 4,
        ]);

        $this->actingAs($admin)
            ->post(route('stock-updates.resolve'), ['adjustments' => [['id' => $id, 'outcome' => 'approve']]])
            ->assertSessionHas('toast.type', 'error');

        $this->assertSame(0, app(StockLedger::class)->currentStock($variant));
    }

    public function test_approve_all_clears_everything_waiting_on_this_branch(): void
    {
        $remote = $this->fakeBusinessQueue();
        $admin = User::factory()->admin()->create();

        $first = $this->stocked();
        $second = $this->stocked();
        $elsewhere = $this->stocked();

        $this->pending($remote, $first, 10, ['reference_type' => 'csv_import']);
        $this->pending($remote, $second, 4, ['reference_type' => 'csv_import']);
        $mine = $this->pending($remote, $elsewhere, 7, ['branch_id' => self::OTHER_BRANCH]);

        $this->actingAs($admin)
            ->post(route('stock-updates.resolve-all'))
            ->assertSessionHasNoErrors();

        $this->assertSame(10, app(StockLedger::class)->currentStock($first));
        $this->assertSame(4, app(StockLedger::class)->currentStock($second));

        // Another branch's row is untouched — approve-all means "everything
        // waiting on ME", not "everything".
        $this->assertSame('pending', $remote->table('inventory_update_queue')->find($mine)->status);
        $this->assertSame(0, app(StockLedger::class)->currentStock($elsewhere));
    }

    public function test_one_row_that_would_go_negative_stops_the_whole_batch(): void
    {
        $remote = $this->fakeBusinessQueue();
        $admin = User::factory()->admin()->create();

        $fine = $this->stocked(20);
        $short = $this->stocked(1);

        $this->pending($remote, $fine, -5);
        $this->pending($remote, $short, -9, ['notes' => 'too much']);

        $this->actingAs($admin)
            ->post(route('stock-updates.resolve-all'))
            ->assertSessionHasErrors('adjustments');

        // All-or-nothing, matching what a CSV import has always done: half an
        // approved batch is a stock position nobody asked for and nobody can
        // read. The operator's next move is to partially approve the one row,
        // which is why the message has to name the product.
        $this->assertSame(20, app(StockLedger::class)->currentStock($fine));
        $this->assertSame(1, app(StockLedger::class)->currentStock($short));
        $this->assertSame(2, $remote->table('inventory_update_queue')->where('status', 'pending')->count());
        $this->assertSame(0, InventoryLedger::where('quantity', '<', 0)->count());
    }

    public function test_the_pending_list_separates_what_this_branch_owes_from_what_it_asked(): void
    {
        $remote = $this->fakeBusinessQueue();
        $mine = $this->stocked();
        $theirs = $this->stocked();

        $this->pending($remote, $mine, 3);
        $this->pending($remote, $theirs, -2, ['branch_id' => self::OTHER_BRANCH, 'status' => 'rejected',
            'resolved_at' => now()->toDateTimeString(), 'resolution_reason' => 'Nothing to take off.']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('stock-updates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('inventory/stock-updates', false)
                ->where('online', true)
                ->has('inbox', 1)
                ->where('inbox.0.quantity', 3)
                // The section that exists so a rejection is visible at all: it
                // writes no ledger row, so this is the only place the branch
                // that asked ever sees it.
                ->has('elsewhere', 1)
                ->where('elsewhere.0.status', 'rejected')
                ->where('elsewhere.0.resolution_reason', 'Nothing to take off.')
            );
    }

    public function test_answered_rows_leave_the_list_and_come_back_on_request(): void
    {
        $remote = $this->fakeBusinessQueue();
        $variant = $this->stocked();
        $admin = User::factory()->admin()->create();
        $id = $this->pending($remote, $variant, 5);

        $this->actingAs($admin)
            ->post(route('stock-updates.resolve'), ['adjustments' => [['id' => $id, 'outcome' => 'approve']]]);

        // Gone by default — its movement is in Stock History now, and a list that
        // keeps everything is a list nobody can see the work in.
        $this->actingAs($admin)->get(route('stock-updates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('inbox', 0)->where('resolved', false));

        $this->actingAs($admin)->get(route('stock-updates.index', ['resolved' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('inbox', 1)
                ->where('inbox.0.status', 'accepted')
                ->where('resolved', true)
            );
    }
}
