<?php

namespace Tests\Feature\Sales;

use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleReturnTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A sellable variant with its on-hand seeded through the ledger (the app's
     * single write path), so snapshot and ledger stay consistent.
     */
    private function sellable(float $price, ?float $cost, int $stock): Variant
    {
        $variant = Variant::factory()->create(['selling_price' => $price, 'cost' => $cost]);

        if ($stock > 0) {
            app(StockLedger::class)->record(
                $variant, $stock, 'stock_adjustment', null, 'manual_adjustment', null,
                User::factory()->admin()->create(),
            );
        }

        return $variant;
    }

    /**
     * Make a completed sale through the real checkout path and return it, items
     * loaded. Each entry of $lines is [variant, quantity].
     *
     * @param  list<array{0: Variant, 1: int}>  $lines
     * @param  array<string, mixed>  $overrides
     */
    private function sell(array $lines, array $overrides = []): Transaction
    {
        $payload = array_merge([
            'lines' => array_map(fn ($line) => ['variant_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
            'payment_type' => 'cash',
            'cash_received' => 100000,
        ], $overrides);

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('pos.checkout'), $payload)
            ->assertSessionHasNoErrors();

        return Transaction::with('items')->latest('created_at')->firstOrFail();
    }

    private function stockOf(Variant $variant): int
    {
        return app(StockLedger::class)->currentStock($variant);
    }

    public function test_partial_return_restores_stock_and_refunds_at_original_price(): void
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 10);
        $transaction = $this->sell([[$variant, 3]]); // stock now 7
        $line = $transaction->items->first();

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('sales.returns.store', $transaction), [
                'type' => 'return',
                'refund_payment_type' => 'cash',
                'lines' => [['transaction_item_id' => $line->id, 'quantity' => 1]],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // Stock restored by 1 and a matching return ledger row appended.
        $this->assertSame(8, $this->stockOf($variant));

        $saleReturn = SaleReturn::sole();
        $this->assertSame('return', $saleReturn->type);
        $this->assertSame('100.00', $saleReturn->total_refund);

        $this->assertDatabaseHas('sale_return_items', [
            'sale_return_id' => $saleReturn->id,
            'transaction_item_id' => $line->id,
            'quantity' => 1,
            'refund_amount' => '100.00',
        ]);
        $this->assertDatabaseHas('inventory_ledger', [
            'variant_id' => $variant->id,
            'quantity' => 1,
            'type' => 'sale',
            'sale_type' => 'return',
            'reference_type' => 'return',
            'reference_id' => $saleReturn->id,
        ]);
    }

    public function test_cumulative_quota_blocks_returning_more_than_sold(): void
    {
        $variant = $this->sellable(price: 50, cost: 20, stock: 10);
        $transaction = $this->sell([[$variant, 3]]);
        $line = $transaction->items->first();
        $cashier = User::factory()->cashier()->create();

        // Return 2 of 3 — leaves 1 outstanding.
        $this->actingAs($cashier)
            ->post(route('sales.returns.store', $transaction), [
                'type' => 'return',
                'refund_payment_type' => 'cash',
                'lines' => [['transaction_item_id' => $line->id, 'quantity' => 2]],
            ])
            ->assertSessionHasNoErrors();

        // Returning 2 more would exceed the sold quantity (2 already + 2 > 3).
        $this->actingAs($cashier)
            ->post(route('sales.returns.store', $transaction), [
                'type' => 'return',
                'refund_payment_type' => 'cash',
                'lines' => [['transaction_item_id' => $line->id, 'quantity' => 2]],
            ])
            ->assertSessionHasErrors('lines');

        // Only the first event persisted; stock reflects only the 2 returned.
        $this->assertSame(1, SaleReturn::count());
        $this->assertSame(10 - 3 + 2, $this->stockOf($variant));
    }

    public function test_fully_returning_a_line_across_partials_refunds_exact_paid_share(): void
    {
        // Subtotal 300 with a ₱10 amount discount → total 290 (paid share of the
        // single line). Per-unit is uneven (290/3), so this exercises cent
        // reconciliation on the final unit.
        $variant = $this->sellable(price: 100, cost: 40, stock: 10);
        $transaction = $this->sell([[$variant, 3]], [
            'discount_type' => 'amount',
            'discount' => 10,
        ]);
        $this->assertSame('290.00', $transaction->total);
        $line = $transaction->items->first();
        $cashier = User::factory()->cashier()->create();

        foreach ([1, 1, 1] as $qty) {
            $this->actingAs($cashier)
                ->post(route('sales.returns.store', $transaction), [
                    'type' => 'return',
                    'refund_payment_type' => 'cash',
                    'lines' => [['transaction_item_id' => $line->id, 'quantity' => $qty]],
                ])
                ->assertSessionHasNoErrors();
        }

        // Cumulative refunds equal the line's paid share exactly — no cent drift.
        $totalRefunded = (float) SaleReturnItem::where('transaction_item_id', $line->id)->sum('refund_amount');
        $this->assertSame(290.0, round($totalRefunded, 2));

        // All three units back on the shelf.
        $this->assertSame(10, $this->stockOf($variant));
    }

    public function test_void_is_no_longer_an_accepted_event_type(): void
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 10);
        $transaction = $this->sell([[$variant, 3]]);
        $line = $transaction->items->first();

        // Void has been removed; a stale request naming it fails validation.
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('sales.returns.store', $transaction), [
                'type' => 'void',
                'refund_payment_type' => 'cash',
                'lines' => [['transaction_item_id' => $line->id, 'quantity' => 1]],
            ])
            ->assertSessionHasErrors('type');

        $this->assertSame(0, SaleReturn::count());
    }

    public function test_defect_writes_a_zero_movement_ledger_row_and_keeps_stock(): void
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 10);
        $transaction = $this->sell([[$variant, 5]]); // stock now 5
        $line = $transaction->items->first();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('sales.returns.store', $transaction), [
                'type' => 'defect',
                'lines' => [['transaction_item_id' => $line->id, 'quantity' => 2]],
            ])
            ->assertSessionHasNoErrors();

        // Defects do not restore stock.
        $this->assertSame(5, $this->stockOf($variant));

        $saleReturn = SaleReturn::sole();
        $this->assertSame('defect', $saleReturn->type);
        $this->assertNull($saleReturn->refund_payment_type);
        // The write-off value is still recorded (net-sales reduction for reports).
        $this->assertSame('200.00', $saleReturn->total_refund);

        $this->assertDatabaseHas('inventory_ledger', [
            'variant_id' => $variant->id,
            'quantity' => 0,
            'sale_type' => 'defect',
            'reference_type' => 'return',
            'reference_id' => $saleReturn->id,
        ]);
    }

    /**
     * A line of ANOTHER sale cannot be returned against this one.
     *
     * The guard is stated twice — `SaleReturnRequest` scopes the `exists` rule to
     * this transaction, and `ProcessSaleReturn::write()` re-checks it against the
     * loaded sold lines — and until now neither was covered: every other test in
     * this file reuses `$transaction->items->first()`, so both copies could have
     * been deleted with the suite still green. Without them a refund is paid at
     * one sale's captured price against another sale's quota.
     */
    public function test_a_line_belonging_to_another_sale_is_refused(): void
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 20);

        $mine = $this->sell([[$variant, 2]]);
        $theirs = $this->sell([[$variant, 2]]);

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('sales.returns.store', $mine), [
                'type' => 'return',
                'refund_payment_type' => 'cash',
                'lines' => [['transaction_item_id' => $theirs->items->first()->id, 'quantity' => 1]],
            ])
            ->assertSessionHasErrors('lines.0.transaction_item_id');

        $this->assertSame(0, SaleReturn::count());
        // Neither sale's stock moved.
        $this->assertSame(20 - 4, $this->stockOf($variant));
    }

    /**
     * Returns and defects consume ONE quota, not one each.
     *
     * The cumulative check sums `sale_return_items` for the line without
     * filtering on the event type, which is what makes "3 sold" mean 3 units of
     * after-sales activity in total. Filtering it to `type = 'return'` would look
     * harmless and would let a line be defected AND returned in full — a
     * write-off and a refund for the same unit. Every existing quota test chains
     * returns only, so that filter could have been added unnoticed.
     */
    public function test_defects_and_returns_share_one_quota(): void
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 10);
        $transaction = $this->sell([[$variant, 3]]); // stock now 7
        $line = $transaction->items->first();

        // Write off 2 of the 3 sold. Stock stays where it was.
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('sales.returns.store', $transaction), [
                'type' => 'defect',
                'lines' => [['transaction_item_id' => $line->id, 'quantity' => 2]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(7, $this->stockOf($variant));

        // Only one unit remains answerable, so returning 2 is over the quota.
        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('sales.returns.store', $transaction), [
                'type' => 'return',
                'refund_payment_type' => 'cash',
                'lines' => [['transaction_item_id' => $line->id, 'quantity' => 2]],
            ])
            ->assertSessionHasErrors('lines');

        $this->assertSame(1, SaleReturn::count());

        // The last unit is still returnable, and it restores stock.
        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('sales.returns.store', $transaction), [
                'type' => 'return',
                'refund_payment_type' => 'cash',
                'lines' => [['transaction_item_id' => $line->id, 'quantity' => 1]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(8, $this->stockOf($variant));
        $this->assertSame(3, (int) SaleReturnItem::query()->where('transaction_item_id', $line->id)->sum('quantity'));
    }

    public function test_cashier_may_return_but_not_defect(): void
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 10);
        $transaction = $this->sell([[$variant, 3]]);
        $line = $transaction->items->first();
        $cashier = User::factory()->cashier()->create();

        $this->actingAs($cashier)
            ->post(route('sales.returns.store', $transaction), [
                'type' => 'defect',
                'lines' => [['transaction_item_id' => $line->id, 'quantity' => 1]],
            ])
            ->assertForbidden();

        $this->assertSame(0, SaleReturn::count());

        // A return, however, is within reach.
        $this->actingAs($cashier)
            ->post(route('sales.returns.store', $transaction), [
                'type' => 'return',
                'refund_payment_type' => 'cash',
                'lines' => [['transaction_item_id' => $line->id, 'quantity' => 1]],
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_a_failing_line_rolls_back_the_whole_event(): void
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 10);
        $transaction = $this->sell([[$variant, 2]]);
        $line = $transaction->items->first();

        // One valid line and one over-quota line in the same request.
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('sales.returns.store', $transaction), [
                'type' => 'return',
                'refund_payment_type' => 'cash',
                'lines' => [
                    ['transaction_item_id' => $line->id, 'quantity' => 1],
                    ['transaction_item_id' => $line->id, 'quantity' => 5],
                ],
            ])
            ->assertSessionHasErrors('lines');

        // Nothing persisted — no header, no lines, no stock movement.
        $this->assertSame(0, SaleReturn::count());
        $this->assertSame(0, SaleReturnItem::count());
        $this->assertSame(8, $this->stockOf($variant));
    }

    public function test_return_cost_is_owner_only_in_the_detail_payload(): void
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 10);
        $transaction = $this->sell([[$variant, 3]]);
        $line = $transaction->items->first();

        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post(route('sales.returns.store', $transaction), [
                'type' => 'return',
                'refund_payment_type' => 'cash',
                'lines' => [['transaction_item_id' => $line->id, 'quantity' => 1]],
            ])
            ->assertSessionHasNoErrors();

        // Owner sees the captured cost on the return line.
        $this->actingAs($owner)
            ->get(route('sales.show', $transaction))
            ->assertInertia(fn ($page) => $page
                ->component('sales/show', false)
                ->where('canViewCost', true)
                ->where('transaction.returns.0.items.0.cost', '40.00'));

        // Admin does not — the key is absent.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('sales.show', $transaction))
            ->assertInertia(fn ($page) => $page
                ->component('sales/show', false)
                ->where('canViewCost', false)
                ->missing('transaction.returns.0.items.0.cost'));
    }
}
