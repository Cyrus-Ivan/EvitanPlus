# Receipt printing — design of record (Phase 11)

This is the authoritative design for how Evitan Plus prints receipts and opens the
cash drawer. Where `BRAINSTORM.md`/`ROADMAP.md` and this document disagree on
printing behaviour, this document wins.

## Target & scope

Evitan Plus **ships for Linux** (AppImage + deb); the design is written to be
platform-agnostic and macOS and Windows builds are future work (`ROADMAP.md`,
`RELEASE-NOTES.md` "Known limitations"). The printer is attached to the individual
till, so this design is per-device regardless of how many branches exist. The
supported printer is an **ESC/POS thermal printer** connected by **USB, Bluetooth
or the network**. In every case the app generates the *same* ESC/POS byte stream
in PHP; only the transport that carries those bytes to the printer differs.

NativePHP runs a real host PHP process, so PHP delivers the bytes itself. There is
still **no HTML/`System::print`** path (that route rasterises through a driver and
cannot carry raw ESC/POS) and **no native Node modules**. Everything is pure PHP
plus, on the OS-queue path, a shell-out to the platform's built-in raw-print tool —
so printing runs and is testable under plain `php artisan serve` **and** the
packaged desktop app.

### Transports (`app/Support/Printing/Connectors/`)

| Connection  | How it reaches the printer | Typical target |
|-------------|----------------------------|----------------|
| `system`    | raw ESC/POS through the **OS print queue** (`SystemPrinterConnector`) | a printer installed in the OS, chosen by name |
| `usb`       | write bytes to a device file (`RawDeviceConnector`) | Linux `/dev/usb/lp0` (kernel `usblp`) |
| `bluetooth` | write bytes to a device file (`RawDeviceConnector`) | Linux `/dev/rfcomm0` (after `rfcomm bind`), macOS `/dev/cu.*` |
| `network`   | TCP socket (`NetworkConnector`) | `ip:9100` (RAW/JetDirect) |

All carry the **same** ESC/POS byte stream.

`system` is the **recommended transport on macOS and Windows**, where a USB or
Bluetooth thermal printer is enrolled as an OS print queue rather than exposed as a
device file. `SystemPrinterConnector` picks the platform's raw-passthrough tool:

- **macOS / Linux (CUPS):** `lp -d "<queue>" -o raw`, bytes on stdin (`-o raw`
  disables driver filtering so the ESC/POS passes through untouched).
- **Windows (spooler):** a bundled PowerShell helper
  (`resources/printing/windows-raw-print.ps1`) that P/Invokes the winspool RAW API
  (`OpenPrinter` → `StartDocPrinter` with `pDatatype="RAW"` → `WritePrinter`).

The queue name is what `Native\Laravel\Facades\System::printers()` reports, so the
owner picks it from a detected list in Business settings (falling back to typing the
exact name when discovery is unavailable, e.g. under `artisan serve`).

### Deferred (not built; seam left open)

The `PrinterConnector` interface remains the extension point for:

- **libusb/WinUSB** for a raw-USB printer that exposes **no** device file **and** is
  not enrolled as an OS print queue. This needs a native module. If a future customer
  has such a printer, add a connector; nothing else changes.

## Components (`app/Support/Printing/`)

- `PrinterSettings` — key/value settings (mirrors `BusinessSettings`; same
  `settings` table, no migration). Keys + defaults: `receipt_paper_size` (`58mm`),
  `receipt_connection` (`usb`), `receipt_printer_name` (`''`, the OS queue name for
  the `system` connection), `receipt_device_path` (`/dev/usb/lp0`),
  `receipt_network_host` (`''`), `receipt_network_port` (`9100`), `auto_print`
  (`1`), `open_cash_drawer` (`0`). Booleans stored as `'1'`/`'0'`. `isConfigured()`
  requires a printer name for `system`, a host for `network`, else a device path.
- `ReceiptContent` — builds the plain receipt data array from a `Transaction` +
  `BusinessSettings` + `PrinterSettings`. **Single source of truth for what a
  receipt says.** Derives subtotal from the line totals (not stored) and the
  discount as `subtotal − total`. **Never includes item cost** — receipts are
  customer-facing.
