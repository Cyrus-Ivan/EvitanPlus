<?php

namespace Tests\Feature\Sync;

use App\Models\AuditLog;
use App\Models\LoginHistory;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\SyncOutboxEntry;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use App\Support\Sync\Outbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The write path's half of M6: what lands in the outbox, and when.
 *
 * The failure this guards against is silent by construction. A local-write table
 * that does not enqueue still sells, still prints, still balances the drawer —
 * and its rows simply never reach Postgres, with nothing to notice until a
 * consolidated report comes up short months later and no way to reconstruct
 * which sales were lost. So the membership of the seven tables is asserted
 * table by table rather than trusted to the trait being present.
 */
class OutboxTest extends TestCase
{
    use RefreshDatabase;

    private function sellable(int $stock = 10): Variant
    {
        $variant = Variant::factory()->create(['selling_price' => 10]);

        app(StockLedger::class)->record(
            $variant, $stock, 'stock_adjustment', null, 'manual_adjustment', null,
            User::factory()->admin()->create(),
        );

        return $variant;
    }

    /** @return list<string> */
    private function queuedTables(): array
    {
        return SyncOutboxEntry::query()->whereNull('synced_at')->pluck('table_name')->unique()->sort()->values()->all();
    }

    public function test_every_local_write_table_enqueues_on_create(): void
    {
        $user = User::factory()->cashier()->create();
        $variant = $this->sellable();

        DB::table('sync_outbox')->truncate();

        $transaction = Transaction::factory()->create(['user_id' => $user->id]);
        TransactionItem::factory()->create(['transaction_id' => $transaction->id, 'variant_id' => $variant->id]);
        $return = SaleReturn::factory()->create(['transaction_id' => $transaction->id, 'performed_by' => $user->id]);
        SaleReturnItem::factory()->create(['sale_return_id' => $return->id, 'variant_id' => $variant->id]);
        LoginHistory::factory()->create(['user_id' => $user->id]);
        AuditLog::factory()->create(['user_id' => $user->id]);
        app(StockLedger::class)->record($variant, -1, 'sale', 'sale', 'transaction', $transaction->id, $user);

        $this->assertSame([
            'audit_logs',
            'inventory_ledger',
            'login_history',
            'sale_return_items',
            'sale_returns',
            'transaction_items',
            'transactions',
        ], $this->queuedTables());
    }

    public function test_stock_adjustment_ledger_rows_are_not_pushed(): void
    {
        $admin = User::factory()->admin()->create();
        $variant = $this->sellable();

        DB::table('sync_outbox')->truncate();

        // Online-authoritative: adjustments are issued online and pulled down.
        // Pushing them would give that class of row two writers.
        $adjustment = app(StockLedger::class)->record(
            $variant, 5, 'stock_adjustment', null, 'manual_adjustment', null, $admin,
        );

        $sale = app(StockLedger::class)->record(
            $variant, -1, 'sale', 'sale', 'transaction', null, $admin,
        );

        $queued = SyncOutboxEntry::query()->where('table_name', 'inventory_ledger')->pluck('row_id')->all();

        $this->assertSame([$sale->id], $queued);
        $this->assertNotContains($adjustment->id, $queued);
    }

    public function test_a_checkout_is_one_push_batch(): void
    {
        $cashier = User::factory()->cashier()->create();
        $variant = $this->sellable();

        DB::table('sync_outbox')->truncate();

        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'lines' => [['variant_id' => $variant->id, 'quantity' => 2]],
            'payment_type' => 'cashless',
        ])->assertSessionHasNoErrors();

        $entries = SyncOutboxEntry::query()->orderBy('sequence')->get();

        // transaction + item + ledger deduction + the audit row, under one
        // group_id: a partially pushed sale would read as a sale with missing
        // lines, which is a wrong total rather than an obviously absent row.
        $this->assertSame(4, $entries->count());
        $this->assertCount(1, $entries->pluck('group_id')->unique());

        // FIFO, and FIFO is FK order: the transaction exists before its items.
        $this->assertSame('transactions', $entries->first()->table_name);
    }

    public function test_a_return_is_its_own_push_batch(): void
    {
        $cashier = User::factory()->cashier()->create();
        $variant = $this->sellable();

        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'lines' => [['variant_id' => $variant->id, 'quantity' => 2]],
            'payment_type' => 'cashless',
        ])->assertSessionHasNoErrors();

        $transaction = Transaction::query()->firstOrFail();
        $item = $transaction->items()->firstOrFail();

        $this->actingAs($cashier)->post(route('sales.returns.store', $transaction), [
            'type' => 'return',
            'lines' => [['transaction_item_id' => $item->id, 'quantity' => 1]],
            'refund_payment_type' => 'cash',
        ])->assertSessionHasNoErrors();

        $groups = SyncOutboxEntry::query()->pluck('group_id')->unique();

        $this->assertCount(2, $groups, 'The sale and the return are separate batches.');
    }

    public function test_enqueueing_the_same_row_twice_is_a_no_op(): void
    {
        $user = User::factory()->cashier()->create();

        DB::table('sync_outbox')->truncate();

        $transaction = Transaction::factory()->create(['user_id' => $user->id]);

        app(Outbox::class)->enqueue($transaction);
        app(Outbox::class)->enqueue($transaction);

        $this->assertSame(1, SyncOutboxEntry::query()->where('table_name', 'transactions')->count());
    }

    public function test_the_unsynced_count_is_derived_from_the_rows(): void
    {
        $user = User::factory()->cashier()->create();

        DB::table('sync_outbox')->truncate();

        Transaction::factory()->count(3)->create(['user_id' => $user->id]);

        $this->assertSame(3, app(Outbox::class)->unsyncedCount());

        $first = SyncOutboxEntry::query()->orderBy('sequence')->value('sequence');
        app(Outbox::class)->markSynced([$first]);

        // No counter to update: the count IS the query.
        $this->assertSame(2, app(Outbox::class)->unsyncedCount());
    }

    public function test_pending_groups_come_back_oldest_first_and_whole(): void
    {
        $user = User::factory()->cashier()->create();

        DB::table('sync_outbox')->truncate();

        $outbox = app(Outbox::class);

        $first = $outbox->group(function () use ($user) {
            $transaction = Transaction::factory()->create(['user_id' => $user->id]);
            TransactionItem::factory()->create(['transaction_id' => $transaction->id]);

            return $transaction;
        });

        $outbox->group(fn () => Transaction::factory()->create(['user_id' => $user->id]));

        // A limit of one must return that group's BOTH rows, not one row: a
        // batch boundary must never cut a sale in half.
        $batch = $outbox->pendingGroups(1);

        $this->assertCount(1, $batch);
        $this->assertSame(2, $batch->first()->count());
        $this->assertSame($first->id, $batch->first()->first()->row_id);
    }

    public function test_a_failed_attempt_leaves_the_backlog_intact(): void
    {
        $user = User::factory()->cashier()->create();

        DB::table('sync_outbox')->truncate();

        Transaction::factory()->create(['user_id' => $user->id]);

        $sequences = SyncOutboxEntry::query()->pluck('sequence')->all();

        app(Outbox::class)->markFailed($sequences, 'connection refused');

        $entry = SyncOutboxEntry::query()->firstOrFail();

        $this->assertNull($entry->synced_at, 'A failed push must not consume the entry.');
        $this->assertSame(1, $entry->attempts);
        $this->assertSame('connection refused', $entry->last_error);
        $this->assertSame(1, app(Outbox::class)->unsyncedCount());
    }
}
