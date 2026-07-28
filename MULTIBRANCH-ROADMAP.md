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
  schema only). *As built (M2): the queue grant is four columns, not `status` alone — a check
  constraint makes `status` alone unusable. `BranchGrants` is the operative matrix; see M2's
  note and `MULTIBRANCH-BRAINSTORM.md` §7.*
- Original Evitan Plus invariants (immutable history, append-only ledger, ledger-derived
  inventory, never delete records, UUID PKs, one Owner, Item Cost Owner-only, negative
  inventory never allowed) remain in force.

---

# Phase M0 — Monorepo, console skeleton & environment separation ✅

## Goal
Establish the two-app layout and the security boundary before any provisioning logic exists.

## Tasks
- Add a separate Laravel app at `console/` (own `composer.json`, autoload namespace, `.env`).
- Wire the console's **direct admin Postgres connection** (superuser/service role) — used only
  by the console, never by the shipped app.
- Confirm/adjust the shipped app's **`pgsql` connection** in `config/database.php` (the custom
  binary carries the pgsql driver) and keep `sqlite` as the default local connection.
- **Vendor the custom PHP runtime:** ship a static PHP with both `pdo_sqlite` and `pdo_pgsql`
  under `build/php/` and point `NATIVEPHP_PHP_BINARY_PATH` at it — NativePHP's stock
  `nativephp/php-bin` has no Postgres driver, and the build bundles exactly one binary.
  Gated by `tests/Feature/Build/PhpBinaryTest.php`; see `build/php/README.md`.
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

# Phase M1 — Postgres business-schema definition & migrations ✅

## Goal
Define the authoritative per-business schema and the migration engine that builds it.

> **Done.** 19 Postgres migrations under `console/database/migrations/` build the schema;
> `schema:migrate` / `schema:version` create and report it. Two scoping calls were made
> against the task list below: there is **no `prices` table** (prices live on `variants` and
> are snapshotted onto `transaction_items`, exactly as the app models them), and returns and
> defects share one `sale_returns` table with a `type`, again mirroring the app. Two tables
> were added: `schema_meta` (the §13 version gate) and `import_history` (the app has it and
> it is business history). The §13 **parity test** was pulled forward from M12 — writing the
> second schema is when drift starts. See `console/tests/Feature/`.

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

# Phase M2 — Provision a business (schema + migrate + seed + role + JSON) ✅

## Goal
One console command turns owner/business/branch input into a live, connectable schema.

> **Done.** `business:provision` (plus `console:migrate` for the audit store) does the
> whole flow; `console/tests/` covers it, including the M12 negative-grant tests, pulled
> forward for the same reason M1 pulled the parity guard forward — grants are authored
> once, and that is the moment to prove them. Scoping calls and findings:
>
> - **Create-only.** An existing schema is a refusal, not an upgrade path; adding a
>   branch to an existing business is M3. This is what makes the failure path safe:
>   atomicity is a **compensating undo stack**, not one transaction (Laravel's migrator
>   commits per migration, `CREATE ROLE` is cluster-global on another connection, and
>   writing the JSON is not a database operation at all), so "drop the schema" can never
>   destroy data this run did not create. The original error is always what the operator
>   is shown; cleanup failures are appended with the exact SQL to finish by hand.
> - **The grant matrix is complete through M8** and lives in `BranchGrants`, enumerated
>   per table — no `ON ALL TABLES`, no `ALTER DEFAULT PRIVILEGES`, because both would
>   silently extend a branch's reach to any table a later migration adds. **Therefore M9's
>   migrate-on-update must call `BranchRole::reapplyGrants()` for every schema it
>   advances.** A test asserts the live grants equal the matrix exactly.
> - §7 says "UPDATE only on `inventory_update_queue.status`", which is **unusable**:
>   `check ((status = 'pending') = (resolved_at is null))` means status can never move
>   without `resolved_at` moving in the same statement. The grant is the four columns one
>   resolution touches (`status, resolved_at, rejection_reason, ledger_entry_id`) — still
>   far less than table-level UPDATE.
> - **Role name is `branch_<32hex of the BRANCH uuid>`.** Postgres roles are cluster-wide
>   while branch codes are unique only per business, so a code-derived name would collide
>   across businesses — and the collision would be one business's till authenticating into
>   another's role.
> - **`DROP OWNED BY` is not usable on Supabase**: the admin is not a superuser and gets
>   "Only roles with privileges of role … may drop objects owned by it". `BranchRole::drop()`
>   revokes explicitly instead, which needs only table ownership.
> - **Pooler usernames differ from role names.** Supavisor expects `<role>.<project-ref>`,
>   so the JSON carries both `role.name` and `connection.username`. Provisioning
>   **connects as the new role and reads the seeded rows before reporting success**, so a
>   JSON that cannot authenticate is impossible to ship silently.
> - **Console self-audit lives in `evitan_console`** (its own schema, own migration set,
>   own `console:migrate`), never in a `biz_*` schema — it is not business history, and the
>   parity guard enumerates what a business schema may contain. It is append-only by
>   trigger, so it binds the console itself. Provisioning **refuses to run** when it is
>   missing: an unauditable provision is not one this tool performs.
> - Owner password is developer-typed (spec-literal §8.1) with `must_change_password = false`.
>   Not written to the JSON or the audit trail. No RLS: within its own schema a branch
>   credential could still write rows tagged with another branch's `branch_id` — stated
>   plainly rather than overclaimed, and deferred.

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

