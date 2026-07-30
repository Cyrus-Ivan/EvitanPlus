<?php

namespace Tests\Feature\Sync;

use App\Models\Item;
use App\Models\SyncOutboxEntry;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use App\Support\Sync\StockReconciler;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Sync\Concerns\FakesTheBusinessDatabase;
use Tests\TestCase;

/**
 * `reconciled stock = baseline + Σ(unsynced local movements)`.
 *
 * The arithmetic is three lines and the danger is entirely in the signs and in
 * what is NOT done. Two failure modes this file exists to pin:
 *
 *  - Overwriting the local figure with the baseline. That is the obvious reading
 *    of "pull the stock down", and on a branch that has been offline all morning
 *    it discards the morning's takings — silently, since the till would simply
 *    show a higher number and carry on selling.
 *  - Treating §10's "baseline − out-movements" as a subtraction of absolute
 *    values. The local ledger is signed, so that gets returns exactly backwards
 *    and hands the branch stock it never got back.
 */
class StockReconciliationTest extends TestCase
{
    use FakesTheBusinessDatabase;
    use RefreshDatabase;

    private const BRANCH = '00000000-0000-0000-0000-0000000b4a4c';

    private Connection $remote;

    private Variant $variant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->remote = $this->businessDatabase(['stock_snapshots']);
        $this->user = User::factory()->create();
        $this->variant = Item::factory()->withVariant()->create()->variants()->firstOrFail();
    }

    private function reconcile(): int
    {
        return app(StockReconciler::class)->reconcile($this->remote, self::BRANCH);
    }

    /** What Postgres says this branch holds. */
    private function baseline(int $quantity, string $at = '2026-07-30 09:00:00'): void
    {
        $this->remote->table('stock_snapshots')->insert([
            'id' => (string) Str::uuid(),
            'branch_id' => self::BRANCH,
            'variant_id' => $this->variant->getKey(),
            'quantity' => $quantity,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /**
     * Put stock on the local shelf, the way a hydrate or an adjustment would.
     *
     * Needed before any sale below, because StockLedger refuses to take a
     * variant negative — the invariant that makes this suite's fixtures read
     * like a real branch rather than like arithmetic.
     */
    private function onHand(int $quantity): void
    {
        app(StockLedger::class)->record(
            variant: $this->variant, quantity: $quantity, type: 'stock_adjustment', saleType: null,
            referenceType: 'manual_adjustment', referenceId: null, performedBy: $this->user,
        );
    }

    /** A local movement, enqueued in the outbox like any real one. */
    private function movement(int $quantity, string $saleType = 'sale'): void
    {
        app(StockLedger::class)->record(
            variant: $this->variant,
            quantity: $quantity,
            type: 'sale',
            saleType: $saleType,
            referenceType: 'transaction',
            referenceId: null,
            performedBy: $this->user,
        );
    }

    private function localStock(): int
    {
        return (int) DB::table('stock_snapshots')->where('variant_id', $this->variant->getKey())->value('quantity');
    }

    public function test_unsynced_sales_are_subtracted_from_the_baseline(): void
    {
        // Postgres has not seen these sales yet, so its figure is pre-sale. The
        // till must not adopt it.
        $this->onHand(50);
        $this->baseline(50);
        $this->movement(-8);
        $this->movement(-2);

        $this->reconcile();

        $this->assertSame(40, $this->localStock());
    }

    public function test_an_unsynced_return_raises_the_reconciled_figure(): void
    {
        // The sign test. Read as "baseline minus out-movements" with absolute
        // values, a +3 return would come out as 47 — the branch losing stock it
        // just took back.
        $this->baseline(50);
        $this->movement(3, saleType: 'return');

        $this->reconcile();

        $this->assertSame(53, $this->localStock());
    }

    public function test_a_defect_moves_nothing(): void
    {
        // Defects write a 0-movement audit row: stock is not restored, and it was
        // already deducted by the sale. A signed sum handles it with no special
        // case, which is the point.
        $this->baseline(50);
        $this->movement(0, saleType: 'defect');

        $this->reconcile();

        $this->assertSame(50, $this->localStock());
    }

    public function test_once_the_sales_push_the_subtraction_falls_away(): void
    {
        // Self-healing, and the reason no state has to be carried between
        // cycles. The push recomputes Postgres's snapshot from its OWN ledger, so
        // the next baseline already contains the sales and the unsynced set is
        // empty — the same 42 arrived at two different ways.
        $this->onHand(50);
        $this->baseline(50);
        $this->movement(-8);

        $this->reconcile();
        $this->assertSame(42, $this->localStock());

        SyncOutboxEntry::query()->update(['synced_at' => now()]);

        $this->remote->table('stock_snapshots')
            ->where('variant_id', $this->variant->getKey())
            ->update(['quantity' => 42, 'updated_at' => '2026-07-30 10:00:00']);

        $this->reconcile();

        $this->assertSame(42, $this->localStock());
    }

    public function test_a_variant_the_baseline_does_not_mention_is_left_alone(): void
    {
        // A variant created locally and not yet recomputed upstream has no
        // business figure to be agreed with. Blanking it would zero the stock of
        // everything the branch is currently selling.
        $this->onHand(12);

        $this->assertSame(0, $this->reconcile());
        $this->assertSame(12, $this->localStock());
    }

    public function test_the_reconciled_figure_never_goes_negative(): void
    {
        // The local shelf has two; Postgres believes there is one. Reconciling
        // gives 1 + (-2) = -1, which the column may never hold.
        $this->onHand(2);
        $this->baseline(1);
        $this->movement(-1);
        $this->movement(-1);

        $this->reconcile();

        // Should be unreachable — nothing subtracts this branch's stock remotely
        // without M8's queue, which exists precisely to validate an adjustment
        // against reconciled stock first. The clamp is the floor under the
        // never-negative invariant, not a design.
        $this->assertSame(0, $this->localStock());
    }

    public function test_no_ledger_entry_is_invented_by_reconciling(): void
    {
        // Reconciliation is not a movement: nothing happened, this is the same
        // stock counted against a better source. Writing it through StockLedger
        // would append history that never occurred and double-count against the
        // adjustment rows the Puller has already inserted.
        $this->baseline(50);

        $before = DB::table('inventory_ledger')->count();

        $this->reconcile();

        $this->assertSame($before, DB::table('inventory_ledger')->count());
        $this->assertSame(50, $this->localStock());
    }

    public function test_a_second_run_reads_only_what_moved_upstream(): void
    {
        $this->baseline(50);

        $this->assertSame(1, $this->reconcile());
        $this->assertSame(1, $this->reconcile(), 'the boundary row is re-read, which is the cost of >=');

        $this->remote->table('stock_snapshots')
            ->where('variant_id', $this->variant->getKey())
            ->update(['quantity' => 60, 'updated_at' => '2026-07-30 12:00:00']);

        $this->reconcile();

        $this->assertSame(60, $this->localStock());
    }
}
