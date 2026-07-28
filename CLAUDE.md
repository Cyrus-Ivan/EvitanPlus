# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project status

The Laravel + React (Inertia) + NativePHP + SQLite app is **released — all 19 `ROADMAP.md` phases complete, first production release tagged `v1.0.0`** (2026-07-19, Linux AppImage + deb; see `RELEASE-NOTES.md`): the full commerce chain (Setup, DB schema, Auth, Authorization, Settings, Inventory, Ledger, CSV Import/Export, POS, Transactions, Returns/Defects, Receipt Printing, Reports incl. PDF export), the full admin/ops track (User Management, Login History, Audit Logs, Database Backup/Restore incl. external drive), the UI polish pass, the **Phase 18 final review** (429 backend tests + 10 Playwright E2E specs, invariants enforced by DB triggers + model guards, business timezone Asia/Manila — see `docs/phase-18-findings.md`), and **Phase 19 release preparation** (once-per-launch provisioning, per-install `APP_KEY`, WAL-aware backup/restore, About page, release build pipeline — see the `README.md` "Building a release" section). **Post-v1.0.0:** a fresh install now runs a pre-login **initial setup wizard** (`/setup`, gated by `RequireInitialSetup` when no user exists) that creates the Owner, business identity, timezone/currency settings, and optional printer config in one atomic submit — there is no seeded production account anymore; forced password change remains for admin resets. Void was dropped from Phase 10 — after-sales events are returns and defects only. Per-phase implementation notes live in the agent memory index, not here — treat this paragraph as approximate and confirm current progress against `git log` and the code.

`BRAINSTORM.md` is the full functional spec and `ROADMAP.md` is the phased build plan. `docs/returns-refunds-voids-design.md` (after-sales) and `docs/receipt-printing-design.md` (printing) are the designs of record for their areas — where `BRAINSTORM.md`/`ROADMAP.md` disagree with them, the design docs win.

## Vocabulary

`docs/glossary.md` is **normative** for this project's terminology and **outranks every other document** — including this one and the design docs — on what a word means. Read it before writing anything against a spec. It exists because a large share of the multi-branch initiative's "As built" corrections were not design changes but a word meaning one thing in the spec and another in Postgres: a grant named on a column that a check constraint made unusable, a `branch:delete` that deletes no branch, a "credential" that is really two values. Each cost implementation time to rediscover.

Two rules:
- **A word that carries weight in a spec or a phase note gets an entry**, with the code artifact that anchors it. A term with no anchor is one two people can read differently.
- **Don't coin a synonym for a defined term.** If a needed distinction has no word yet, add the entry rather than inventing a word in passing — that is precisely how `till`/`device`/`instance`, `outbox`/`queue`, and `mirrored`/`readable`/`pulled` each became three words for what readers assumed was one thing.

Naming: **Evitan POS** is the shipped single-install, offline-only product (`v1.0.0`); **Evitan Plus** is the multi-branch, cloud-connected product the `M0–M12` initiative builds. They are different products with different application ids and data directories, not old and new spellings of one name (`RELEASE-NOTES.md`). Use "Evitan Plus" for user-facing names unless told otherwise.

## What this is

Evitan Plus is an **offline-first retail Point of Sale** desktop app for a **single business installation**. No server, no cloud, no network dependency. Multi-branch and cloud sync are explicitly out of scope for the MVP but the schema should stay sync-compatible (hence UUIDs everywhere).

## Stack

- **Laravel 12** (backend) + **React 19 / TypeScript** (frontend) over **Inertia**, from the Laravel React starter kit. Pinned to Laravel 12 for NativePHP compatibility.
- **NativePHP** to package it as a native desktop executable.
- **SQLite** as the only database (`database/nativephp.sqlite`, shared by terminal artisan and the NativePHP runtime). Foreign key enforcement is on.

Standard Laravel workflow applies: `php artisan migrate` (or `migrate:fresh --seed`), `php artisan test` (Pest/PHPUnit), `npm run dev` / `npm run build`, `npm run lint`, `./vendor/bin/pint`, and NativePHP's `php artisan native:serve` / `native:build`. Dev login is `owner` / `password` (from the local demo seed; packaged fresh installs run the setup wizard instead). Verify script details in `composer.json` / `package.json` if in doubt.

