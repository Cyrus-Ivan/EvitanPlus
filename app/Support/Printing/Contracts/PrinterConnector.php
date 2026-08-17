<?php

namespace App\Support\Printing\Contracts;

use App\Support\Printing\EscPosReceiptBuilder;
use App\Support\Printing\PrinterException;
use App\Support\Printing\ReceiptPrinter;

/**
 * A transport that delivers already-built ESC/POS bytes to a printer. The one
 * responsibility is "get these bytes to the device"; byte generation lives in
 * {@see EscPosReceiptBuilder}.
 *
 * Implementations exist for a raw device file (USB/Bluetooth), the OS print
 * queue and a network socket. Tests bind an in-memory implementation. This seam
 * is also where a future libusb transport would slot in.
 *
 * **Every implementation bounds its write.** The network connector sets 5s, the
 * system connector 15s on the spooler process, and the raw device connector uses
 * a non-blocking write loop with a deadline — because a printer that is powered
 * on with a full buffer blocks `fwrite` forever, and for one release that is
 * exactly what it did.
 */
interface PrinterConnector
{
    /**
     * Write the given bytes to the printer.
     *
     * @throws PrinterException when the device cannot be opened or written to.
     *                          Not the only thing callers must expect: the system
     *                          connector's spooler timeout arrives as Symfony's
     *                          `ProcessTimedOutException`. Catch `\Throwable` at
     *                          the call site — see {@see ReceiptPrinter}.
     */
    public function write(string $bytes): void;
}
