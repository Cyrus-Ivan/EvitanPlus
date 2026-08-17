<?php

namespace App\Support\Printing\Connectors;

use App\Support\Printing\Contracts\PrinterConnector;
use App\Support\Printing\PrinterException;

/**
 * Writes raw ESC/POS bytes straight to a character device — the driverless path
 * for USB (`/dev/usb/lp0`, exposed by the kernel `usblp` module) and paired
 * Bluetooth (`/dev/rfcomm0`, after `rfcomm bind`) thermal printers on Linux.
 *
 * No CUPS/driver is involved: the app's PHP process opens the device and writes
 * to it. The process therefore needs write access to the node (membership of
 * the `lp`/`dialout` group or a udev rule) — documented in
 * docs/receipt-printing-design.md.
 *
 * ## Why the write is bounded
 *
 * This is the DEFAULT connection (`PrinterSettings::DEFAULTS['receipt_connection']`
 * is `usb`), and it was the only one of the three with no timeout at all —
 * {@see NetworkConnector} has 5s, {@see SystemPrinterConnector} 15s. A thermal
 * printer that is powered on but cannot consume bytes — out of paper, cover
 * open, paused, buffer full — fills its kernel buffer and then parks a blocking
 * `fwrite()` for as long as it stays that way, which is to say until somebody
 * notices. That takes the PHP worker and the cashier's screen with it.
 *
 * The sale is never at risk (printing happens strictly after the checkout
 * transaction commits, and a print failure may never roll one back), so the only
 * thing at stake is the wedge — which is why giving up and saying so is
 * unambiguously better than waiting.
 *
 * The OPEN is deliberately not bounded: `fopen` on a character device returns as
 * soon as the kernel has the node, and there is no portable timeout for it
 * anyway. What blocks on a real printer is the write.
 */
class RawDeviceConnector implements PrinterConnector
{
    /** Seconds to keep offering bytes to a device that is accepting none. */
    private const TIMEOUT_SECONDS = 15.0;

    /** How long one wait-for-writable may park, so the deadline stays checkable. */
    private const POLL_MICROSECONDS = 200_000;

    public function __construct(
        private readonly string $devicePath,
        private readonly float $timeout = self::TIMEOUT_SECONDS,
    ) {}

    public function write(string $bytes): void
    {
        if (! file_exists($this->devicePath)) {
            throw new PrinterException("Printer device {$this->devicePath} was not found. Check that the printer is connected.");
        }

        // Suppress the native warning so a permission/IO problem surfaces as our
        // typed exception rather than leaking a PHP warning to the response.
        $handle = @fopen($this->devicePath, 'wb');

        if ($handle === false) {
            throw new PrinterException("Could not open printer device {$this->devicePath}. Check permissions (the app needs write access to the device).");
        }

        try {
            // The whole point: a full device now returns 0 from fwrite instead of
            // parking the process in an uninterruptible wait.
            stream_set_blocking($handle, false);

            $deadline = microtime(true) + $this->timeout;
            $remaining = $bytes;

            while ($remaining !== '') {
                $written = @fwrite($handle, $remaining);

                if ($written === false) {
                    throw new PrinterException("Failed to send the receipt to {$this->devicePath}.");
                }

                if ($written > 0) {
                    // A short write is normal on a non-blocking stream and is not
                    // an error — carry on with what is left. (The blocking version
                    // this replaces treated one as a failure.)
                    $remaining = substr($remaining, $written);

                    continue;
                }

                if (microtime(true) >= $deadline) {
                    throw new PrinterException(
                        "The printer at {$this->devicePath} stopped accepting data. Check for paper, and that the cover is closed and the printer is not paused."
                    );
                }

                // Wait for the device to drain rather than spinning on it. Capped
                // well below the timeout so the deadline above stays the authority.
                $write = [$handle];
                $read = [];
                $except = [];
                @stream_select($read, $write, $except, 0, self::POLL_MICROSECONDS);
            }

            @fflush($handle);
        } finally {
            @fclose($handle);
        }
    }
}
