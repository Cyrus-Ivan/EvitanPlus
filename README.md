# Evitan Plus

**Evitan Plus** is an offline-first retail Point of Sale desktop app for a single business installation. It runs entirely on the local machine — no server, no cloud, no network dependency — and packages Laravel + React into a native desktop executable via NativePHP.

The schema stays sync-compatible (UUID primary keys everywhere) so multi-branch and cloud sync remain possible later, but they are explicitly **out of scope** for the MVP.

## Highlights

- **Point of sale** — product grid and search (name / SKU / barcode), barcode scanner support, cart with quantity editing, amount/percentage discounts, cash / cashless / others payment types, cash-received and automatic change, and an optional checkout comment. Out-of-stock products stay visible but disabled.
- **Ledger-based inventory** — categories, items, and variants with an **append-only** inventory ledger as the single source of stock truth. Current stock is ledger-sum reconciled against a snapshot; **negative inventory is never allowed**. Low-stock thresholds highlight rows that need restocking.
- **CSV import / export** — bulk catalog upsert by SKU with "replace stock count" and "add to current stock" modes, downloadable template, validation messages, and upload history. Every import funnels through the ledger.
- **Immutable sales history** — completed transactions and line items never change. Selling price and item cost are snapshotted at time of sale, so later price edits never rewrite past sales. The transaction number is a continuous, gapless, zero-padded running serial (`0000000001`, never resets — the BIR-compliant shape) that doubles as the receipt number.
- **Returns & defects** — partial and multiple returns (restore stock) and defect write-offs (do not restore stock), recorded in a dedicated `sale_returns` family that references the original sale without ever mutating it.
- **Receipt printing** — driverless ESC/POS straight to the device (USB / Bluetooth / network socket), 58mm and 80mm, auto-print, cash-drawer kick, and reprint. A printing failure **never** rolls back a completed sale.
- **Reports** — daily / weekly / monthly / yearly sales on one page with drill-down buckets, a stacked-by-item net-sales chart, and PDF export. Totals are always recomputed from sales minus returns minus defects; COGS and profit are Owner-only.
- **Roles & permissions** — Owner > Admin > Cashier, enforced at routes, actions, and UI. **Item cost is Owner-only** everywhere, including reports and API responses.
- **Admin & ops** — user management (create / archive / reset password / change role), login history (login, logout, crash, forced logout, power loss), an immutable audit log of every significant action (Admin+ view, cost redacted for non-Owners), and Owner-only SQLite backup / restore — including to and from an external drive — with an automatic safety backup before every restore.

## Tech stack

- **Laravel 12** (backend) — pinned to 12 for NativePHP compatibility.
- **React 19 + TypeScript** over **Inertia**, from the Laravel React starter kit.
- **Tailwind CSS 4** + **shadcn/ui** components.
- **NativePHP (Electron)** to package the app as a native desktop executable.
- **SQLite** as the only database (`database/nativephp.sqlite`), with foreign-key enforcement on.

## Repository layout