- `EscPosReceiptBuilder` — turns that array into ESC/POS bytes: init (`ESC @`),
  centred/bold header, columns sized to the paper width (58mm ≈ 32 cols, 80mm ≈
  48 cols), bold total, a full cut (`GS V 0`), and an optional drawer kick
  (`ESC p 0 25 250`). The peso sign is not in the default code page, so money is
  prefixed with a plain `P`.
- `ReceiptPrinter` — the single entry point. Picks the connector from settings,
  builds the bytes, appends the drawer kick when enabled, writes. Returns `false`
  when no printer is configured (silent skip) and throws `PrinterException` on a
  real device failure. Also has `test()` for the settings-page test slip.

## Flows

- **Auto-print** (`PosController::checkout`, toggle `auto_print`, default **on**):
  after the sale's `DB::transaction` commits, `ReceiptPrinter::print()` is called
  in a try/catch. A failure only downgrades the success toast to a warning.
- **Reprint** (`SalesController::print`, route `sales.print`, Cashier-level): any
  user who can view a sale may reprint it; the copy carries a `*** REPRINT ***`
  banner. Printing never mutates the immutable sale.
- **Settings + test print** (`PrinterSettingsController`, Owner-only, surfaced on
  the business-settings page): configure connection/paper/drawer and fire a test
  slip.

### Invariant: a print failure never rolls back a sale

The sale is committed **before** any print is attempted. Auto-print and reprint
both wrap `ReceiptPrinter` in try/catch and surface the error as a toast only.
The sale is always recorded and always reprintable from Sales.

## Cash drawer

The drawer is kicked over ESC/POS (`ESC p 0 25 250`) when `open_cash_drawer` is on
and the connection is a real ESC/POS transport — i.e. the drawer must be wired to
(or chained through) the receipt printer. There is no independent drawer device.

## macOS & Windows setup (the `system` transport)

The bytes still originate in PHP; the OS spooler only carries them, in raw mode.

1. **Install the printer in the OS.** Add the thermal printer in *System Settings →
   Printers* (macOS) or *Settings → Bluetooth & devices → Printers* (Windows) so it
   becomes a named print queue. A "Generic / Raw" or vendor driver both work — the
   bytes bypass driver rendering.
2. **Configure it in the app.** Business settings → Receipt printing → connection
   **System printer** → **Detect printers** and pick it (or type the exact queue
   name) → **Save** → **Test print**.
3. **macOS** uses CUPS `lp -o raw`; **Windows** uses the bundled winspool RAW helper
   (`resources/printing/windows-raw-print.ps1` — it must ship in the NativePHP build).
   No group membership or udev rule is needed on either OS.

## Linux setup prerequisites (deployment)

These are environment steps, not app code:

1. **Device permissions.** The app's PHP process needs write access to the device
   node. Add the runtime user to the `lp`/`dialout` group, or ship a udev rule.
   (On the dev machine `cyrus` is already in `dialout`; `/dev/rfcomm0` is
   `root:dialout`.)
2. **USB.** The printer should enumerate `/dev/usb/lp0` via the `usblp` module.
   Verify with `ls -l /dev/usb/lp*` and `dmesg` after plugging in. If it does not
   appear (a libusb-only device), that is the deferred case above.
3. **Bluetooth.** Pair with `bluetoothctl`, then bind a serial port:
   `sudo rfcomm bind /dev/rfcomm0 <MAC> 1`, and set the device path to
   `/dev/rfcomm0`.
4. **Network.** Set the printer's IP and port (usually 9100).

## Verification

- **No hardware:** `php artisan test --filter=Printing` and the reprint/checkout
  tests pass (a temp file stands in for the device; a fake/throwing printer proves
  the sale never rolls back). A network printer can be smoke-tested against
  `nc -l 9100`.
- **Real printer:** configure it in Business settings → Receipt printing, run
  **Test print**, then complete a POS sale (auto-print) and reprint from Sales;
  confirm alignment at 58mm/80mm, that item **cost never appears**, and that the
  drawer kicks. Disconnect the printer and sell again — the sale must still be
  recorded with a warning toast.
