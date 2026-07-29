# ROADMAP.md

# Evitan Plus MVP Development Roadmap

This roadmap prioritizes building a **working application as early as possible**, while minimizing refactoring. Each phase should produce a usable milestone before moving to the next.

> **Status:** All phases 0–19 are **complete** (✅) — first production release tagged `v1.0.0` (see `RELEASE-NOTES.md`). Confirm against `git log` and the code.

---

# Phase 0 — Project Initialization ✅

## Goal

Set up the project foundation.

## Tasks

* Create Laravel NativePHP project
* Install React starter
* Configure SQLite
* Configure UUID generation
* Enable SQLite foreign keys
* Create project structure
* Configure routing
* Create layouts
* Create navigation sidebar
* Create reusable UI components
* Configure dark/light theme if desired
* Create notification/toast system
* Create modal component
* Create confirmation dialog component

**Milestone**

Application launches with navigation.

---

# Phase 1 — Database Foundation ✅

## Goal

Build every database table before implementing business logic.

## Tables

* Users
* Categories
* Items
* Variants
* Transactions
* Transaction Items
* Inventory Ledger
* Audit Logs
* Login History
* Import History
* Settings

Implement

* UUIDs
* Soft Deletes
* Foreign Keys
* Indexes
* Constraints

**Milestone**

Database schema complete.

---

# Phase 2 — Authentication ✅

## Goal

Users can securely access the application.

## Features

* Login
* Logout
* Password hashing
* Session management
* Single logged-in user
* Crash detection
* Forced logout detection
* Login history
* Password change

Implement

* Owner
* Admin
* Cashier

Seed default Owner account. *(Superseded post-v1.0.0: the Owner is now created
by the initial setup wizard on first launch; only the local demo seed still
creates the `owner` / `password` dev login.)*

**Milestone**

Secure authentication working.

---

# Phase 3 — Authorization ✅

## Goal

Protect every page.

Implement role permissions.

Cashier

* POS
* Sales
* View Inventory
* Own Login History

Admin

* Everything except Item Cost
* User Management (Cashier only)

Owner

* Full Access

Protect

* Routes
* Components
* Buttons
* Actions

**Milestone**

Permissions complete.

---

# Phase 4 — Settings ✅

## Goal

Owner can configure the business.

Features

* Business Name
* Address
* TIN
* Receipt Footer

Persist in SQLite.

**Milestone**

Business identity configured.

---

# Phase 5 — Inventory Foundation ✅

## Goal

Manage products.

Build

Categories

Items

Variants

Features

* Create
* Edit
* Archive
* Restore
* Search
* Pagination
* Filters

Rules

* SKU uniqueness
* Barcode uniqueness
* Owner-only Item Cost

**Milestone**

Inventory management complete.

---

# Phase 6 — Ledger System ✅

## Goal

Implement inventory computation.

Build

Inventory Ledger

Stock Snapshot

Functions

* Compute stock
* Update snapshot
* Prevent negative inventory
* Stock history page

Implement

* Adjustment entries
* Sale entries
* Return entries
* Defect entries

Return entries restore stock; defect entries are 0-movement audit rows. See `docs/returns-refunds-voids-design.md`.

Ledger becomes append-only.

**Milestone**

Inventory calculations complete.

---

# Phase 7 — Inventory Import / Export ✅

## Goal

Bulk inventory management.

Features

Download Template

CSV Upload

CSV Export

Upload History

Validation

Support

* Replace Stock Count
* Add To Current Stock

Generate Ledger entries automatically.

**Milestone**

Inventory migration tools complete.

---

# Phase 8 — POS ✅

## Goal

Cashier can sell products.

Build

Product Grid

Cart

Search

Barcode Scanner

Quantity Editor

Discount

Payment Type

Cash Received

Automatic Change

Checkout

Rules

* No negative inventory
* Out of stock disabled

**Milestone**

Products can be sold.

---

# Phase 9 — Transactions ✅

## Goal

Store completed sales.

Create

Transactions

Transaction Items

Generate

Transaction Number (a continuous, gapless, zero-padded running serial — `0000000001`, never resets; it doubles as the receipt number, there is no separate receipt-number column)

Store

Historical Selling Price

Historical Item Cost

Historical Discounts

Generate Ledger entries.

`transaction_items` holds sale lines only; `transactions` and `transaction_items` are immutable (append-only, no `updated_at`). Returns/defects live in `sale_returns` — see `docs/returns-refunds-voids-design.md`.

