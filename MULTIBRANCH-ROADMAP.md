# MULTIBRANCH-ROADMAP.md

# Evitan Plus — Multi-Branch, Provisioning Console & Sync Roadmap

Phased build plan for the **Evitan Plus** initiative (see `MULTIBRANCH-BRAINSTORM.md` for the
spec of record). It follows the same principle as the original `ROADMAP.md`: build each slice
**end-to-end** (migration → backend → frontend/TUI → validation → permissions/grants →
audit → tests) and keep the system runnable at every phase. Don't open several of these at once.

These phases follow the shipped `v1.0.0` (`ROADMAP.md` phases 0–19). They are numbered
**M0–M12** to keep them distinct from the original phases.

**Global constraints that apply to every phase**
- The Provisioning Console **never** ships in any build (structural separation + verified
  build exclusion + `cleanup_env_keys`).
- Branch DB roles stay **least-privilege** (SELECT/INSERT broadly; UPDATE only on
  `branch_status`, `stock_snapshots`, `inventory_update_queue.status`; **no DELETE**; one
  schema only).
- Original Evitan POS invariants (immutable history, append-only ledger, ledger-derived
  inventory, never delete records, UUID PKs, one Owner, Item Cost Owner-only, negative
  inventory never allowed) remain in force.

---

# Phase M0 — Monorepo, console skeleton & environment separation

## Goal
Establish the two-app layout and the security boundary before any provisioning logic exists.

## Tasks
- Add a separate Laravel app at `console/` (own `composer.json`, autoload namespace, `.env`).
- Wire the console's **direct admin Postgres connection** (superuser/service role) — used only
  by the console, never by the shipped app.
- Confirm/adjust the shipped app's **`pgsql` connection** in `config/database.php` (the custom
  binary carries the pgsql driver) and keep `sqlite` as the default local connection.
- **Verify build exclusion:** prove `console/` and its `.env` are **not** bundled by
  `native:build`; add any needed ignore config. Add console-only/admin keys to the POS build's
  `cleanup_env_keys` as defense-in-depth.
- Split migration sets: a **Postgres schema migration** directory owned by `console/`, and the
  existing **SQLite** migrations owned by the app.
- Document the developer onboarding chain in `README.md` (provision → hardware → AppImage →
  wizard).

## Milestone
`console/` runs its own `php artisan` against Supabase with admin creds; a release build is
verified to contain **no** console code or admin credentials.

---

# Phase M1 — Postgres business-schema definition & migrations

## Goal
Define the authoritative per-business schema and the migration engine that builds it.

## Tasks
- Author the Postgres migration set for one business schema: `users`, `categories`, `items`,
  `variants`, `prices`, `settings`, business identity, `branches`, `transactions`,
  `transaction_items`, `inventory_ledger`, `stock_snapshots`, `returns`, `defects`,
  `login_history`, `audit_logs`, `inventory_update_queue`, `branch_status`.
- Encode invariants in Postgres: UUID PKs, uniqueness (username, item name case-insensitive,
  SKU uppercase-unique, barcode-unique-when-present), append-only/immutability **triggers**,
  negative-inventory guards.
- Make the migration runnable into an **arbitrary named schema** (parameterized
  `search_path`), carrying a **schema version** + **min-compatible app version**.

## Milestone
`console` can create an empty, correctly-constrained schema `biz_<id>` on demand and report
its schema version.

---

# Phase M2 — Provision a business (schema + migrate + seed + role + JSON)

## Goal
One console command turns owner/business/branch input into a live, connectable schema.

## Tasks
- Laravel Prompts flow collecting **Owner**, **Business**, and **first Branch** (incl. unique
  **branch code**) — nothing is created until input is complete and validated.
- On submit (transactional): `CREATE SCHEMA` → run migration → seed Owner (hashed), business
  settings/identity, first `branches` row.
- `CREATE ROLE branch_<id>` with least-privilege grants + pinned `search_path`.
- Emit the **credentials JSON** (business/branch identity, branch code, Supabase connection,
  branch role user/password, schema + min-app versions).
- Write a console self-audit entry.

## Milestone
Running one command yields a populated schema, a least-privilege branch role, and a JSON file
that a `pgsql` client can use to connect and read the seeded data.

---

# Phase M3 — Provision a branch & credential lifecycle

## Goal
Add branches to an existing business and manage branch credentials.

## Tasks
- **Provision branch**: insert a `branches` row (unique branch code) + create its role + emit
  JSON (no new schema, no re-migration).
- **Rotate**: change a branch role's password; document/produce the **fresh-AppImage + new
  JSON** delivery artifact for that branch.
- **Disable / enable**: revoke/grant `LOGIN` on the role.
- **Delete / re-create** a branch role (re-create follows the rotation delivery path).
- Console self-audit for every action.

## Milestone
The console can fully manage the lifecycle of branch credentials; a disabled role's client
fails auth cleanly while an enabled one connects.

---

# Phase M4 — Schema-drift parity test

## Goal
Guarantee the SQLite mirror and Postgres schema agree on the mirrored tables.

## Tasks
- Build an automated **parity test** that introspects both schemas and asserts the mirrored
  tables match on column names and types via a known type map
  (`uuid↔text`, `timestamptz↔text`, `numeric↔real`, `boolean↔integer`, …).
- Maintain the explicit list of **mirrored** tables (Postgres-only tables are exempt).
- Wire it into CI so forgetting one side turns the build red.

## Milestone
CI fails on an intentional divergence and passes on the real schemas.

---

# Phase M5 — Setup wizard (connect-and-hydrate, developer-run)

## Goal
Replace the local Owner-creation wizard with a connect-and-hydrate flow.

