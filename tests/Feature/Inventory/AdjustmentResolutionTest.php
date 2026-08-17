<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryLedger;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\AdjustmentResolver;
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

    /**
     * Let somebody else answer $ids after this resolver has read them and before
     * it settles them — the race a second till at the same branch produces.
     *
     * There is no seam between the two in the resolver, so the hook is the local
     * ledger row it writes in between: `apply()` creates one inside the
     * transaction, and `settle()` runs afterwards from the `afterMirroring`
     * closure. Firing once is enough and keeps the flip from re-running for every
     * row of a batch.
     *
     * @param  list<string>  $ids
     */
    private function answerElsewhereMidFlight(Connection $remote, array $ids): void
    {
        $fired = false;

        InventoryLedger::created(function () use ($remote, $ids, &$fired): void {
            if ($fired) {
                return;
            }

            $fired = true;

            $remote->table('inventory_update_queue')
                ->whereIn('id', $ids)
                ->update([
                    'status' => 'accepted',
                    'resolved_at' => now()->toDateTimeString(),
                ]);
        });
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
        // Labelled even when the requester is the only voice on the row: this
        // string is what a reader gets offline, and an unlabelled line there
        // gives them nothing to tell somebody's words from the app's.
        $this->assertSame('Requested: Delivery', $entry->notes);

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

        // Every voice on its own labelled line, with the app's verdict between
        // the requester's words and the approver's. Run together, nothing tells
        // a reader where a person stopped talking and the app started — and this
        // string is the whole record offline, where the dialog's attributed
        // blocks cannot be fetched.
        $this->assertSame(
            ['Requested: Transfer in', 'Partially approved: +2 of +3 asked for.', 'User note: One was crushed in transit.'],
            explode("\n", (string) $entry->notes),
        );

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

    /**
     * A sending leg that somebody else answered first spawns NO receiving leg.
     *
     * `settle()` has always guarded its UPDATE with `and status = 'pending'`, but
     * it discarded the result — so the loser of that race carried on and inserted
     * the transfer's receiving leg anyway, with a brand-new uuid. That is the one
     * write in the resolver without the local ledger row's protection (which
     * reuses the queue row's own id, so a repeat collides on the primary key).
     * The counterparty would see two identical "N arrived" cards, and approving
     * both adds 2N of stock that never existed.
     *
     * Simulated the only way it can be from one device: the row is pending when
     * the resolver reads it and accepted by the time it settles. That is exactly
     * what a second till at the same branch produces, and it is why the guard
     * cannot rest on "one device per branch" — nothing in the code knew that.
     */
    public function test_a_sending_leg_answered_first_elsewhere_spawns_no_receiving_leg(): void
    {
        $remote = $this->fakeBusinessQueue();
        $variant = $this->stocked(10);
        $admin = User::factory()->admin()->create();

        $sending = $this->pending($remote, $variant, -3, [
            'reference_type' => 'transfer',
            'counterparty_branch_id' => self::OTHER_BRANCH,
        ]);

        // Pending when the resolver reads it, accepted by the time it settles.
        // Marking it accepted UP FRONT proves nothing: `authoritative()` filters
        // on `pending`, so the row would never be read and the code under test
        // would never run — which is exactly why the existing
        // "already answered" test could not catch this.
        $this->answerElsewhereMidFlight($remote, [$sending]);

        app(AdjustmentResolver::class)->resolve([$sending => ['outcome' => 'approve']], $admin);

        // The whole point: no second leg was queued at the counterparty.
        $this->assertSame(
            0,
            $remote->table('inventory_update_queue')->where('parent_id', $sending)->count(),
            'A receiving leg was queued for a sending leg this call did not answer.',
        );
    }

    /**
     * The count in the toast is what MOVED, not what was read.
     *
     * `resolve()` returned `$rows->count()` — the size of the authoritative read
     * — while its own docblock promised "rows actually resolved … fewer than
     * asked for when a row has since been answered elsewhere". A resolution that
     * lost every race still reported success for every row.
     */
    public function test_the_resolved_count_excludes_rows_answered_elsewhere(): void
    {
        $remote = $this->fakeBusinessQueue();
        $mine = $this->stocked();
        $taken = $this->stocked();
        $admin = User::factory()->admin()->create();

        $mineId = $this->pending($remote, $mine, 5);
        $takenId = $this->pending($remote, $taken, 5);

        // Taken after the read, so both rows are in `$rows` and only one settles.
        $this->answerElsewhereMidFlight($remote, [$takenId]);

        $resolved = app(AdjustmentResolver::class)->resolve([
            $mineId => ['outcome' => 'approve'],
            $takenId => ['outcome' => 'approve'],
        ], $admin);

        $this->assertSame(1, $resolved, 'The count must name the rows this call moved, not the rows it read.');
    }

    /**
     * Every receiving leg says which branch sent it.
     *
     * `secondLeg()` bypasses `AdjustmentQueue::enqueue()`, which stamps
     * `issued_by_branch_id` from `instance` so no caller can forget it — and this
     * caller forgot it. Schema 22 added the column because the issuer's branch
     * genuinely cannot be derived: an Owner at head office can arrange a move
     * between two branches that are neither of them theirs.
     */
    public function test_a_receiving_leg_records_the_branch_that_sent_it(): void
    {
        $remote = $this->fakeBusinessQueue();
        $variant = $this->stocked(10);
        $admin = User::factory()->admin()->create();

        $sending = $this->pending($remote, $variant, -3, [
            'reference_type' => 'transfer',
            'counterparty_branch_id' => self::OTHER_BRANCH,
            // Issued by somebody at a third branch, so "the issuer's branch" and
            // "the branch it leaves from" are provably different values.
            'issued_by_branch_id' => '00000000-0000-0000-0000-0000000f0f0f',
        ]);

        app(AdjustmentResolver::class)->resolve([$sending => ['outcome' => 'approve']], $admin);

        $leg = $remote->table('inventory_update_queue')->where('parent_id', $sending)->sole();

        $this->assertSame(self::OTHER_BRANCH, $leg->branch_id);
        // The leg was issued by the person confirming it out of the door, who is
        // standing at the SENDING branch — not at whichever branch first asked.
        $this->assertSame(self::HERE, $leg->issued_by_branch_id);
    }

    /**
     * One row answered differently from its neighbours does not invalidate them.
     *
     * `quantity` and `reason` were `requiredIf` over the WHOLE submit, because a
     * `requiredIf` closure has no row index to look at. So one partial approval
     * made `quantity` required on every plain approval beside it, and one
     * rejection made `reason` required on every row — a 422 pointing at rows the
     * operator answered perfectly well. The dialog answers one row at a time, but
     * this is the same route "Approve all" posts a whole card to.
     */
    /**
     * A "partial" equal to the whole request is refused, not relabelled.
     *
     * The magnitude check was `>` rather than `>=`, so approving 5 of 5 as a
     * partial was accepted — and wrote `Partially approved: +5 of +5 asked for.`
     * onto the ledger row, beside the reason a partial makes mandatory,
     * explaining a shortfall that did not happen. That row is the permanent,
     * offline-readable account of the movement, so it is the one place a lie
     * costs the most.
     */
    public function test_a_partial_equal_to_the_whole_request_is_refused(): void
    {
        $remote = $this->fakeBusinessQueue();
        $variant = $this->stocked();
        $admin = User::factory()->admin()->create();
        $id = $this->pending($remote, $variant, 5);

        $this->actingAs($admin)
            ->post(route('stock-updates.resolve'), ['adjustments' => [[
                'id' => $id,
                'outcome' => 'partial',
                'quantity' => 5,
                'reason' => 'All of it turned up.',
            ]]])
            ->assertSessionHasErrors('adjustments');

        $this->assertSame(0, app(StockLedger::class)->currentStock($variant));
        $this->assertSame('pending', $remote->table('inventory_update_queue')->find($id)->status);

        // Approving it is the move, and it says the true thing.
        $this->actingAs($admin)
            ->post(route('stock-updates.resolve'), ['adjustments' => [['id' => $id, 'outcome' => 'approve']]])
            ->assertSessionHasNoErrors();

        $this->assertSame(5, app(StockLedger::class)->currentStock($variant));
        $this->assertStringNotContainsString('Partially approved', (string) InventoryLedger::query()->find($id)->notes);
    }

    public function test_a_card_answered_row_by_row_validates(): void
    {
        $remote = $this->fakeBusinessQueue();
        $admin = User::factory()->admin()->create();

        $whole = $this->stocked();
        $some = $this->stocked();
        $none = $this->stocked(9);

        $wholeId = $this->pending($remote, $whole, 4);
        $someId = $this->pending($remote, $some, 6);
        $noneId = $this->pending($remote, $none, -9);

        $this->actingAs($admin)
            ->post(route('stock-updates.resolve'), ['adjustments' => [
                ['id' => $wholeId, 'outcome' => 'approve'],
                ['id' => $someId, 'outcome' => 'partial', 'quantity' => 4, 'reason' => 'Two short.'],
                ['id' => $noneId, 'outcome' => 'reject', 'reason' => 'Nothing came.'],
            ]])
            ->assertSessionHasNoErrors();

        $this->assertSame(4, app(StockLedger::class)->currentStock($whole));
        $this->assertSame(4, app(StockLedger::class)->currentStock($some));
        $this->assertSame(9, app(StockLedger::class)->currentStock($none));
    }

    /** A partial with no quantity is still refused — on its own row. */
    public function test_a_partial_still_needs_its_quantity_even_beside_an_approval(): void
    {
        $remote = $this->fakeBusinessQueue();
        $admin = User::factory()->admin()->create();

        $fine = $this->pending($remote, $this->stocked(), 4);
        $vague = $this->pending($remote, $this->stocked(), 6);

        $this->actingAs($admin)
            ->post(route('stock-updates.resolve'), ['adjustments' => [
                ['id' => $fine, 'outcome' => 'approve'],
                ['id' => $vague, 'outcome' => 'partial', 'reason' => 'Some arrived.'],
            ]])
            ->assertSessionHasErrors('adjustments.1.quantity')
            ->assertSessionDoesntHaveErrors('adjustments.0.quantity');
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
                ->where('requests.0.legs.0.requested', 3)
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
                ->where('requests.0.legs.0.requested', 3)
            );
    }

    /**
     * A busy neighbour must not push this branch's own work off the page.
     *
     * The read takes the 200 oldest PENDING rows in the whole business and only
     * then filters to the scope, in PHP. So a branch with more than that waiting
     * — one 100-line transfer batch is already 200 legs across its two branches —
     * fills the window on its own, and every other branch's page renders empty
     * while its stock sits there. The rows exist, the screen says there is
     * nothing to do, and nothing anywhere says otherwise.
     *
     * The scoped read is now narrowed in SQL before the limit. `all` still takes
     * the business-wide window, which is what that scope means.
     */
    public function test_another_branchs_backlog_cannot_hide_this_branchs_work(): void
    {
        $remote = $this->fakeBusinessQueue();
        $mine = $this->stocked();

        // 250 older rows aimed elsewhere — past the 200-row window on their own.
        for ($i = 0; $i < 250; $i++) {
            $this->pending($remote, $mine, 1, [
                'branch_id' => self::OTHER_BRANCH,
                'created_at' => now()->subDays(30)->addSeconds($i)->toDateTimeString(),
            ]);
        }

        // Ours is the newest row in the business, so it sorts last of all 251.
        $this->pending($remote, $mine, 7, ['created_at' => now()->toDateTimeString()]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('stock-updates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('requests', 1)
                ->where('requests.0.legs.0.requested', 7)
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
                ->where('requests.0.legs.0.requested', -3)
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

    /**
     * A stock batch is ONE card, however many products it holds (M13).
     *
     * The grouping key is `batch_id ?? parent_id ?? id`, read outside in, and
     * this is the outermost term: twelve rows of one delivery are one event to
     * the person confirming it, and twelve cards repeating one note is the page
     * failing to say so. The product moved onto the LEG for the same reason.
     */
    public function test_a_batch_is_one_card_listing_every_product_in_it(): void
    {
        $remote = $this->fakeBusinessQueue();
        $admin = User::factory()->admin()->create();
        $batchId = (string) Str::uuid();

        $first = $this->stocked();
        $second = $this->stocked();

        // Distinct timestamps, because the queue is worked oldest-first and two
        // rows in the same second would leave the leg order to the uuids.
        $this->pending($remote, $first, 4, [
            'batch_id' => $batchId, 'batch_reference' => 'TST-B-000007', 'notes' => 'Weekly delivery',
            'created_at' => now()->subMinute()->toDateTimeString(),
        ]);
        $this->pending($remote, $second, 7, [
            'batch_id' => $batchId, 'batch_reference' => 'TST-B-000007', 'notes' => 'Weekly delivery',
        ]);

        $this->actingAs($admin)
            ->get(route('stock-updates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('requests', 1)
                ->where('requests.0.kind', 'adjustment')
                ->where('requests.0.batch.size', 2)
                // The name the card prints (schema 24), so the person answering
                // and the person who asked are talking about the same delivery.
                ->where('requests.0.batch.reference', 'TST-B-000007')
                // Said once, on the card, rather than twice.
                ->where('requests.0.notes', 'Weekly delivery')
                ->has('requests.0.legs', 2)
                ->where('requests.0.legs.0.item', $first->item->name)
                ->where('requests.0.legs.0.quantity', 4)
                ->where('requests.0.legs.0.actionable', true)
                ->where('requests.0.legs.1.item', $second->item->name)
                ->where('requests.0.legs.1.quantity', 7)
                ->where('requests.0.legs.1.actionable', true)
            );
    }

    /**
     * A row issued on its own carries no batch, and still renders as the card it
     * always did — one line, `batch` null.
     */
    public function test_a_request_issued_alone_carries_no_batch(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->pending($remote, $this->stocked(), 5);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('stock-updates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('requests', 1)
                ->where('requests.0.batch', null)
                ->has('requests.0.legs', 1)
            );
    }

    /**
     * A batch issued before schema 24 has a uuid and no name. It still groups —
     * the identity is what does that — and the card simply has nothing to print.
     */
    public function test_a_batch_from_before_the_names_still_groups(): void
    {
        $remote = $this->fakeBusinessQueue();
        $batchId = (string) Str::uuid();

        $this->pending($remote, $this->stocked(), 4, ['batch_id' => $batchId]);
        $this->pending($remote, $this->stocked(), 7, ['batch_id' => $batchId]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('stock-updates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('requests', 1)
                ->where('requests.0.batch.size', 2)
                ->where('requests.0.batch.reference', null)
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
