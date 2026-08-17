<?php

namespace Tests\Feature\Sales;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use App\Support\Printing\PrinterSettings;
use App\Support\Printing\ReceiptPrinter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReprintTest extends TestCase
{
    use RefreshDatabase;

    private ?string $deviceFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        app(PrinterSettings::class)->update(['auto_print' => '0']);
    }

    protected function tearDown(): void
    {
        if ($this->deviceFile !== null && file_exists($this->deviceFile)) {
            @unlink($this->deviceFile);
        }

        parent::tearDown();
    }

    private function useTempDevice(): string
    {
        $this->deviceFile = tempnam(sys_get_temp_dir(), 'rcpt');

        app(PrinterSettings::class)->update([
            'receipt_connection' => 'usb',
            'receipt_device_path' => $this->deviceFile,
        ]);

        return $this->deviceFile;
    }

    private function makeSale(): Transaction
    {
        $variant = Variant::factory()->create(['selling_price' => 100, 'cost' => 40]);
        app(StockLedger::class)->record(
            $variant, 10, 'stock_adjustment', null, 'manual_adjustment', null,
            User::factory()->admin()->create(),
        );

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('pos.checkout'), [
                'lines' => [['variant_id' => $variant->id, 'quantity' => 1]],
                'payment_type' => 'cash',
                'cash_received' => 100000,
            ])->assertSessionHasNoErrors();

        return Transaction::latest('created_at')->firstOrFail();
    }

    public function test_a_cashier_can_reprint_a_receipt(): void
    {
        $device = $this->useTempDevice();
        $transaction = $this->makeSale();

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('sales.print', $transaction))
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'success');

        $this->assertStringContainsString('*** REPRINT ***', file_get_contents($device));
    }

    public function test_a_guest_cannot_reprint(): void
    {
        $transaction = $this->makeSale();

        // makeSale() authenticated a cashier (cached on the guard); drop it so
        // the next request is a genuine guest.
        $this->app['auth']->forgetGuards();

        $this->post(route('sales.print', $transaction))
            ->assertRedirect(route('login'));
    }

    public function test_reprint_reports_when_no_printer_is_configured(): void
    {
        app(PrinterSettings::class)->update([
            'receipt_connection' => 'usb',
            'receipt_device_path' => '',
        ]);
        $transaction = $this->makeSale();

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('sales.print', $transaction))
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'error');
    }

    public function test_reprint_reports_a_device_failure_without_a_server_error(): void
    {
        app(PrinterSettings::class)->update([
            'receipt_connection' => 'usb',
            'receipt_device_path' => '/dev/does-not-exist-'.uniqid(),
        ]);
        $transaction = $this->makeSale();

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('sales.print', $transaction))
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'error');
    }

    /**
     * A printer failure that is not a `PrinterException` still toasts.
     *
     * The two tests above both drive the RAW DEVICE connector, which fails in the
     * app's own typed exception — so the `catch (PrinterException)` in
     * `SalesController::print()` looked sufficient. It is not: the `system`
     * connection runs `Process::timeout(15)`, and a CUPS queue that accepts the
     * job and then stalls (out of paper, printer paused) throws Laravel's
     * `ProcessTimedOutException`, a plain `RuntimeException`. That escaped to a
     * 500 error page on the exact failure the toast exists for.
     *
     * Checkout already catches `\Throwable` here (a print may never take a sale
     * down with it); this pins the reprint path to the same rule.
     */
    public function test_reprint_survives_a_printer_failure_that_is_not_a_printer_exception(): void
    {
        $transaction = $this->makeSale();

        $printer = Mockery::mock(ReceiptPrinter::class);
        $printer->shouldReceive('print')
            ->once()
            ->andThrow(new ProcessTimedOutException(
                Process::fromShellCommandline('lp')->setTimeout(15),
                ProcessTimedOutException::TYPE_GENERAL,
            ));
        $this->app->instance(ReceiptPrinter::class, $printer);

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('sales.print', $transaction))
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'error');
    }
}
