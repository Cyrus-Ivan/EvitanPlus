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

## v1.1.0 — unreleased

The multi-branch release: **Evitan Plus**. Many branches of one business share a
single authoritative database, and every branch still sells with the network
down. Nothing below has been tagged or built yet — this entry is written as the
work lands, per [`docs/releasing.md`](docs/releasing.md) §2.

### Rolling it out

**Migrate every business database before you install this build anywhere.**

| | |
| --- | --- |
| Schema version | **23** |
| Minimum app version | **1.1.0**, raised from 1.0.0 at schema 21 |
| Order | `schema:migrate --all` **first**, then build, then visit the tills |

There is no business database at v1.0.0 to compare against — that product is a
single machine and its own SQLite file, so this is not an upgrade path but a
provision-and-rebuild. What the schema version governs is businesses already
running an Evitan Plus build.

The floor has risen exactly once, at schema 21, and that is what makes the order
compulsory rather than tidy: a 1.0.0 build against a 21-or-later schema still
runs the old automatic step that answered its own pending stock adjustments, so
it would auto-approve every movement waiting for a human and accept a transfer's
sending leg without ever creating the receiving one — stock leaving one branch
and arriving nowhere, looking from the schema's side exactly like a branch doing
its job. Migrating first freezes those builds at `version_blocked`, where they
keep selling offline until somebody swaps the binary. Every other migration has
been additive and left the floor alone.

Full procedure — gates, `--pretend`, the re-grant step, confirming with
`monitor` — is [`docs/releasing.md`](docs/releasing.md).

### What's new

- **Branches.** One business, many locations, one Postgres schema on Supabase.
  Each till reads and writes its own SQLite and reconciles when the network is
  there; losing the connection never stops a sale. Receipt serials now carry the
  branch code (`MNL-0000000001`), which is what keeps them unique business-wide
  while each branch numbers its own offline.
- **A new first run.** A fresh install no longer creates the Owner — it connects.
  The developer imports the branch credentials file, and one submit joins the
  business, copies the catalog, staff, settings, this branch's stock **and this
  branch's history** down, and seals the credential to the device. Only the
  printer is still chosen on the machine.
- **Sync you can see.** Every screen shows how many rows are waiting and a
  **Sync now** button; the sidebar names the business and the branch you are
  standing in.
- **Stock moves only when somebody says it did.** Every stock movement —
  a recount at this till, an adjustment aimed at another branch, both legs of a
  transfer — is now *asked for* and confirmed by a person at the branch whose
  shelf it is, under **Pending Stocks**. Three answers: approve, approve part of
  it, or turn it down, the last two with a reason that follows the movement into
  Stock History. The one exception is opening stock, below.
- **Opening stock from a spreadsheet.** A branch that has not sold anything yet
  can set its whole shelf from a two-column file — SKU and quantity — on the
  Inventory page. A quantity is the total that should be there, not an amount to
  add, so a wrong number is fixed by uploading the file again. Every SKU has to
  exist already: a new business imports its products first, and a new branch
  already has the catalog. **The option disappears at the branch's first sale**,
  after which stock moves only through a request somebody confirms.
- **Transfers between branches.** One request, two confirmations: the sending
  branch says what left, and only then is the receiving branch asked what
  arrived. A shortfall in transit is written off rather than bounced back,
  because the unit no longer exists.
- **Stock batches.** Many products moved or recounted as one request, sharing one
  note, answered as one card.
- **Items and Inventory are two screens.** **Items** is the catalog — products,
  variants, prices, cost, categories. **Inventory** is the shelf: one row per
  variant with its stock, low-stock state and the three stock actions, for this
  branch or any other.
- **See the whole business from one till.** Reports, Sales, Login History, Stock
  History, Pending Stocks and Inventory stock all take a branch filter. Reports
  stay Owner-only, because they carry cost and profit; Sales, Login History,
  Stock History and Inventory stock are Admin and above, with a Cashier simply
  seeing their own branch; Pending Stocks is open to everyone, since answering a
  request is bounded by which branch you are standing in rather than by rank.
- **CSV import is products only.** It creates and updates the catalog, names
  every existing product a file would change and asks before applying it, and
  lives in a modal on Items. The stock columns and the CSV **export** are gone;
  stock has its own file now, under Opening stock above.

### Removed

- **Database backup and restore.** A single offline install had one copy of its
  data; a branch has the business database, and a dead device is replaced by
  running the wizard again on new hardware.
- **CSV export**, and the `stock` / `mode` columns on import.

### Known limitations

- **No bulk path for stock after the first sale.** Opening stock can be imported
  from a spreadsheet only until the branch sells something; after that a product
  gets its count from the stock batch dialog on Inventory, one line per variant.
  A spreadsheet cannot assert what is on a shelf that has a history, and from
  that point nothing sets stock without a person confirming it.
- **Work done offline on hardware that then dies is unrecoverable.** Anything a
  till had not yet synced is only on that machine. Rebuilding a device restores
  everything the business database holds and nothing it does not.
- **Branches are separated by grants, not by row-level security.** Every branch's
  credential is scoped to its own business and holds a deliberately narrow,
  table-by-table privilege set — but within one business the boundary is the
  application, not the database. This is a decision, not a gap
  ([`docs/glossary.md`](docs/glossary.md) §9).
- **Still no auto-update.** An update is: copy the new AppImage to the device,
  replace the old one, relaunch.
- **Linux only**, as at v1.0.0.

---

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
