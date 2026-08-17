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

        // Staggered returns of a 90.05 share over three units. Each event pays
        // the cumulative share owed so far less what the line has already had, so
        // the odd centavo falls where the rounding puts it rather than being
        // dumped on the final unit — 3002, then 6003 − 3002, then 9005 − 6003.
        // What matters is that no prefix ever exceeds the share and the set sums
        // to it exactly; see fixture E for why the per-event form is unsafe.
        $this->assertSame('30.02', $this->returnUnits($transaction, $lineA->id, 1)->total_refund);
        $this->assertSame('30.01', $this->returnUnits($transaction, $lineA->id, 1)->total_refund);
        $this->assertSame('30.02', $this->returnUnits($transaction, $lineA->id, 1)->total_refund);

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
     * Fixture E — the paid share is SMALLER than the units sold.
     *
     * Six units at ₱1.00 discounted 99.5% leaves a line paid share of 3 centavos
     * across 6 units, so a single unit's share is half a centavo. This is the
     * only shape in which the refund apportionment can drift the wrong way: a
     * per-unit `round()` that ignores what has already been refunded rounds every
     * half-centavo UP, and five single-unit returns can pay out more than the
     * line was ever worth — leaving the true-up on the final unit to hand back a
     * NEGATIVE refund, i.e. an instruction to take money from the customer.
     *
     * The rules, in order of importance: no refund is ever negative, the running
     * total never exceeds the paid share, and the completed set sums to it
     * exactly. A unit whose share rounds to nothing refunds nothing, which is the
     * honest answer — it is what the customer paid for it.
     */
    public function test_fixture_e_paid_share_smaller_than_units_never_over_refunds(): void
    {
        $a = $this->sellable(price: 1.00, cost: null, stock: 10);

        $transaction = $this->checkout([[$a, 6]], 'percentage', 99.5);

        // 600 centavos less a 597-centavo discount.
        $this->assertSame('0.03', $transaction->total);

        $line = $transaction->items->sole();

        $refunds = [];
        $running = 0;

        for ($unit = 1; $unit <= 6; $unit++) {
            $refund = $this->returnUnits($transaction, $line->id, 1)->total_refund;
            $refunds[] = $refund;

            $running += (int) round((float) $refund * 100);

            $this->assertGreaterThanOrEqual(
                0,
                (float) $refund,
                "Unit {$unit} refunded {$refund} — a negative refund tells the cashier to take money back.",
            );
            $this->assertLessThanOrEqual(
                3,
                $running,
                "After unit {$unit} the line had refunded {$running} centavos of a 3-centavo share.",
            );
        }

        // The whole line came back, so its refunds sum to exactly what was paid.
        $this->assertSame(3, $running, 'Refunds for a fully returned line must equal its paid share.');

        $this->assertSame(['0.01', '0.00', '0.01', '0.00', '0.01', '0.00'], $refunds);

        $this->assertReport([
            'gross_sales' => '0.03',
            'returns' => '0.03',
            'net_sales' => '0.00',
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
