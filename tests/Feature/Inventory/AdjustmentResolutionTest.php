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

        // A ledger row of ZERO (M11.8), not no ledger row. It moves nothing, and
        // it is the only thing that can carry the refusal to the branch that
        // asked: the pull filters the ledger to a single branch, so a rejection
        // that lived only on the queue row was unreachable from there.
        $written = InventoryLedger::query()->find($id);
        $this->assertNotNull($written);
        $this->assertSame(0, $written->quantity);
        $this->assertSame(5, $written->resulting_snapshot);
        // The sentence is what makes it readable OFFLINE, where the structured
        // account on the queue row cannot be fetched.
        $this->assertStringContainsString('Turned down: -5 was asked for', $written->notes);
        $this->assertStringContainsString('No delivery ever came here.', $written->notes);

        $answered = $remote->table('inventory_update_queue')->find($id);
        $this->assertSame('rejected', $answered->status);
        $this->assertSame('No delivery ever came here.', $answered->resolution_reason);
        // Null: `approved_quantity` means "what was agreed", and nothing was.
        // The zero is on the ledger row, where it means "did not happen".
        $this->assertNull($answered->approved_quantity);
        // Set, so the stock entry dialog can follow it back to the request.
        $this->assertSame($id, $answered->ledger_entry_id);
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

        $first_id = $this->pending($remote, $first, 10, ['reference_type' => 'csv_import']);
        $second_id = $this->pending($remote, $second, 4, ['reference_type' => 'csv_import']);
        $mine = $this->pending($remote, $elsewhere, 7, ['branch_id' => self::OTHER_BRANCH]);

        // What the page's "Approve all" sends: the ids on screen, as an ordinary
        // batch. There is no server-side sweep any more — the page filters, so
        // "all" can only honestly mean all of what is being shown.
        $this->actingAs($admin)
            ->post(route('stock-updates.resolve'), ['adjustments' => [
                ['id' => $first_id, 'outcome' => 'approve'],
                ['id' => $second_id, 'outcome' => 'approve'],
                // Aimed at another branch, and included on purpose: the resolver
                // re-reads every id filtered to THIS branch, so it must drop.
                ['id' => $mine, 'outcome' => 'approve'],
            ]])
            ->assertSessionHasNoErrors();

        $this->assertSame(10, app(StockLedger::class)->currentStock($first));
        $this->assertSame(4, app(StockLedger::class)->currentStock($second));

        // Another branch's row is untouched, even though its id was submitted:
        // what bounds a resolution is the branch, not the request body.
        $this->assertSame('pending', $remote->table('inventory_update_queue')->find($mine)->status);
        $this->assertSame(0, app(StockLedger::class)->currentStock($elsewhere));
    }

    public function test_one_row_that_would_go_negative_stops_the_whole_batch(): void
    {
        $remote = $this->fakeBusinessQueue();
        $admin = User::factory()->admin()->create();

        $fine = $this->stocked(20);
        $short = $this->stocked(1);

        $fine_id = $this->pending($remote, $fine, -5);
        $short_id = $this->pending($remote, $short, -9, ['notes' => 'too much']);

        $this->actingAs($admin)
            ->post(route('stock-updates.resolve'), ['adjustments' => [
                ['id' => $fine_id, 'outcome' => 'approve'],
                ['id' => $short_id, 'outcome' => 'approve'],
            ]])
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

    public function test_the_list_shows_every_branch_s_unfinished_work_and_says_which_rows_are_this_one_s(): void
    {
        $remote = $this->fakeBusinessQueue();
        $mine = $this->stocked();
        $theirs = $this->stocked();

        // Explicit timestamps: the list is oldest-first, and two rows written in
        // the same second fall through to a tiebreak on a random uuid.
        $this->pending($remote, $mine, 3, ['created_at' => now()->subMinute()->toDateTimeString()]);
        $this->pending($remote, $theirs, -2, ['branch_id' => self::OTHER_BRANCH]);

        // One section, both branches' work — but only a row aimed HERE carries
        // buttons, and that is the server's call rather than the page's.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('stock-updates.index', ['scope' => 'all']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('inventory/stock-updates', false)
                ->where('online', true)
                ->has('requests', 2)
                ->where('filters.scope', 'all')
                ->where('requests.0.kind', 'adjustment')
                ->where('requests.0.requested', 3)
                ->where('requests.0.legs.0.role', 'target')
                ->where('requests.0.legs.0.actionable', true)
                ->where('requests.1.legs.0.actionable', false)
            );
    }

    public function test_the_default_scope_is_this_branch_s_own_work(): void
    {
        $remote = $this->fakeBusinessQueue();

        $this->pending($remote, $this->stocked(), 3);
        $this->pending($remote, $this->stocked(), -2, ['branch_id' => self::OTHER_BRANCH]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('stock-updates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.scope', 'local')
                ->has('requests', 1)
                ->where('requests.0.requested', 3)
            );
    }

    public function test_the_kind_filter_splits_transfers_from_adjustments(): void
    {
        $remote = $this->fakeBusinessQueue();

        $this->pending($remote, $this->stocked(), 3);
        $this->pending($remote, $this->stocked(5), -2, [
            'reference_type' => 'transfer',
            'counterparty_branch_id' => self::OTHER_BRANCH,
        ]);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('stock-updates.index', ['kind' => 'transfer']))
            ->assertInertia(fn ($page) => $page->has('requests', 1)->where('requests.0.kind', 'transfer'));

        $this->actingAs($admin)->get(route('stock-updates.index', ['kind' => 'adjustment']))
            ->assertInertia(fn ($page) => $page->has('requests', 1)->where('requests.0.kind', 'adjustment'));
    }

    public function test_a_transfer_is_one_card_carrying_the_sender_s_answer(): void
    {
        $remote = $this->fakeBusinessQueue();
        $variant = $this->stocked();
        $admin = User::factory()->admin()->create();

        // The sending leg, already answered at the OTHER branch: three were
        // asked for and two went out of the door.
        $sent = $this->pending($remote, $variant, -3, [
            'branch_id' => self::OTHER_BRANCH,
            'counterparty_branch_id' => self::HERE,
            'reference_type' => 'transfer',
            'status' => 'accepted',
            'approved_quantity' => -2,
            'resolution_reason' => 'One was crushed in transit.',
            'resolved_by' => User::query()->value('id'),
            'resolved_at' => now()->toDateTimeString(),
        ]);

        // The receiving leg it spawned, waiting on this branch for what was
        // ACTUALLY sent.
        $this->pending($remote, $variant, 2, [
            'parent_id' => $sent,
            'counterparty_branch_id' => self::OTHER_BRANCH,
            'reference_type' => 'transfer',
        ]);

        // One card, not two: the receiving branch is answering for what the
        // sender said left, so what the sender said has to be on it. The
        // already-answered parent is fetched even though it is not pending.
        $this->actingAs($admin)
            ->get(route('stock-updates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('requests', 1)
                ->where('requests.0.kind', 'transfer')
                ->where('requests.0.requested', -3)
                ->has('requests.0.legs', 2)
                ->where('requests.0.legs.0.role', 'send')
                ->where('requests.0.legs.0.status', 'accepted')
                ->where('requests.0.legs.0.approved_quantity', -2)
                ->where('requests.0.legs.0.resolution_reason', 'One was crushed in transit.')
                ->where('requests.0.legs.0.actionable', false)
                ->where('requests.0.legs.1.role', 'receive')
                ->where('requests.0.legs.1.quantity', 2)
                ->where('requests.0.legs.1.actionable', true)
            );
    }

    public function test_a_fully_answered_request_leaves_the_list(): void
    {
        $remote = $this->fakeBusinessQueue();
        $variant = $this->stocked();
        $admin = User::factory()->admin()->create();
        $id = $this->pending($remote, $variant, 5);

        $this->actingAs($admin)
            ->post(route('stock-updates.resolve'), ['adjustments' => [['id' => $id, 'outcome' => 'approve']]]);

        // Gone, and not behind a toggle: the record of what happened is the
        // ledger row it just wrote, read in Stock History.
        $this->actingAs($admin)->get(route('stock-updates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('requests', 0));
    }
}