| Path | What it is |
|---|---|
| `/` (root) | **Evitan Plus** — the shipped desktop app. Laravel + React + NativePHP over local SQLite. |
| `console/` | **Provisioning Console** — a developer-only Laravel CLI app with its own `composer.json`, `vendor/`, `.env` and autoload namespace (`Provisioning\`). It holds the admin Postgres credentials and owns all DDL. |

`console/` is **never bundled into any build**, and the POS app contains no
runtime reference to it. That boundary is enforced by
`nativephp.cleanup_exclude_files` / `cleanup_env_keys`, gated in CI by
[`tests/Feature/Build/BuildExclusionTest.php`](tests/Feature/Build/BuildExclusionTest.php),
and re-checked by hand at release time (see "Building a release", step 4).
Spec: [`MULTIBRANCH-BRAINSTORM.md`](MULTIBRANCH-BRAINSTORM.md) §18.

## Requirements

- PHP **8.2+** and Composer
- Node.js **24** and npm
- SQLite

To cut a desktop release you also need your dev machine's PHP minor to match the
vendored runtime in [`build/php/`](build/php/README.md) (currently **8.5**) —
NativePHP bundles the zip matching the host minor. That runtime, not
`nativephp/php-bin`, is what ships inside the app: it is the only one carrying
both `pdo_sqlite` and `pdo_pgsql`.

## Getting started

```bash
# 1. Install dependencies
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate

# 3. Database (creates/migrates database/nativephp.sqlite)
php artisan migrate
```

Run the app in development:

```bash
# Web dev — Laravel server + queue + logs + Vite, all at once
composer dev

# Desktop dev — NativePHP (Electron) shell + Vite
composer native:dev
```

Default development login: **`owner` / `password`** (created by the local demo
seed — `migrate:fresh --seed`). A packaged fresh install has no seeded account:
the app opens an initial setup wizard that creates the Owner.

### Provisioning console (developer-only)

Optional for POS development; required for anything in
[`MULTIBRANCH-ROADMAP.md`](MULTIBRANCH-ROADMAP.md). It is a self-contained
Laravel app with its own dependency tree.

```bash
(cd console && composer install)
cp console/.env.example console/.env
php console/artisan key:generate

# Paste your Supabase admin credentials into console/.env (CONSOLE_DB_*),
# then verify the connection and its DDL capability:
php console/artisan console:ping
```

Use Supabase's **session** endpoint on port `5432` — the transaction pooler on
`6543` does not preserve session state and breaks DDL and `search_path`
pinning. Pooler usernames are `postgres.<project-ref>`, not plain `postgres`.
**Never copy `CONSOLE_DB_*` values into the root `.env`.**

## Common commands

```bash
php artisan test          # Pest / PHPUnit test suite
npm run e2e               # Playwright E2E suite (isolated DB copy)
npm run lint              # ESLint (auto-fix)
npm run format            # Prettier
./vendor/bin/pint         # Laravel Pint (PHP formatting)
npm run build             # Production frontend build
php artisan native:build  # Build the native desktop executable

php console/artisan console:ping   # Verify the console's admin Supabase connection
```

## Building a release

1. Bump the version default in `config/nativephp.php` and add an entry to
   [`RELEASE-NOTES.md`](RELEASE-NOTES.md).
2. Run every gate with `composer verify`. It chains them fail-fast, cheapest
   first: `composer audit` → `pint --test` → `phpstan` → `php artisan test` →
   `npm run types` → `npm run lint:check` → `npm run format:check` →
   `npm run e2e` (which builds the frontend before it starts Playwright).
   The audit step needs network access and ignores *abandoned* packages —
   `nativephp/electron` and `nativephp/laravel` are deliberate pins, not
   oversights. The M0 build gates —
   [`BuildExclusionTest`](tests/Feature/Build/BuildExclusionTest.php) (no
   console code or admin credentials in a build) and
   [`PhpBinaryTest`](tests/Feature/Build/PhpBinaryTest.php) (the shipped PHP
   runtime carries both `pdo_sqlite` and `pdo_pgsql`) — run inside
   `php artisan test`.
3. Build: `php artisan native:build linux` — artifacts land in `dist/` as
   `Evitan Plus-<version>.AppImage` and `evitan-plus_<version>_amd64.deb`;
   rename the AppImage to `evitan-plus-<version>.AppImage` (no spaces) before
   distributing.
4. Inspect the artifact before shipping: extract the AppImage
   (`--appimage-extract`) and confirm the bundled `.env` carries no
   `APP_KEY` / `APP_ENV` / `APP_DEBUG` (stripped via
   `nativephp.cleanup_env_keys`), no `*.sqlite` files or repo internals are
   inside, and `public/icon.png` is the Evitan mark. **Also confirm the
   developer/admin boundary held** — no `console/` directory, no
   `MULTIBRANCH-*.md`, and no `CONSOLE_*` / `BRANCH_DB_*` / `SUPABASE_*` keys
   in the bundled `.env`:

   ```bash
   ./evitan-plus-<version>.AppImage --appimage-extract >/dev/null
   # Payload lives at resources/app.asar.unpacked/resources/app; the find keeps
   # this robust if electron-builder's layout changes.
   APP=$(find squashfs-root -type d -name app -path '*resources*' | head -1)

   test ! -e "$APP/console" && echo "OK: no console/" || echo "FAIL: console/ shipped"
   ls "$APP"/MULTIBRANCH-*.md >/dev/null 2>&1 \
       && echo "FAIL: design docs shipped" || echo "OK: no design docs"
   grep -qE '^(CONSOLE_|BRANCH_DB_|SUPABASE_)' "$APP/.env" \
       && echo "FAIL: admin keys shipped" || echo "OK: no admin env keys"

   # The bundled PHP runtime must carry BOTH drivers, or the app cannot read
   # its own SQLite database / cannot reach its branch Postgres.
   PHP_BIN=$(find squashfs-root -type f -path '*resources/php/php' | head -1)
   "$PHP_BIN" -r 'echo implode(",", PDO::getAvailableDrivers()), PHP_EOL;'  # → pgsql,sqlite
   rm -rf squashfs-root
   ```

   [`tests/Feature/Build/BuildExclusionTest.php`](tests/Feature/Build/BuildExclusionTest.php)
   and [`tests/Feature/Build/PhpBinaryTest.php`](tests/Feature/Build/PhpBinaryTest.php)
   gate the same invariants in CI; this step is the belt-and-braces check
   against the real artifact.
5. Smoke-test the installed app with a scratch `HOME`: the initial setup
   wizard (create the Owner, business details, timezone/currency, optional
   printer), a sale, a backup, a restore + restart.

The packaged app provisions itself on every launch (migrate → stock
reconcile) and generates a per-install `APP_KEY` on first boot; a fresh
install (no users yet) opens the setup wizard before anything else.

## Developer onboarding: standing up a branch

> **Status:** this is the target flow for **Evitan Plus** (multi-branch). Steps
> tagged *(planned — Phase Mx)* are **not implemented** as of `v1.0.0`; today a
> device is still a single, self-contained install set up by the existing setup
> wizard. Phase status lives in [`MULTIBRANCH-ROADMAP.md`](MULTIBRANCH-ROADMAP.md).

Every branch device is prepared by a developer, in this order:

1. **Provision** — in `console/`, create the business schema (`biz_<id>`) or add
   a branch to an existing one, create the least-privilege `branch_<id>`
   Postgres role, and emit that branch's credentials JSON.
   *(planned — Phases M1/M2. M0 ships only `console:ping`.)*
2. **Hardware** — prepare the till: OS install, operator account, receipt
   printer, cash drawer.
3. **AppImage** — build the release and copy it to the device (see
   "Building a release" above). Credential rotation is delivered the same way:
   a fresh AppImage plus a new JSON, never an in-place swap.
4. **Wizard** — run the setup wizard on the device: import the JSON (stored
   encrypted at rest under the per-install `APP_KEY`), verify version
   compatibility, run the local SQLite migrations, and hydrate the branch
   baseline — catalog, all users, settings/identity, and this branch's stock
   snapshot. *(planned — Phases M5/M6. The current wizard creates the Owner
   locally instead.)*

The business then simply powers on a ready device and logs in as the Owner
created during provisioning. The credentials JSON is one-time: after a
successful import the wizard prompts to remove it. Admin/service credentials
never leave `console/.env`. See
[`MULTIBRANCH-BRAINSTORM.md`](MULTIBRANCH-BRAINSTORM.md) §2, §9, §16.

## Architecture & invariants

A few non-negotiable rules shape the entire codebase — violating them is a correctness bug, not a style choice:

- **Immutable business history** — historical transactions, transaction items, captured prices/costs, the inventory ledger, audit logs, and login history are append-only and never edited.
- **Never delete business records** — categories, items, and variants are **archived**; users are **soft-deleted**. Archived entities stay visible in all historical records.
- **Inventory is ledger-derived** — there is no stock-adjustment table; every movement (sales, returns, defects, manual adjustments, CSV imports) is a ledger entry. Stock never goes negative.
- **UUID primary keys everywhere** — human-readable transaction numbers are a separate value.
- **Permissions enforced at every layer** — exactly one Owner (never archivable/deletable); item cost is Owner-only.
- **Printing never rolls back a sale** — the sale commits, the user sees a clear error, and the receipt can be reprinted.

Deeper documentation lives in the repo:

- [`BRAINSTORM.md`](BRAINSTORM.md) — full functional specification
- [`ROADMAP.md`](ROADMAP.md) — phased build plan
- [`CLAUDE.md`](CLAUDE.md) — invariants and working guidance
- [`docs/`](docs/) — after-sales and receipt-printing design of record
- [`MULTIBRANCH-BRAINSTORM.md`](MULTIBRANCH-BRAINSTORM.md) — Evitan Plus (multi-branch, provisioning, sync) spec of record
- [`MULTIBRANCH-ROADMAP.md`](MULTIBRANCH-ROADMAP.md) — Evitan Plus phased build plan (M0–M12)

## Project status

**Released — v1.0.0** (see [`RELEASE-NOTES.md`](RELEASE-NOTES.md)). All 19 `ROADMAP.md` phases are complete: the full commerce chain (Setup, DB schema, Auth, Authorization, Settings, Inventory, Ledger, CSV Import/Export, POS, Transactions, Returns/Defects, Printing, Reports), the full admin/ops track (User Management, Login History, Audit Logs, Backup/Restore), UI polish, the Phase 18 final review (400+ backend tests, 10 Playwright E2E specs, DB-level immutability triggers), and release preparation.

This summary is approximate — confirm current progress against `git log` and the code.

## License

This codebase is **private** and not licensed for redistribution. It is derived from the [Laravel + React starter kit](https://laravel.com/docs/starter-kits), which is open-source under the MIT license.
