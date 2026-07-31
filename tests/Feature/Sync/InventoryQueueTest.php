<?php

namespace Tests\Feature\Sync;

use App\Models\Item;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use App\Support\Sync\QueueProcessor;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Sync\Concerns\FakesTheBusinessDatabase;
use Tests\TestCase;

/**
 * Step 2 of a cycle: adjustments issued elsewhere, answered by the branch they
 * were aimed at.
 *
 * The decision is one comparison, and every way of getting it wrong is silent.
 * What this file exists to pin:
 *
 *  - **Reconciled stock, not the baseline.** Deciding against the upstream figure
 *    alone would accept a subtraction that this branch's unsynced sales have
 *    already spent, and the branch would go negative the moment they pushed.
 *  - **Order.** Two subtractions that each fit alone may not fit together, so
 *    which one is turned down has to be decided by age, not by row order.
 *  - **Idempotence across a lost connection.** A cycle that inserted the ledger
 *    entry and then died must not re-decide the row on the next pass — by then
 *    the baseline contains it, and the same subtraction may no longer fit, so a
 *    naive retry would REJECT an adjustment that has already been applied.
 *
 * The round trip — real grants, `timestamptz`, the check constraints — is
 * proved in tests/Integration/InventoryQueueTest.php. Nothing belonging there
 * may be asserted here.
 */
class InventoryQueueTest extends TestCase
{
    use FakesTheBusinessDatabase;
    use RefreshDatabase;

    private const BRANCH = '00000000-0000-0000-0000-0000000b4a4c';

    private const OTHER_BRANCH = '00000000-0000-0000-0000-00000000e15e';

    private Connection $remote;

    private Variant $variant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->remote = $this->businessDatabase([
            'inventory_update_queue', 'inventory_ledger', 'stock_snapshots',
        ]);