## Tasks
- Import JSON → **encrypt at rest** with per-install `APP_KEY` → prompt to remove JSON.
- Connect as the branch role; **verify version compatibility** (§ version gate) before proceeding.
- Run local SQLite migrations.
- **Hydrate** baseline: full catalog, **all** users (business-wide), settings/identity, this
  branch's stock snapshot.
- Gate the app behind setup until hydration succeeds (extends the existing
  `RequireInitialSetup` behavior); no seeded local Owner.
- Audit the setup event.

## Milestone
A fresh device, given a JSON and internet, becomes a ready branch that logs in as the
pre-created Owner and can browse the hydrated catalog offline.

---

# Phase M6 — Sync engine: write path, outbox & push

## Goal
Local-first writes with reliable, idempotent upward sync.

## Tasks
- Introduce the **outbox** for local-write tables (sales, transaction_items, returns, defects,
  sale/return/defect ledger rows, login_history, local audit).
- Keep all offline-capable writes **SQLite-first**; generate the **per-branch serial** locally.
- **Push** = insert-only, UUID-keyed, idempotent upserts to Postgres.
- **Opportunistic post-checkout sync** when online.
- Surface **unsynced count** and last-sync status in-app.

## Milestone
Sales made offline appear in Postgres after reconnect; re-running a push is a no-op.

---

# Phase M7 — Sync engine: connectivity detection & pull + stock reconciliation

## Goal
Near-instant reconnect and correct bidirectional data flow.

## Tasks
- **Connectivity:** webview `online`/`offline` trigger + confirming **probe** + **adaptive
  polling** (fast offline / slow online) + configurable **backstop timer** (default 15 min) +
  manual "sync now".
- **Pull** deltas of catalog/users/settings/identity via `updated_at`/change markers.
- **Stock reconciliation:** pull the recomputed baseline and apply
  `effective = baseline − local unsynced out-movements` (never blind-overwrite).
- Enforce cycle order: **push → (queue, M8) → pull → heartbeat**.

## Milestone
Online catalog/user edits reach the branch within seconds of reconnect; local stock stays
correct across a sell-offline-then-sync cycle.

---

# Phase M8 — Inventory update queue

## Goal
Let online branch-targeted adjustments happen without ever driving stock negative.

## Tasks
- Online adjustments to a branch land in `inventory_update_queue` as **pending** (not yet a
  ledger entry).
- On sync, after pushing real movements, process the queue in order: **accept** (create ledger
  entry) when reconciled stock stays `≥ 0`; **reject** a subtractive adjustment that would go
  `< 0`.
- **Flag rejected adjustments in the in-app admin** (Owner/Admin screens) for review/re-issue.
- Grant `UPDATE` on `inventory_update_queue.status` to the branch role (still least-privilege).

## Milestone
A subtract-more-than-available adjustment issued while the branch is offline is rejected on
reconnect, stock stays `≥ 0`, and the in-app admin shows the flag.

---

# Phase M9 — Version gate & migrate-on-update

## Goal
Safe rollout of schema changes across independently-updated branches.

## Tasks
- Sync carries the app's schema version; compare against the schema's **min-compatible app
  version**; on incompatibility **refuse both push and pull** while the till **keeps selling**;
  surface "update required" in-app and version-behind in the console.
- Console command to apply pending Postgres migrations to **one or all** schemas, bumping
  schema/min-app versions appropriately (additive migrations don't raise min-app).

## Milestone
Migrating a schema ahead of an old app freezes only that branch's sync (not its selling);
updating the app realigns versions and flushes the backlog.

---

# Phase M10 — Monitoring: branch_status & console monitor

## Goal
Central visibility into every branch and instance.

## Tasks
- Each instance **upserts** its `branch_status` (app/schema version, last_sync_at,
  unsynced_count, last_seen_at, connection_state, last_error) every cycle.
- Console **monitor** view aggregates `branch_status` across all schemas.

## Milestone
The console lists every branch of every business with live version and sync health.

---

# Phase M11 — Consolidated cross-branch reporting

## Goal
Owner-facing, online, multi-branch reporting that recomputes totals.

## Tasks
- Online consolidated reports querying Postgres across all branches, **recomputing**
  sales − returns − defects (never a stored total).
- Enforce visibility at the app layer: Owner-facing, **Item Cost Owner-only** even here.
- Keep **offline** reports branch-local (unchanged).

## Milestone
The Owner sees correct combined figures across branches when online, per-branch figures offline,
with cost hidden from non-Owners throughout.

---

# Phase M12 — Hardening, testing & release verification

## Goal
Prove the whole loop and lock the security boundary before shipping the custom binary.

## Tasks
- Backend tests: provisioning, least-privilege grants (negative tests: no DELETE / no
  cross-schema), parity test, version gate, stock reconciliation, inventory-queue accept/reject,
  idempotent push, offline-login for any user.
- E2E: developer onboarding (provision → wizard → hydrate → sell offline → reconnect → sync →
  consolidated report), rotation (fresh AppImage + JSON), disable/enable.
- **Release verification:** confirm the packaged app contains **no** console code and **no**
  admin credentials; branch creds are runtime-only and encrypted at rest.
- Update `README.md` / release notes for the Evitan Plus build.

## Milestone
A green test suite and a verified build in which a single provisioned branch runs offline,
syncs on reconnect, and the console monitors it — with the powerful provisioning surface
provably absent from the shipped binary.

---

## Deferred (post-M12, tracked in `MULTIBRANCH-BRAINSTORM.md` §19)
- Multiple tills per branch (shared-stock coordination).
- Supabase Realtime channel (instant reconnect + live console↔branch monitor/command).
- OS network-change listeners.
- Auto credential rotation over a bootstrap channel.
- Cross-timezone branches.