## Non-negotiable invariants

These rules span the entire codebase and are the reason the architecture looks the way it does. Violating them is a correctness bug, not a style choice. Full detail is in `BRAINSTORM.md`.

**Immutable business history**
- Historical transactions, transaction items, and their captured prices **never change**. Selling price / item cost edits must not retroactively alter past sales — prices and costs are snapshotted onto each transaction item at time of sale.
- The inventory ledger is **append-only**. Never edit or delete a ledger row. Corrections are new compensating entries.
- Audit logs and login history are immutable and append-only.

**Never delete business records**
- Categories, Items, Variants → **archived** (flag), not deleted. Hidden from default lists, still visible via Archived filter and in all historical records.
- Users → **soft deleted**. Archived users must still appear in Sales, Login History, and Audit Logs.

**Inventory is ledger-derived**
- There is **no stock-adjustment table**. All stock movement — sales, returns, defects, manual adjustments, CSV imports — is expressed as inventory ledger entries.
- Current stock = ledger sum reconciled against a stock snapshot that is updated on every ledger insert.
- **Negative inventory is never allowed.** Enforce at write time.
- Returns restore stock (new positive ledger entry); **defects do not** restore stock.

**Identity & keys**
- Every table uses a **UUID primary key**. The transaction number is a separate human-readable value: a **continuous, gapless, zero-padded running serial** (`0000000001`, never resets — the BIR-compliant CRM/POS shape) that serves as both the internal transaction id and the customer-facing receipt number (there is no separate `receipt_number` column).
- Uniqueness: username unique; **Item name unique** (case-insensitive, spanning archived items); SKU **required**, unique, and stored uppercase; barcode unique *when provided* (blank barcode is allowed and must not block selling). **Variant names are not unique** — different items may reuse "Small"/"Large".
- Every Item must have **at least one Variant**. Stock, price, SKU, and barcode live on the Variant, not the Item. A single-product item keeps exactly one **unnamed** variant (`name` is null) — the "no variant" case; UI and reports fall back to the item name.

**Roles & permissions** (Owner > Admin > Cashier)
- Exactly **one Owner**; the Owner can never be archived or deleted.
- **Item Cost is Owner-only** to view and edit — Admins and Cashiers must never see it, including in reports and API responses. Cashiers get view-only inventory and cannot see cost.
- Enforce permissions at every layer: routes, actions, components, and buttons.

**Point of sale**
- Printing and cash-drawer opening happen after checkout but a **printing failure must never cancel or roll back a completed sale** — the sale commits, the user sees a clear error, and the receipt can be reprinted.
- Out-of-stock products stay visible but disabled.
- Discounts: amount (capped at transaction total) or percentage (capped at 100%). Split payments are out of scope.

**Reporting**
- Reports **recompute** totals from sales minus returns minus defects. Never trust a single stored total for reporting.

**Auditability**
- Every significant action (auth events, user/inventory/price changes, imports/exports, backups, restores, settings changes) writes an audit log entry.

**The Provisioning Console never ships** (Evitan Plus)
- `console/` is a separate, developer-only Laravel CLI app holding admin Postgres credentials. Its code and credentials must never appear in any AppImage/deb/build. Enforced structurally (separate app, no runtime reference from the POS app) plus `nativephp.cleanup_exclude_files` / `cleanup_env_keys`, and gated by `tests/Feature/Build/BuildExclusionTest.php`. See `MULTIBRANCH-BRAINSTORM.md` §18.

## Build order

Follow `ROADMAP.md` phases in sequence. The guiding principle is to complete each feature **end-to-end** (migration → backend → frontend → validation → permissions → audit logging → tests) before starting the next, keeping the app runnable at every phase. Do not open several major modules at once. Order: Setup → DB schema → Auth → Authorization → Settings → Inventory → Ledger → CSV Import/Export → POS → Transactions → Returns/Defects → Printing → Reports → User Management → Login History → Audit Logs → Backup/Restore → Polish → Testing → Release.
