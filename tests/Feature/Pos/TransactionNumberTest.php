<?php

namespace Tests\Feature\Pos;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TransactionNumberTest extends TestCase
{
    use RefreshDatabase;

    private function sellable(int $stock): Variant
    {
        $variant = Variant::factory()->create(['selling_price' => 100]);
        app(StockLedger::class)->record(
            $variant, $stock, 'stock_adjustment', null, 'manual_adjustment', null,
            User::factory()->admin()->create(),
        );

        return $variant;
    }

    private function sell(Variant $variant, User $cashier): void
    {
        $this->actingAs($cashier)
            ->post(route('pos.checkout'), [
                'lines' => [['variant_id' => $variant->id, 'quantity' => 1]],
                'payment_type' => 'cashless',
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_numbers_are_a_continuous_zero_padded_serial()
    {
        $variant = $this->sellable(stock: 5);
        $cashier = User::factory()->cashier()->create();

        $this->sell($variant, $cashier);
        $this->sell($variant, $cashier);

        $numbers = Transaction::orderBy('created_at')->pluck('transaction_number')->all();

        // A gapless, zero-padded running serial (BIR-shaped: unique,
        // consecutive), prefixed by this branch's code (§12) — the prefix is
        // what makes two branches both counting from 1 unambiguous business-wide.
        foreach ($numbers as $number) {
            $this->assertMatchesRegularExpression('/^TST-\d{10}$/', $number);
        }
        $this->assertSame('TST-0000000001', $numbers[0]);
        $this->assertSame('TST-0000000002', $numbers[1]);
    }

    public function test_serial_does_not_reset_across_a_day_boundary()
    {
        $variant = $this->sellable(stock: 5);
        $cashier = User::factory()->cashier()->create();

        $this->travelTo(Carbon::parse('2026-07-12 09:00:00'));
        $this->sell($variant, $cashier);

        // A new calendar day must NOT restart the sequence — the series continues.
        $this->travelTo(Carbon::parse('2026-07-13 09:00:00'));
        $this->sell($variant, $cashier);

        $this->travelBack();

        $numbers = Transaction::orderBy('created_at')->pluck('transaction_number')->all();
        $this->assertSame('TST-0000000001', $numbers[0]);
        $this->assertSame('TST-0000000002', $numbers[1]);
    }
}