# Phase M3 — Provision a branch & credential lifecycle ✅

## Goal
Add branches to an existing business and manage branch credentials.

> **Done.** Six commands — `branch:provision`, `branch:rotate` (with
> `--recreate`), `branch:disable`, `branch:enable`, `branch:revoke` and
> `branch:list` — over a shared `BranchIssuer`, plus a `credential_issuance`
> record in `evitan_console`. Scoping calls and findings:
>
> - **The schema is never in the undo stack.** This is the one line that
>   separates M3 from M2. `BusinessProvisioner` can compensate with
>   `DROP SCHEMA ... CASCADE` because it created the schema in the same run;
>   everything in M3 writes into a schema holding other branches' sales history.
>   So the undo is exactly three objects — one `branches` row keyed by a UUID
>   minted seconds earlier, one role, one file — and a failed cleanup prints
>   `BranchRole::dropRoleSql()`, **not** `cleanupSql()`, which starts with a
>   `DROP SCHEMA` that would be catastrophic here. `branches` carries no
>   immutability trigger (those cover the seven history tables), so the row
>   delete is permitted; it is the only DELETE in the console that touches a
>   business schema.
> - **Re-create folded into rotate**, as `branch:rotate --recreate`. They are
>   one act differing only in whether the role exists, and the flag is required
>   in **both** directions — rotating a missing role is refused without it, and
>   re-creating a live one is refused with it — so a rotation can never silently
>   become a re-creation. The audit still distinguishes `branch.rotate` from
>   `branch.recreate`.
> - **A rotated password cannot be rolled back.** The previous secret was never
>   stored (§9), so ordering is the whole mitigation: the credentials file is
>   reserved *before* the `ALTER ROLE`. A failure past that point reports
>   `cleanup_failed` and says plainly that the password HAS changed and that
>   re-running is safe, rather than implying a rollback that did not happen.
> - **A re-issue never rolls back on a failed verification** — the correction
>   that mattered most, and it was caught by the tests rather than by review.
>   Verification is the last step, and by then the role already holds a secret
>   that exists in exactly one place: the credentials file this run reserved.
>   The original code rolled back on a failed verify, which *discarded that
>   file* — leaving a branch whose password had genuinely changed with no way to
>   ever learn the new one. So a failed re-issue keeps everything and reports
>   `verified => false`, loudly and into the audit detail. A **provision** still
>   rolls back in full: it created the branch row, the role and the file in the
>   same run, so undoing all three destroys nothing that existed before.
> - **Supabase's pooler caches roles by username**, which produced two findings
>   the design had to absorb. `DROP ROLE` followed by `CREATE ROLE` under the
>   **same name** yields `invalid role OID` for **~126 seconds**, and a rotation
>   on a role with warm connections can lag too (the isolated probe said
>   "instant"; the suite disagreed) — hence the retry budget `--verify-wait`,
>   default 180s, on both re-issue paths. See `PoolerLag` for the timing table
>   and the three error wordings.
> - **`NOLOGIN` on its own does not disable anything**, which is the finding
>   with the sharpest edge: Supavisor satisfies new clients from its warm pool
>   without re-authenticating, so a "disabled" branch was measured **still
>   connecting after 90 seconds**. A disable that does not disable is worse than
>   no feature — the operator believes a suspect credential is dead while it is
>   still serving. `BranchRole::setLogin()` therefore also terminates the role's
>   backends, which makes cut-off immediate (measured at 0s with four warm
>   connections). `setPassword()` does the same, for the same reason — a
>   rotation is often performed *because* the old credential is suspect, and a
>   warm pooled session would keep it working. The till is unaffected either
>   way: a dropped connection is a failed sync, and a failed sync never stops it
>   selling.
> - **`branch:revoke`, not `branch:delete`** — a deliberate deviation from
>   §8.3's word. It deletes a *role*; the branch row, its sales and its ledger
>   are untouched and could not be deleted from here. `branch:delete` invites
>   exactly the wrong reading at exactly the wrong hour. The audit action stays
>   `branch.delete`.
> - **Disable is `LOGIN`-only.** `branches.disabled_at` is business state pulled
>   down to every till; a credential disabled on suspicion of a leak is not a
>   branch that has closed, and conflating them would make re-enabling a
>   credential silently reopen a branch. Left for a future closure command.
> - **`credential_issuance` was built** (proposed for M3 in
>   `docs/evitan-plus-erds.md` §3). `generation` and `superseded_at` could be
>   reconstructed from `provisioning_audit` later; the password **fingerprint
>   could not** — the secret is gone when the command exits. A partial unique
>   index enforces at most one live credential per branch, and a narrowed
>   append-only trigger allows `superseded_at` to move exactly once.
>   `provisioning_audit` needed no migration: its three outcomes already cover
>   M3.
> - **`branch:list` was added** though the task list does not name it: every
>   other command targets `--branch`/`--branch-code`, and there was nowhere to
>   read those from. It joins `branches` with `pg_roles`, which is what makes
>   "no role" and "disabled" distinguishable at all. It is not M10's monitor —
>   it reports what the console did, not what a branch is doing.

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
- **Connect per cycle, never continuously (§11.5).** Branches connect on `6543`, Supabase's
  **transaction** pooler — not the `5432` session pooler the console administers through.
  Transaction mode pools server backends, but each *connected client* still holds a Supavisor
  client slot (capped per tenant in the low hundreds), so a held-open connection re-imposes the
  very ceiling the port choice removes. Every cycle — including this phase's version gate and
  hydration — connects, runs, and `DB::disconnect('pgsql')`s in a `finally`. Assign the JSON's
  `connection` block **over** `config('database.connections.pgsql')` rather than replacing it,
  and assign `search_path` as-is (`null` — the schema is pinned server-side; see §9).
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
- **Push** = insert-only, UUID-keyed, idempotent upserts to Postgres. It runs inside a §11.5
  cycle: connect, push, disconnect. The connectivity probe that decides whether to sync must not
  itself open Postgres.
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
- ~~Grant `UPDATE` on `inventory_update_queue.status` to the branch role~~ — **already done in
  M2**, and as four columns (`status, resolved_at, rejection_reason, ledger_entry_id`), because
  a check constraint ties `status` to `resolved_at`. M8 writes the resolution logic against
  that existing grant; it does not issue a new one.

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
- **Call `BranchRole::reapplyGrants()` for every schema advanced.** M2 grants per table on
  purpose (see its note), so a migration that adds a table leaves every branch role without
  privileges on it until grants are re-applied.

## Milestone
Migrating a schema ahead of an old app freezes only that branch's sync (not its selling);
updating the app realigns versions and flushes the backlog.

---

# Phase M10 — Monitoring: branch_status & console monitor

## Goal
Central visibility into every branch and instance.

## Tasks
- Each instance **upserts** its `branch_status` (app/schema version, last_sync_at,
  unsynced_count, last_seen_at, connection_state, last_error) every cycle — inside that cycle's
  connection (§11.5), never on a heartbeat connection of its own. `connection_state` is derived
  from the last cycle's outcome, not from a socket held open to observe it.
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
