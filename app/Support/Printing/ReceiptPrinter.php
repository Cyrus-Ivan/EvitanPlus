<?php

namespace App\Support\Printing;

use App\Models\Transaction;
use App\Support\Printing\Connectors\NetworkConnector;
use App\Support\Printing\Connectors\RawDeviceConnector;
use App\Support\Printing\Connectors\SystemPrinterConnector;
use App\Support\Printing\Contracts\PrinterConnector;
use App\Support\Settings\BusinessSettings;

/**
 * The single entry point for putting a receipt on paper (Phase 11).
 *
 * Builds the receipt bytes and writes them to the configured driverless ESC/POS
 * printer (USB/Bluetooth device, OS print queue or network socket). Returns
 * false when no printer is configured (so the caller can stay quiet rather than
 * error) and throws on a real device failure — callers wrap this in try/catch so
 * a print problem never rolls back a committed sale.
 *
 * ## Catch `\Throwable`, not {@see PrinterException}
 *
 * The connectors raise `PrinterException` for faults they diagnose themselves,
 * but {@see SystemPrinterConnector} shells out to the platform's spooler through
 * `Process::timeout(15)`, and a queue that accepts the job and then stalls raises
 * Symfony's `ProcessTimedOutException` — a plain `RuntimeException` this package
 * never sees and cannot wrap without swallowing the distinction. Two of the four
 * call sites caught the narrow type and turned the commonest real printer fault
 * into a 500; all four catch `\Throwable` now.
 */
class ReceiptPrinter
{
    public function __construct(
        private readonly ReceiptContent $content,
        private readonly EscPosReceiptBuilder $builder,
        private readonly PrinterSettings $settings,
        private readonly BusinessSettings $business,
    ) {}

    /**
     * Print (or reprint) the receipt for a sale.
     *
     * @return bool true when sent to the printer, false when no printer is
     *              configured.
     *
     * @throws PrinterException on a device failure the connector diagnosed. A
     *                          stalled `system` spooler raises Symfony's
     *                          `ProcessTimedOutException` instead — catch
     *                          `\Throwable`, per the class docblock.
     */
    public function print(Transaction $transaction, bool $isReprint = false): bool
    {
        if (! $this->settings->isConfigured()) {
            return false;
        }

        $data = $this->content->forTransaction($transaction, $isReprint);
        $bytes = $this->builder->build($data, $this->settings->openCashDrawer());

        $this->connector()->write($bytes);

        return true;
    }

    /**
     * Print a short test slip so the owner can verify the printer (and drawer).
     *
     * @return bool false when no printer is configured.
     *
     * @throws PrinterException on a device failure the connector diagnosed; see
     *                          {@see self::print()} for what else can escape.
     */
    public function test(): bool
    {
        if (! $this->settings->isConfigured()) {
            return false;
        }

        $bytes = $this->builder->build($this->testData(), $this->settings->openCashDrawer());
        $this->connector()->write($bytes);

        return true;
    }

    /**
     * The transport for the configured connection type.
     */
    private function connector(): PrinterConnector
    {
        return match ($this->settings->connection()) {
            PrinterSettings::CONNECTION_SYSTEM => new SystemPrinterConnector(
                $this->settings->printerName() ?? '',
            ),
            PrinterSettings::CONNECTION_NETWORK => new NetworkConnector(
                $this->settings->networkHost() ?? '',
                $this->settings->networkPort(),
            ),
            default => new RawDeviceConnector($this->settings->devicePath() ?? ''),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function testData(): array
    {
        $business = $this->business->all();
        $now = now()->timezone(config('app.timezone'))->format('Y-m-d h:i A');

        return [
            'paper_size' => $this->settings->paperSize(),
            'is_reprint' => false,
            'business' => [
                'name' => $business['business_name'] ?? '',
                'address' => $business['business_address'] ?? '',
                'tin' => $business['tin'] ?? '',
            ],
            'footer' => 'This is a printer test.',
            'currency_symbol' => $business['currency_symbol'] ?? '₱',
            'number' => 'TEST',
            'datetime' => $now,
            'cashier' => '-',
            'payment_label' => 'Test',
            'comment' => null,
            'items' => [
                ['label' => 'Printer test line', 'quantity' => 1, 'unit_price' => '0.00', 'line_total' => '0.00'],
            ],
            'subtotal' => '0.00',
            'discount_display' => null,
            'discount_amount' => '0.00',
            'total' => '0.00',
            'cash_received' => null,
            'change' => null,
        ];
    }
}