        $this->user = User::factory()->create();
        $this->variant = Item::factory()->withVariant()->create()->variants()->firstOrFail();
    }

    private function process(): int
    {
        return app(QueueProcessor::class)->process($this->remote, self::BRANCH);
    }

    /** What Postgres believes this branch holds. */
    private function baseline(int $quantity, ?string $branchId = null): void
    {
        $this->remote->table('stock_snapshots')->insert([
            'id' => (string) Str::uuid(),
            'branch_id' => $branchId ?? self::BRANCH,
            'variant_id' => $this->variant->getKey(),
            'quantity' => $quantity,
            'created_at' => '2026-07-30 09:00:00',
            'updated_at' => '2026-07-30 09:00:00',
        ]);

        // The movements behind that figure. RemoteStock recomputes the snapshot
        // from the ledger on every acceptance, so a baseline with no ledger under
        // it would be wiped to zero the first time one landed — the same defect
        // M7 found in the manual rig seeder.
        $this->remote->table('inventory_ledger')->insert([
            'id' => (string) Str::uuid(),
            'branch_id' => $branchId ?? self::BRANCH,
            'variant_id' => $this->variant->getKey(),
            'quantity' => $quantity,
            'resulting_snapshot' => $quantity,
            'type' => 'stock_adjustment',
            'sale_type' => null,
            'reference_id' => null,
            'reference_type' => 'manual_adjustment',
            'notes' => 'Opening stock',
            'performed_by' => $this->user->getKey(),
            'created_at' => '2026-07-30 09:00:00',
        ]);
    }

    /** An adjustment somebody at another branch aimed at this one. */
    private function queue(int $quantity, string $at = '2026-07-30 10:00:00', ?string $branchId = null): string
    {
        $id = (string) Str::uuid();

        $this->remote->table('inventory_update_queue')->insert([
            'id' => $id,
            'branch_id' => $branchId ?? self::BRANCH,
            'variant_id' => $this->variant->getKey(),
            'quantity' => $quantity,
            'notes' => 'Damaged in transit',
            'status' => 'pending',
            'rejection_reason' => null,
            'ledger_entry_id' => null,
            'issued_by' => $this->user->getKey(),
            'resolved_at' => null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        return $id;
    }

    /** Stock on this device's shelf, so a local sale has something to take. */
    private function onHand(int $quantity): void
    {
        app(StockLedger::class)->record(
            variant: $this->variant, quantity: $quantity, type: 'stock_adjustment', saleType: null,
            referenceType: 'manual_adjustment', referenceId: null, performedBy: $this->user,
        );
    }

    /** A local sale, enqueued in the outbox and therefore still unsynced. */
    private function sold(int $quantity): void
    {
        app(StockLedger::class)->record(
            variant: $this->variant, quantity: -$quantity, type: 'sale', saleType: 'sale',
            referenceType: 'transaction', referenceId: null, performedBy: $this->user,
        );
    }

    private function row(string $id): object
    {
        return $this->remote->table('inventory_update_queue')->where('id', $id)->first();
    }

    private function upstreamStock(): int
    {
        return (int) $this->remote->table('stock_snapshots')
            ->where('branch_id', self::BRANCH)
            ->where('variant_id', $this->variant->getKey())
            ->value('quantity');
    }

    public function test_an_adjustment_the_branch_can_absorb_becomes_a_ledger_entry(): void
    {
        $this->baseline(20);
        $id = $this->queue(-5);

        $this->assertSame(1, $this->process());

        $row = $this->row($id);
        $this->assertSame('accepted', $row->status);
        $this->assertNotNull($row->resolved_at);
        $this->assertNull($row->rejection_reason);

        // The link back, and the id the entry was written under — they are the
        // same value on purpose.
        $this->assertSame($id, $row->ledger_entry_id);

        $entry = $this->remote->table('inventory_ledger')->where('id', $id)->first();
        $this->assertSame(-5, (int) $entry->quantity);
        $this->assertSame(15, (int) $entry->resulting_snapshot);
        $this->assertSame('stock_adjustment', $entry->type);
        $this->assertSame('manual_adjustment', $entry->reference_type);
        $this->assertSame($this->user->getKey(), $entry->performed_by);

        // Recomputed from Postgres's own ledger, so the next cycle's baseline
        // contains the adjustment instead of reverting it.
        $this->assertSame(15, $this->upstreamStock());
    }

    public function test_the_decision_is_made_against_reconciled_stock_not_the_baseline(): void
    {
        // Postgres thinks there are 10; this branch has sold 8 of them and has
        // not pushed yet, so only 2 are really there.
        $this->baseline(10);
        $this->onHand(10);
        $this->sold(8);

        $id = $this->queue(-5);

        $this->process();

        $row = $this->row($id);
        $this->assertSame('rejected', $row->status);

        // Read against the baseline alone this would have been accepted, and the
        // branch would have gone to -3 the moment its sales pushed.
        $this->assertNull($this->remote->table('inventory_ledger')->where('id', $id)->first());
        $this->assertSame(10, $this->upstreamStock());
    }

    public function test_a_rejection_says_what_the_numbers_were(): void
    {
        $this->baseline(3);
        $id = $this->queue(-5);

        $this->process();

        $reason = (string) $this->row($id)->rejection_reason;

        // Figures, not a category: the person reading this is on another device,
        // deciding whether to re-issue and for how much.
        $this->assertStringContainsString('3 on hand', $reason);
        $this->assertStringContainsString('removing 5', $reason);
    }

    public function test_rows_are_answered_oldest_first(): void
    {
        $this->baseline(10);

        $first = $this->queue(-8, at: '2026-07-30 10:00:00');
        $second = $this->queue(-8, at: '2026-07-30 11:00:00');

        $this->assertSame(2, $this->process());

        // Each fits on its own; together they do not. Age decides, so the older
        // one is applied and the newer one is turned down.
        $this->assertSame('accepted', $this->row($first)->status);
        $this->assertSame('rejected', $this->row($second)->status);
        $this->assertSame(2, $this->upstreamStock());
    }

    public function test_a_positive_adjustment_is_always_accepted(): void
    {
        // Nothing on the shelf and nothing upstream: adding stock cannot take
        // anything below zero, so the mediation has nothing to mediate.
        $id = $this->queue(7);

        $this->process();

        $this->assertSame('accepted', $this->row($id)->status);
        $this->assertSame(7, $this->upstreamStock());
    }

    public function test_an_adjustment_already_applied_is_not_decided_again(): void
    {
        $this->baseline(10);
        $id = $this->queue(-8);

        $this->process();
        $this->assertSame('accepted', $this->row($id)->status);

        // Put the row back to pending, which is exactly the state a cycle that
        // died between the ledger insert and the resolution would leave behind.
        $this->remote->table('inventory_update_queue')->where('id', $id)->update([
            'status' => 'pending',
            'resolved_at' => null,
            'ledger_entry_id' => null,
        ]);

        $this->process();

        // Re-decided, this would now be rejected — the baseline is 2 and the row
        // asks for 8 — and an applied adjustment would be recorded as refused.
        $this->assertSame('accepted', $this->row($id)->status);
        $this->assertSame(1, $this->remote->table('inventory_ledger')->where('id', $id)->count());
        $this->assertSame(2, $this->upstreamStock());
    }

    public function test_only_this_branchs_rows_are_answered(): void
    {
        $this->baseline(10);
        $this->baseline(10, branchId: self::OTHER_BRANCH);

        $mine = $this->queue(-1);
        $theirs = $this->queue(-1, branchId: self::OTHER_BRANCH);

        $this->assertSame(1, $this->process());

        $this->assertSame('accepted', $this->row($mine)->status);
        // Another branch's device answers its own queue. Touching it from here
        // would be deciding on stock this device cannot see.
        $this->assertSame('pending', $this->row($theirs)->status);
    }

    public function test_a_resolved_row_is_never_looked_at_again(): void
    {
        $this->baseline(10);
        $id = $this->queue(-1);

        $this->assertSame(1, $this->process());
        // Nothing pending left, so a second cycle does no work at all.
        $this->assertSame(0, $this->process());
    }
}