**Milestone**

Sales become permanent.

---

# Phase 10 — Returns, Refunds & Defects ✅

## Goal

Support after-sales workflow. (Void was dropped — cancelling before checkout is handled at
the POS by unselecting cart items; post-checkout use returns.)

**Implement per `docs/returns-refunds-voids-design.md` (authoritative schema + rules).**

Features

Partial Return

Multiple Return

Defect

Validation

* Never exceed sold quantity

Returns

* Restore stock

Defects

* Do not restore stock

Reports compute totals using returns.

**Milestone**

Returns workflow complete.

---

# Phase 11 — Receipt Printing ✅

## Goal

Physical receipt support.

Support

58mm

80mm

Auto print

Open cash drawer

Printing failures

* Never cancel sale

Reprint

**Milestone**

Printing complete.

**Status: done.** Raw ESC/POS bytes generated in PHP, delivered by transport:
device file (USB `/dev/usb/lp0`, Bluetooth `/dev/rfcomm0`), network socket
(`ip:9100`), or the OS print queue in raw mode (`system` — the recommended path
on macOS/Windows; CUPS `lp -o raw` or a bundled winspool RAW helper). 58mm/80mm,
auto-print (default on, toggleable), cash-drawer kick, and reprint (with a
REPRINT banner). A print failure never rolls back the sale. Owner-only printer
settings + test print live on the Business settings page. Design of record:
`docs/receipt-printing-design.md`. Deferred (seam left open): a libusb/WinUSB
transport for a raw-USB printer with no device file and no OS queue.

---

# Phase 12 — Reports ✅

## Goal

Basic business reporting.

Implement

Daily Sales

Monthly Sales

Reports must compute

Sales

Returns

Defects

instead of relying on stored totals.

**Milestone**

Reports complete.

**Status: done.** Shipped beyond the minimum: Daily / Weekly / Monthly / Yearly
on one page (period toggle + prev/next stepping, drill-down sub-buckets), a
stacked-by-item net-sales SVG chart, Owner-only COGS/profit (with an un-costed
sales split so null costs never inflate margin), and PDF export (dompdf) with a
top-selling-items table. Everything recomputed from source tables.

---

# Phase 13 — User Management ✅

## Goal

Manage employees.

Features

Create User

Archive User (soft delete; restorable)

Reset Password (also signs the target out — forced_logout)

Update Role (name is immutable after creation)

Permissions

Owner

* Admin
* Cashier

Admin

* Cashier

**Milestone**

Employee management complete.

---

# Phase 14 — Login History ✅

## Goal

Audit authentication.

Display

Login

Logout

Crash

Forced Logout

Power Loss

Cashier

Own History

Admin

Everyone

Owner

Everyone

**Milestone**

Authentication auditing complete.

---

# Phase 15 — Audit Logs ✅

## Goal

Track important actions.

Log

User Changes

Inventory Changes

Price Changes

CSV Imports

Backups

Restores

Settings

Transactions

Password Reset

Audit logs are immutable.

**Milestone**

Business audit complete.

---

# Phase 16 — Database Backup ✅

## Goal

Protect business data.

Owner Only

Backup SQLite

Restore SQLite

Automatic backup before restore.

**Milestone**

Recovery complete.

---

# Phase 17 — UI Polish ✅

## Goal

Improve usability.

Review

Loading states

Skeletons

Empty states

Error pages

Confirmation dialogs

Success notifications

Validation messages

Keyboard navigation

Keyboard shortcuts

Responsive layouts

Accessibility

**Milestone**

Production-ready interface.

**Status: done.** Highlights: styled Inertia error page for 403/404/500/503
(419 → redirect-back + toast), React error boundary, boot-guard sign-out
message on the login screen, spinner-while-processing on every submit button,
POS keyboard support (global scan refocus, F9 / Enter checkout), a11y pass
(`th scope`, dialog descriptions, aria-labels, low-stock text badge), dialog
max-height, dead starter-kit header removed. Page-loading skeletons were
**deliberately skipped** — all queries hit local SQLite and the Inertia
progress bar covers slow cases; do not reopen in Phase 18/19.

---

# Phase 18 — Testing ✅

## Goal

Verify the MVP.

**Plan of record: `docs/phase-18-plan.md`** — a session-by-session final-review plan (testing
+ assumption-challenging + security + accuracy + code-quality review), with findings logged in
`docs/phase-18-findings.md`.

Authentication

Inventory

