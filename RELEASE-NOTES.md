# Evitan Plus — Release Notes

> **Naming:** the product was renamed from **Evitan POS** to **Evitan Plus**
> after v1.0.0. The v1.0.0 entry below is left as released — it shipped under
> the old name, and its file paths (`~/.config/evitan-pos/`) are where a real
> v1.0.0 install actually stores its database and encryption key.
> Builds from the rename onward use the name **Evitan Plus**, application id
> `com.evitan.plus`, artifacts `Evitan Plus-<version>.AppImage` /
> `evitan-plus_<version>_amd64.deb`, and data under `~/.config/evitan-plus/`.
> Because the application identity changed, a new build does **not** adopt a
> v1.0.0 install's data — treat it as a fresh install (or copy the old
> directory across deliberately).

## v1.0.0 (2026-07-20)

First production release. **Linux only** (AppImage + Debian package), built with
NativePHP (Electron) over Laravel 12 + React 19 + SQLite.

### What's in the box

- **Guided first-run setup** — a fresh install ships **no default credentials**.
  On first launch a pre-login setup wizard creates the Owner account, the
  business identity (name, address, TIN, receipt footer), the business timezone
  and currency symbol, and — optionally — the receipt printer, in one atomic
  step, then signs you straight in. Existing installs never see the wizard.
- **Configurable timezone & currency** — business timezone (default
  `Asia/Manila`) and currency symbol (default `₱`), set in the wizard and
  editable later under Business Settings; both apply across the POS, reports, and
  receipts.
- **Point of sale** — product grid with search (name / SKU / barcode), barcode
  scanner support, cart with quantity editing, amount/percentage discounts,
  cash / cashless / others payment types, cash-received with automatic change,
  keyboard checkout (F9 / Enter). Out-of-stock products stay visible but
  disabled; inventory can never go negative.
- **Ledger-based inventory** — categories, items, and variants; every stock
  movement (sales, returns, manual adjustments, CSV imports) is an append-only
  ledger entry; snapshots are re-checked against the ledger on every launch.
- **CSV import / export** — bulk catalog upsert by SKU with replace/add stock
  modes, template download, validation, and upload history.
- **Immutable sales history** — snapshotted prices/costs, and a continuous,
  gapless, zero-padded transaction serial that doubles as the receipt number
  (BIR-compliant shape). Immutability is enforced by database triggers.
- **Returns & defects** — partial and multiple returns (restore stock), defect
  write-offs (no stock restore), cent-exact discount-aware refunds.
- **Receipt printing** — driverless ESC/POS to USB / Bluetooth / network / OS
  print queue, 58mm and 80mm, auto-print, cash-drawer kick, reprint. A print
  failure never cancels a completed sale.
- **Reports** — daily / weekly / monthly / yearly with drill-down buckets,
  stacked-by-item net-sales chart, PDF export. Totals always recomputed as
  sales − returns − defects; profit/COGS are Owner-only.
- **Admin & ops** — user management (Owner > Admin > Cashier), login history
  with crash detection, immutable audit logs, and Owner-only database backup and
  restore. *(Backup was removed after v1.0.0 — Evitan Plus keeps a branch's data
  in the business database and rebuilds a replacement device from it.)*

### Installing

- **AppImage:** `chmod +x evitan-pos-1.0.0.AppImage && ./evitan-pos-1.0.0.AppImage`
- **Debian/Ubuntu:** `sudo dpkg -i evitan-pos_1.0.0_amd64.deb`

### First launch

1. The app creates its database automatically and opens the **setup wizard** —
   no default account ships.
2. Create your Owner account (your name, username, and password) and enter your
   business details (name, address, TIN, receipt footer).
3. Pick your timezone and currency symbol, then configure the receipt printer or
   skip it — it lives in Business Settings.
4. One submit signs you straight in on the POS screen; add categories, items,
   and stock.

Forced password change remains only for accounts whose password an admin later
resets.

### Where your data lives

Everything is stored per user account under `~/.config/evitan-pos/`:

| What | Where |
| --- | --- |
| Database | `~/.config/evitan-pos/database/database.sqlite` |
| Logs (rotating, 3 days) | `~/.config/evitan-pos/storage/logs/` |
| Per-install encryption key | `~/.config/evitan-pos/storage/app/app.key` |

### Known limitations

- **Single terminal, single store.** Multi-branch and cloud sync are out of
  scope (the schema is sync-ready for a future version).
- **BIR ATP series is not configurable.** The receipt serial is continuous and
  gapless, but a real Authority-To-Print registration needs a configurable
  series prefix and starting serial — planned, not built.
- **Raw-USB printers without a device file** (no `/dev/usb/lp*`, no OS queue)
  are not supported yet; use USB-as-device-file, Bluetooth, network, or the OS
  print queue. Test your printer with **Business Settings → Test print**
  before going live.
- **Timezone is chosen once at setup** and used everywhere (receipts, report
  buckets, daily sequences). Change it later under Business Settings, but keep it
  stable — historical timestamps are stored in the business timezone.
- **No auto-update.** Install new releases manually over the old one — your
  data directory is preserved.

### Manual release checklist (per install)

- [ ] Physical receipt printer: configure transport, run a test print, one
      real sale print, one reprint, cash-drawer kick.
- [ ] First-launch flow: initial setup wizard completed (Owner account,
      business details, timezone/currency, printer), lands signed in on POS.
