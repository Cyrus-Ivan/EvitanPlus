<?php

namespace Tests\Feature\Sales;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesHistoryTest extends TestCase
{
    use RefreshDatabase;

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
     * Ring up a sale through the real checkout so history reflects the write path.
     *
     * @param  array<int, array{variant_id: string, quantity: int}>  $lines
     */
    private function sell(User $cashier, array $lines, string $paymentType = 'cashless'): void
    {
        $this->actingAs($cashier)
            ->post(route('pos.checkout'), [
                'lines' => $lines,
                'payment_type' => $paymentType,
                'cash_received' => $paymentType === 'cash' ? 100000 : null,
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_index_lists_sales_newest_first()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 20);
        $cashier = User::factory()->cashier()->create();

        $this->sell($cashier, [['variant_id' => $variant->id, 'quantity' => 1]]);
        $this->sell($cashier, [['variant_id' => $variant->id, 'quantity' => 2]]);

        $response = $this->actingAs($cashier)->get(route('sales'));

        $response->assertOk();
        // shouldExist: false — the project resolves pages via Vite at runtime, so
        // the testing view-finder's page_paths does not point at the real dir.
        $response->assertInertia(fn ($page) => $page
            ->component('sales', false)
            ->has('transactions.data', 2)
            // Newest first: the second sale (serial 0000000002) leads.
            ->where('transactions.data.0.transaction_number', 'TST-0000000002')
            ->where('transactions.data.0.items_count', 1)
            ->where('transactions.data.1.transaction_number', 'TST-0000000001'));
    }

    public function test_a_cashier_sees_sales_rung_up_by_others()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 20);
        $alice = User::factory()->cashier()->create();
        $bob = User::factory()->cashier()->create();

        $this->sell($alice, [['variant_id' => $variant->id, 'quantity' => 1]]);

        // Bob (a different cashier) can still see Alice's sale.
        $this->actingAs($bob)
            ->get(route('sales'))
            ->assertInertia(fn ($page) => $page->has('transactions.data', 1));
    }

    public function test_filters_narrow_the_list()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 20);
        $cashier = User::factory()->cashier()->create();

        $this->sell($cashier, [['variant_id' => $variant->id, 'quantity' => 1]], 'cash');
        $this->sell($cashier, [['variant_id' => $variant->id, 'quantity' => 1]], 'cashless');

        // Payment-type filter.
        $this->actingAs($cashier)
            ->get(route('sales', ['payment_type' => 'cash']))
            ->assertInertia(fn ($page) => $page
                ->has('transactions.data', 1)
                ->where('transactions.data.0.payment_type', 'cash'));

        // Search by receipt number.
        $this->actingAs($cashier)
            ->get(route('sales', ['search' => 'TST-0000000001']))
            ->assertInertia(fn ($page) => $page
                ->has('transactions.data', 1)
                ->where('transactions.data.0.transaction_number', 'TST-0000000001'));
    }

    public function test_show_returns_line_items_with_snapshotted_prices()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 20);
        $cashier = User::factory()->cashier()->create();
        $this->sell($cashier, [['variant_id' => $variant->id, 'quantity' => 3]]);

        $transaction = Transaction::sole();

        $this->actingAs($cashier)
            ->get(route('sales.show', $transaction->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('sales/show', false)
                ->where('transaction.transaction_number', 'TST-0000000001')
                ->has('transaction.items', 1)
                ->where('transaction.items.0.quantity', 3)
                ->where('transaction.items.0.selling_price', '100.00')
                ->where('transaction.items.0.total', '300.00'));
    }

    public function test_cost_is_visible_to_the_owner_only()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 20);
        $cashier = User::factory()->cashier()->create();
        $this->sell($cashier, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $transaction = Transaction::sole();

        // Owner sees per-line cost.
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('sales.show', $transaction->id))
            ->assertInertia(fn ($page) => $page
                ->where('canViewCost', true)
                ->where('transaction.items.0.cost', '40.00'));

        // Admin and Cashier never see cost — the key is absent.
        foreach ([User::factory()->admin()->create(), $cashier] as $viewer) {
            $this->actingAs($viewer)
                ->get(route('sales.show', $transaction->id))
                ->assertInertia(fn ($page) => $page
                    ->where('canViewCost', false)
                    ->missing('transaction.items.0.cost'));
        }
    }
}
