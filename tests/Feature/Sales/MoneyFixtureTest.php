<?php

namespace Tests\Feature\Sales;

use App\Models\SaleReturn;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 18 S5 — the hand-verified adversarial money fixtures from the S3
 * accuracy review (docs/phase-18-findings.md, "S3 appendix — money fixtures").
 * Every amount is asserted cent-exact end-to-end: checkout stores the exact
 * totals, returns refund the exact discount-apportioned shares, and the report
 * recomputes the same numbers from source tables.
 */
class MoneyFixtureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the clock so every sale, return and report bucket lands in the
        // same daily bucket (2026-03-10 12).
        Carbon::setTestNow('2026-03-10 12:00:00');
    }

    private function sellable(float $price, ?float $cost, int $stock): Variant
    {
        $variant = Variant::factory()->create(['selling_price' => $price, 'cost' => $cost]);

        app(StockLedger::class)->record(
            $variant, $stock, 'stock_adjustment', null, 'manual_adjustment', null,
            User::factory()->admin()->create(),
        );

        return $variant;
    }

    /** @param list<array{0: Variant, 1: int}> $lines */
    private function checkout(array $lines, ?string $discountType = null, float $discount = 0): Transaction
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('pos.checkout'), [
                'lines' => array_map(fn (array $line) => [
                    'variant_id' => $line[0]->id,
                    'quantity' => $line[1],
                ], $lines),
                'payment_type' => 'cash',
                'cash_received' => 100000,
                'discount_type' => $discountType,
                'discount' => $discount,
            ])
            ->assertSessionHasNoErrors();

        return Transaction::with('items')->latest('created_at')->firstOrFail();
    }

    private function returnUnits(Transaction $transaction, string $transactionItemId, int $quantity): SaleReturn
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('sales.returns.store', $transaction), [
                'type' => 'return',
                'refund_payment_type' => 'cash',
                'lines' => [['transaction_item_id' => $transactionItemId, 'quantity' => $quantity]],
            ])
            ->assertSessionHasNoErrors();

        return SaleReturn::query()->latest('created_at')->orderByDesc('return_number')->firstOrFail();
    }

    /** @param array<string, string> $expectations */
    private function assertReport(array $expectations): void
    {
        $response = $this->actingAs(User::factory()->owner()->create())
            ->get(route('reports', ['period' => 'daily', 'date' => '2026-03-10']))
            ->assertOk();

        $response->assertInertia(function ($page) use ($expectations) {
            $page->component('reports', false)->has('rows', 1)->where('rows.0.bucket', '2026-03-10 12');

            foreach ($expectations as $key => $value) {
                $page->where("rows.0.{$key}", $value);
            }

            return $page;
        });
    }

    /**
     * Fixture A — 10% discount over three unequal lines, staggered single-unit
     * returns of the first line. Shares: 9005 + 8999 + 9000 = 27004 centavos.
     */
    public function test_fixture_a_percentage_discount_with_staggered_returns(): void
    {
        $a = $this->sellable(price: 33.35, cost: 10.00, stock: 10);
        $b = $this->sellable(price: 99.99, cost: 20.00, stock: 10);
        $c = $this->sellable(price: 50.00, cost: 15.00, stock: 10);

        $transaction = $this->checkout([[$a, 3], [$b, 1], [$c, 2]], 'percentage', 10);

        $this->assertSame('270.04', $transaction->total);
        $this->assertSame('10.00', $transaction->discount);
        $this->assertSame('percentage', $transaction->discount_type);

        $lineA = $transaction->items->firstWhere('variant_id', $a->id);

        // Staggered returns: 30.02, 30.02, then the final unit trues up to 30.01
        // so the cumulative refund equals the line's paid share of 90.05 exactly.
        $this->assertSame('30.02', $this->returnUnits($transaction, $lineA->id, 1)->total_refund);
        $this->assertSame('30.02', $this->returnUnits($transaction, $lineA->id, 1)->total_refund);
        $this->assertSame('30.01', $this->returnUnits($transaction, $lineA->id, 1)->total_refund);

        $this->assertReport([
            'gross_sales' => '270.04',
            'returns' => '90.05',
            'defects' => '0.00',
            'net_sales' => '179.99',
            // COGS (not reported, but it is what profit is net of): sold
            // 30.00 + 20.00 + 30.00, credited back 30.00 for the returned units
            // → 50.00; profit = 179.99 − 50.00.
            'profit' => '129.99',
            'uncosted_sales' => '0.00',
        ]);
    }

    /** Fixture B — the float classic: 0.10 + 0.20 must be exactly 0.30. */
    public function test_fixture_b_float_classic_sums_exactly(): void
    {
        $a = $this->sellable(price: 0.10, cost: null, stock: 5);
        $b = $this->sellable(price: 0.20, cost: null, stock: 5);

        $transaction = $this->checkout([[$a, 1], [$b, 1]]);

        $this->assertSame('0.30', $transaction->total);

        $this->assertReport([
            'gross_sales' => '0.30',
            'net_sales' => '0.30',
            // Both lines are un-costed: excluded from profit, reported apart.
            'profit' => '0.00',
            'uncosted_sales' => '0.30',
        ]);
    }

    /** Fixture C — 33.33% of 0.99 rounds to 0.33; refunds split 22+22+22. */
    public function test_fixture_c_sub_cent_percentage_discount(): void
    {
        $a = $this->sellable(price: 0.33, cost: 0.10, stock: 10);

        $transaction = $this->checkout([[$a, 3]], 'percentage', 33.33);

        $this->assertSame('0.66', $transaction->total);

        $line = $transaction->items->sole();

        $this->assertSame('0.22', $this->returnUnits($transaction, $line->id, 1)->total_refund);
        $this->assertSame('0.22', $this->returnUnits($transaction, $line->id, 1)->total_refund);
        $this->assertSame('0.22', $this->returnUnits($transaction, $line->id, 1)->total_refund);

        $this->assertReport([
            'gross_sales' => '0.66',
            'returns' => '0.66',
            'net_sales' => '0.00',
            // All three units returned: their cost is credited back in full,
            // so profit nets to zero.
            'profit' => '0.00',
        ]);
    }

    /**
     * Fixture D — ₱100.00 amount discount over ₱300.05: shares 6667 + 6667 +
     * 6671 = 20005 centavos; the full return of line C refunds its exact share.
     */
    public function test_fixture_d_amount_discount_with_full_line_return(): void
    {
        $a = $this->sellable(price: 100.00, cost: null, stock: 5);
        $b = $this->sellable(price: 100.00, cost: null, stock: 5);
        $c = $this->sellable(price: 100.05, cost: null, stock: 5);

        $transaction = $this->checkout([[$a, 1], [$b, 1], [$c, 1]], 'amount', 100.00);

        $this->assertSame('200.05', $transaction->total);
        $this->assertSame('100.00', $transaction->discount);

        $lineC = $transaction->items->firstWhere('variant_id', $c->id);

        $this->assertSame('66.71', $this->returnUnits($transaction, $lineC->id, 1)->total_refund);

        $this->assertReport([
            'gross_sales' => '200.05',
            'returns' => '66.71',
            'net_sales' => '133.34',
        ]);
    }
}
