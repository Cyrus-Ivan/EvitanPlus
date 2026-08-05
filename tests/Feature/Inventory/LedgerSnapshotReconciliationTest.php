<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryLedger;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Inventory\Concerns\FakesThePendingQueue;
use Tests\TestCase;

/**
 * Phase 18 S5 — "Current stock = ledger sum reconciled against a stock
 * snapshot" (CLAUDE.md). After every flow that moves stock — adjustment, sale,
 * return, defect, CSV import — the snapshot must equal the ledger sum exactly,
 * and StockLedger::reconcile() must agree.
 */
class LedgerSnapshotReconciliationTest extends TestCase
{
    use FakesThePendingQueue, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Adjustments and imports ASK for stock since schema 21; the confirming
        // is what writes the ledger row this file is about. The stand-in is what
        // makes that step reachable without a Postgres.
        $this->fakeBusinessQueue();
    }

    private function assertReconciled(Variant $variant, int $expected): void
    {
        $ledgerSum = (int) InventoryLedger::where('variant_id', $variant->id)->sum('quantity');
        $snapshot = app(StockLedger::class)->currentStock($variant);

        $this->assertSame($expected, $snapshot, 'snapshot disagrees with the expected on-hand');
        $this->assertSame($ledgerSum, $snapshot, 'snapshot has drifted from the ledger sum');
        $this->assertSame($expected, app(StockLedger::class)->reconcile($variant), 'reconcile() disagrees');
    }

    public function test_snapshot_equals_ledger_sum_across_every_flow(): void
    {
        $admin = User::factory()->admin()->create();
        $variant = Variant::factory()->create(['selling_price' => 50, 'cost' => 10]);

        // Manual adjustment (set to 10) through the endpoint.
        $this->actingAs($admin)
            ->post(route('stock.adjust', $variant), ['mode' => 'set', 'value' => 10, 'notes' => 'seed'])
            ->assertSessionHasNoErrors();
        $this->approveEverythingPending($admin);
        $this->assertReconciled($variant, 10);

        // Sale of 4.
        $this->actingAs($admin)->post(route('pos.checkout'), [
            'lines' => [['variant_id' => $variant->id, 'quantity' => 4]],
            'payment_type' => 'cash',
            'cash_received' => 1000,
        ])->assertSessionHasNoErrors();
        $this->assertReconciled($variant, 6);

        $transaction = Transaction::with('items')->sole();
        $line = $transaction->items->sole();

        // Return of 1: restores stock.
        $this->actingAs($admin)->post(route('sales.returns.store', $transaction), [
            'type' => 'return',
            'refund_payment_type' => 'cash',
            'lines' => [['transaction_item_id' => $line->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();
        $this->assertReconciled($variant, 7);

        // Defect of 1: zero-movement ledger row, stock unchanged.
        $this->actingAs($admin)->post(route('sales.returns.store', $transaction), [
            'type' => 'defect',
            'lines' => [['transaction_item_id' => $line->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();
        $this->assertReconciled($variant, 7);

        // Delta adjustment (-2).
        $this->actingAs($admin)
            ->post(route('stock.adjust', $variant), ['mode' => 'delta', 'value' => -2, 'notes' => 'shrinkage'])
            ->assertSessionHasNoErrors();
        $this->approveEverythingPending($admin);
        $this->assertReconciled($variant, 5);
    }

    public function test_snapshot_equals_ledger_sum_after_csv_import(): void
    {
        $owner = User::factory()->owner()->create();
        $variant = Variant::factory()->create(['sku' => 'SKU-REC-1']);

        app(StockLedger::class)->record(
            $variant, 3, 'stock_adjustment', null, 'manual_adjustment', null, $owner,
        );

        $item = $variant->item;

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['category', 'product', 'variant', 'sku', 'barcode', 'selling_price', 'cost', 'stock', 'unit', 'description', 'low_stock_threshold', 'mode']);
        fputcsv($handle, [
            $item->category?->name ?? '',
            $item->name,
            $variant->name ?? '',
            'SKU-REC-1',
            $variant->barcode ?? '',
            '25.00',
            '',
            '9',
            $item->unit,
            $item->description ?? '',
            '',
            'replace_stock_count',
        ]);
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        $path = tempnam(sys_get_temp_dir(), 'inv').'.csv';
        file_put_contents($path, $content);

        $response = $this->actingAs($owner)
            ->post(route('inventory.import.store'), [
                'file' => new UploadedFile($path, 'inventory.csv', 'text/csv', null, true),
            ]);

        $response->assertSessionHasNoErrors();

        $this->approveEverythingPending($owner);

        $this->assertReconciled($variant->fresh(), 9);
    }
}