Ledger

Sales

Returns

Printing

CSV Import

Reports

Permissions

Backup

Restore

Negative inventory

Archived records

Crash recovery

Receipt generation

Audit logs

**Milestone**

Feature complete.

**Status: done (2026-07-18).** Ran as nine sessions (S1–S9): four review passes (invariants,
security, accuracy, code quality), backend test expansion (364 → 396 tests incl. cent-exact
money fixtures and serial-gaplessness proofs), a Playwright E2E suite (9 specs: commerce
chain, role gating, error pages, ops surfaces, smoke), then fix sessions closing all 26
findings — highlights: DB-level immutability triggers + model guards on all seven append-only
tables, archived users restored to history rows (`withTrashed`), `APP_TIMEZONE=Asia/Manila`,
MAX-based self-healing serial generators, restore refuses foreign SQLite files, profile page
locked down, formula-safe names, `inventory:reconcile` command. Exit gates all green
(`php artisan test` · `npx playwright test` · `npm run types` · `npm run lint:check` ·
`pint --test` · `phpstan`); final security review clean. Findings log + **Phase 19 handoff
list**: `docs/phase-18-findings.md`.

---

# Phase 19 — Release Preparation ✅

## Goal

Prepare production build.

Review

Application Icon

Installer

Version Number

Database Migration

Default Owner Account

Printer Testing

SQLite Optimization

Build NativePHP Executable

Release Notes

Create first production release.

**Status: done (2026-07-19), tagged `v1.0.0`.** Linux AppImage + deb built via
`native:build` and verified: bundle inspection (no APP_KEY/APP_ENV/APP_DEBUG in
the shipped `.env`, no databases/backups/repo-internals inside, Evitan icons)
and a fresh-`HOME` launch smoke (seeded owner with forced password change, 14
immutability triggers migrated, WAL live, per-install `APP_KEY` generated,
login serves 200, no queue worker). Beyond the packaging: once-per-launch
provisioning (migrate → seed-if-empty → `inventory:reconcile`), per-install
encryption key, WAL-aware backup/restore on the active connection, database
session driver re-pinned in the native runtime, forced password change for
seeded/reset accounts, Settings→About version surface, restore restart flow,
and `RELEASE-NOTES.md`. Known deferrals live in the release notes (BIR ATP
series config, raw-USB printer transport, physical-printer checklist per
install). Windows/macOS builds are future work.

**Amendment (2026-07-20, folded into v1.0.0):** the seeded
`owner` / `password` account and its first-login forced password change were
replaced by a pre-login **initial setup wizard** (`/setup`, gated by
`RequireInitialSetup` on the absence of any user, archived included). The
wizard atomically creates the Owner, business identity, the new
timezone/currency-symbol settings, and optional printer configuration, then
signs the Owner in. `LaunchProvisioner` no longer seeds (migrate + reconcile
only); `DatabaseSeeder` seeds settings defaults only; the local demo seed
still provides the dev login. `must_change_password` remains for
administrative resets.

> **Superseded by Evitan Plus M5 (`MULTIBRANCH-ROADMAP.md`).** The wizard no
> longer creates anything: the Owner and the business identity come from
> Postgres, and `/setup` imports a branch credential and hydrates the device.
> The gate moved with it — from "no user exists" to `instance.hydrated_at` —
> because users now arrive *during* setup. The paragraph above is kept as the
> record of what `v1.0.0` shipped.

---

# Development Order Summary

```
Project Setup
        │
        ▼
Database Schema
        │
        ▼
Authentication
        │
        ▼
Authorization
        │
        ▼
Settings
        │
        ▼
Inventory
        │
        ▼
Inventory Ledger
        │
        ▼
CSV Import / Export
        │
        ▼
POS
        │
        ▼
Transactions
        │
        ▼
Returns & Defects
        │
        ▼
Receipt Printing
        │
        ▼
Reports
        │
        ▼
User Management
        │
        ▼
Login History
        │
        ▼
Audit Logs
        │
        ▼
Backup & Restore
        │
        ▼
UI Polish
        │
        ▼
Testing
        │
        ▼
Production Release
```

---

# Guiding Principle

Each phase should leave the application in a usable state. Avoid starting multiple major modules simultaneously. Complete one feature end-to-end—including database, backend, frontend, validation, permissions, audit logging, and tests—before moving to the next. This incremental approach reduces regressions, makes debugging easier, and results in a stable MVP throughout development rather than only at the end.

