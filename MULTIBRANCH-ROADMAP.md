# MULTIBRANCH-ROADMAP.md

# Evitan Plus — Multi-Branch, Provisioning Console & Sync Roadmap

Phased build plan for the **multi-branch initiative** — the work that turns **Evitan POS**
(the shipped single-install product) into **Evitan Plus** (multi-branch, cloud-connected). See
`MULTIBRANCH-BRAINSTORM.md` for the spec of record. It follows the same principle as the
original `ROADMAP.md`: build each slice **end-to-end** (migration → backend → frontend/TUI →
validation → permissions/grants → audit → tests) and keep the system runnable at every phase.
Don't open several of these at once.

These phases follow the shipped `v1.0.0` (`ROADMAP.md` phases 0–19). They are numbered
**M0–M12** to keep them distinct from the original phases.

**Read `docs/glossary.md` before starting a phase.** It is **normative** for the vocabulary
these phases are written in, and it outranks both this file and `MULTIBRANCH-BRAINSTORM.md`
where they disagree about what a word means. A large share of M1–M3's "As built" corrections
were not design changes but a word meaning one thing in the spec and another in Postgres. The
terms most likely to be assumed wrong in M4–M12 specifically:

- **mirrored** (14 tables, parity-guarded) ≠ **readable** (18, `BranchGrants::SELECT`) ≠
  **pulled** (online-authoritative) — M4, M5, M7.
- **outbox** (this branch's work going up) ≠ **queue** (`inventory_update_queue`, adjustments
  coming down) — M6, M8.
- **app version** (semver, the build) ≠ **schema version** (integer) ≠ **min app version**. The
  gate compares *app version ≥ min app version*, and nothing else — M9.
- `inventory_ledger` has **three** type columns, not one six-valued one, and **`restock` is not
  a value** — M6, M7, M8.
- **revoke** (drops a role) ≠ **delete** ≠ **disable** (`LOGIN` only) ≠ **archive** (app-side).

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
>
> **Corrected in M3.2** (see M3's note below and `MULTIBRANCH-BRAINSTORM.md` §8): the undo
> stack did not in fact cover the schema — it was pushed *after* the migration, leaving the
> whole create-plus-19 window uncompensated — and `business:provision` never wrote the
> `credential_issuance` row, so a business's first branch was a live credential the ledger
> had no row for and its first rotation claimed generation 1 twice.

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
> - **`credential_issuance` was built** (`console/database/migrations-admin/`).
>   `generation` and `superseded_at` could be
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
>
> **As built (M3.2) — the console front door.** `console:run` is now the default command, so
> a bare `php console/artisan` opens an interactive menu: pick a business, pick a branch, pick
> an action. This is what `MULTIBRANCH-BRAINSTORM.md` §8 specified from the outset and what
> M2/M3 did not build. It retires the error class the `--business` XOR `--schema` /
> `--branch` XOR `--branch-code` targeting machinery exists to guard — you cannot mistype a
> UUID you selected from a list — and it is a navigator, not a reimplementation: every action
> dispatches the real command through `call()`, never with `--force`, so the warnings and
> confirmations above are inherited rather than restated. `branch:list` and `schema:version`
> stop being actions and become the screens.
>
> **Sixteen defects in the M2/M3 commands became visible once every console read was on one
> screen**, several worse than the two that prompted the audit. Every one of them lived on a
> path no operator had walked. The three that change what a phase note above claims are
> annotated in `MULTIBRANCH-BRAINSTORM.md` §8.1, §8.3 and §8.4 — the uncompensated migration
> window, the missing first-branch issuance row plus rotate-on-disabled blaming pooler lag,
> and `--pretend` performing real DDL. Also here: `BranchRole::drop()` ran its two `REVOKE`s
> and its `DROP ROLE` outside a transaction, so a failed drop left a **live role stripped of
> every privilege** — a state no `connection_state` can describe, presenting as offline while
> online — with the audit saying `rolled_back`. See `d86255e` for the full list.
>
> **`schema:migrate` still does not re-apply branch grants** — M9, and deliberately still
> deferred. But the menu had promoted it to a first-class action with no caveat, which is
> worse than the CLI was, so it now warns and confirms.

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

# Phase M4 — Schema-drift parity test ✅

## Goal
Guarantee the SQLite mirror and Postgres schema agree on the mirrored tables.

> **Done.** The guard now runs as part of `composer verify:console`, the SQLite
> side has an allowlist for the first time, and the comparison itself is tested.
> Scoping calls and findings:
>
> - **The guard existed since M1 and had never run once.** Three phases of "the
>   parity test covers that" were true only of a developer remembering to run it
>   by hand, and nobody did. The cause is narrower than it first looks and worth
>   stating exactly: **no command invoked it.** `composer verify:console` was
>   `--testsuite=Unit`, and `composer verify` does not touch the console at all.
>   The test was reachable — the credentials were on the machine the whole time —
>   but nothing reached for it. *A guard nothing calls is indistinguishable from
>   one that was never written.*
> - **The fix is one line in `composer.json`, and the first attempt was much
>   bigger.** `verify:console` now runs the whole suite, Unit and Feature, with
>   `--fail-on-skipped`. What was built first: a GitHub Actions workflow with a
>   `postgres:17` service container, a third `tests/Postgres/` suite split out so
>   a hosted runner could run the half that needs no Supabase, and a generated
>   `console/.env` pointing at the container. All of it reverted. The reversal is
>   the more instructive half, and it turned on noticing that CI was never the
>   missing piece — the failure was a command that did not exist, and no amount of
>   remote machinery addresses that.
> - **This repo now runs no CI at all** (`.github/` is gone, both workflows
>   deleted). The local chain was already a strict superset: `composer verify`
>   runs `composer audit`, `pint --test`, phpstan, the app suite, `tsc`, eslint,
>   prettier and the Playwright e2e specs; the workflow ran the app suite and the
>   console `Unit` half and **none** of the other six. `lint.yml` was worse than
>   redundant — it ran `pint` and `npm run format` in *write* mode with the
>   commit-back step commented out, so it reformatted files on a rented machine
>   and discarded them, structurally incapable of failing. What CI uniquely
>   offered was the from-empty-directory build; `native:build` already covers
>   that, and covers it on the artifact that actually ships.
> - **The earlier attempt to point CI at the real project is the cautionary
>   tale.** It meant admin credentials to production in a secret store, unattended
>   writes, **serialising** through a `concurrency` group against whoever was
>   working locally, and a leaked schema every time a run was cancelled before
>   `tearDownAfterClass`. That last one grew a 149-line `schema:prune-test`
>   command, a timestamp in every test schema name so the sweep could tell stale
>   from in-flight, and a 166-line test that itself created a fake `biz_<hex>`
>   business schema in production on every run — ~330 lines of custodial machinery
>   guarding nothing but the litter the test run generated. All deleted. The
>   surviving cleanup is `tearDownAfterClass` plus a one-line manual sweep
>   documented in `Tests\TestCase`.
> - **There is one database, and it is Supabase.** `SchemaParityTest` and
>   `SchemaInvariantsTest` would run against any Postgres — stock DDL, no
>   extension, no `auth.` schema, no Supabase-only function — and that fact was
>   what justified the container. It is still true and no longer load-bearing: a
>   second server is an install to maintain, a version to keep matched (the
>   project reports 17.6) and a class of failure that reproduces only where the
>   tests do not run. The suite is slower against a remote database and that is
>   the whole cost.
> - **`SQLITE_LOCAL_ONLY` was the missing half of a check that did not exist.** The
>   test read only the 14 mirrored tables out of SQLite, so "is this table classified?"
>   was asked of Postgres and never of the local mirror — a new local table was not
>   un-allowlisted, it was invisible. M6's `sync_outbox` would have drifted in
>   unnoticed. This settles `MULTIBRANCH-BRAINSTORM.md` §11.6 in favour of its own
>   recommendation: a local-only table is deliberately a *different, narrower* table,
>   so M8's local queue goes on this list rather than into `MIRRORED`.
> - **`sqlite_sequence` is on the list and nothing in the migration set mentions it.**
>   Laravel's migration repository uses `increments('id')`, SQLite implements that as
>   `AUTOINCREMENT`, and SQLite materialises the table itself. It only surfaced because
>   the check enumerates *real* tables via `sqlite_master` rather than expected ones —
>   which is the difference between a guard and a restatement of the author's beliefs.
> - **The comparison is now tested against invented schemas** (`Tests\Support\SchemaDiff`
>   + `Tests\Unit\SchemaDiffTest`). A green parity run proves the schemas match; it is
>   equally consistent with the comparison having stopped checking. The unit half is
>   credential-free, so the guard-for-the-guard runs on a fresh clone with no
>   database at all. Note in particular that an **unmapped SQLite type must fail**:
>   `TYPE_MAP[$t] ?? null` followed by a skip reads as caution and behaves as a
>   hole.
> - **Both allowlists are checked in both directions.** A surplus table means nobody
>   decided about it; a *missing* one means a migration was dropped, which no
>   permission-shaped check would ever notice.
> - **Verified by breaking it three ways** — a column on the SQLite side only, the same
>   column on the Postgres side only, and a stray local table — each producing the
>   intended failure, then reverted. Plus a fourth check that only matters now that
>   the gate is a local command: blanking `CONSOLE_DB_PASSWORD` must make
>   `verify:console` exit **non-zero**, not green-with-skips. One sharp edge
>   survives from the discarded workflow and is worth keeping in mind whenever
>   `console/.env` is written by anything but a human: quote values **single**,
>   because phpdotenv interpolates `${...}` inside double-quoted values and would
>   silently rewrite a password containing those characters into a different
>   string — surfacing as a wrong password, not as a quoting bug.

## Tasks
- Build an automated **parity test** that introspects both schemas and asserts the mirrored
  tables match on column names and types via a known type map
  (`uuid↔text`, `timestamptz↔text`, `numeric↔real`, `boolean↔integer`, …).
- Maintain the explicit list of **mirrored** tables (Postgres-only tables are exempt).
  *As built: **two** exemption lists, one per side — `POSTGRES_ONLY` and the new
  `SQLITE_LOCAL_ONLY`.*
- Wire it into a gate so forgetting one side turns the run red. *As built: into
  `composer verify:console`, not CI — see the findings above.*

> **"Mirrored" here means structural parity — not "readable" and not "pulled".** This is the
> phase that makes the word machine-enforced, so it is the one that must not blur it:
> `SchemaParityTest::MIRRORED` is **14** tables, `BranchGrants::SELECT` is **18**, and the
> online-authoritative set is a third list again. `branch_status` and `inventory_update_queue`
> are readable but deliberately *not* mirrored. `docs/glossary.md` §3. *As built: **four** sets,
> not three — `SQLITE_LOCAL_ONLY` is the fourth, and it now has a glossary entry too.*
>
> ~~Note the guard was **pulled forward into M1** … so this phase is largely already done.~~
> **That reading was wrong, and it is the finding of the phase.** The guard was written in M1
> but no command ran it, so it had never once fired — "largely already done" described code
> that existed, not a check that ran. Putting it behind `verify:console` was not a finishing
> touch on a working guard; it was the entire difference between having one and believing
> you did.
>
> The `MULTIBRANCH-BRAINSTORM.md` §11.6 open item is **settled** in favour of its own
> recommendation: `SQLITE_LOCAL_ONLY` exists, so M8's local `inventory_update_queue` — a
> *narrower, different* table — goes there rather than into `MIRRORED`.

## Milestone
`composer verify:console` fails on an intentional divergence and passes on the real schemas.

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
- **Hydrate** the baseline: full catalog, **all** users (business-wide), settings/identity, this
  branch's stock snapshot. *Hydrate* is the one-time first fill of a new branch device — not a
  pull, which is the recurring delta. It is re-runnable from scratch, because the local mirror
  is the only store in the system that can be thrown away.
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
- Introduce the **outbox** for local-write tables (transactions, transaction_items,
  sale_returns, sale_return_items, `type = 'sale'` ledger rows, login_history, local audit).
- Keep all offline-capable writes **SQLite-first**; generate the **per-branch serial** locally.
- **Push** = insert-only, UUID-keyed, idempotent upserts to Postgres. It runs inside a §11.5
  cycle: connect, push, disconnect. The connectivity probe that decides whether to sync must not
  itself open Postgres.
- **Opportunistic post-checkout sync** when online.
- Surface **unsynced count** and last-sync status in-app.

> **Three vocabulary points this phase turns into code** (`docs/glossary.md` §4–§5):
>
> - **The outbox is not the queue.** `sync_outbox` holds this branch's work going **up**;
>   `inventory_update_queue` holds adjustments issued elsewhere coming **down** (M8). They share
>   no code and move in opposite directions.
> - **What belongs in the outbox is `type = 'sale'` ledger rows** — any `sale_type`
>   (`sale`/`return`/`defect`). There are no "returns" or "defects" tables to push (both are
>   `sale_returns` + `sale_return_items` with a `type`), and no "restock" rows to exclude
>   (`type = 'stock_adjustment'` is online-authoritative and pulled, never pushed).
> - **`unsynced_count` is derived, not maintained**: `count(*) where synced_at is null` in the
>   outbox. Do not add a counter — one definition, nothing to drift.
>
> **Settled: `import_history` does not sync upward, because CSV import becomes online-only.**
> An in-app import writes `type = 'stock_adjustment'` ledger rows, which are online-authoritative
> and pulled down — so the outbox would not carry them, and a branch importing while offline
> would create stock movements that could never reach Postgres and would diverge silently and
> permanently. Pushing them instead would give `stock_adjustment` rows two writers, breaking
> single-writer-per-table. So the existing grants (SELECT, no INSERT) are correct and stay.
>
> **This phase therefore owes one behavioural change:** gate the shipped v1.0.0 CSV import
> screen behind connectivity, so it refuses cleanly offline rather than silently producing
> unsyncable rows. See `MULTIBRANCH-BRAINSTORM.md` §17.
>
> **The serial generator needs a real change here, not a cosmetic one.**
> `TransactionNumberGenerator` today does `(int) max(transaction_number) + 1`. Once the number
> is `MNL-0000000001` (§12), `(int) "MNL-…"` is **0**, and every sale tries to claim serial 1.
> The generator must strip the branch prefix off the max — or better, store the numeric serial
> separately from the rendered number. Worth flagging early because it fails *silently* into a
> unique-constraint retry loop rather than into an obvious error.

## Milestone
Sales made offline appear in Postgres after reconnect; re-running a push is a no-op.

---

# Phase M7 — Sync engine: connectivity detection & pull + stock reconciliation

## Goal
Near-instant reconnect and correct bidirectional data flow.

## Tasks
- **Connectivity:** webview `online`/`offline` trigger + confirming **probe** + **one
  fixed-interval backstop poll** (same rate online and offline, no knob) + manual "sync now".
  §11.3 is normative on why it is one interval and not adaptive polling plus a configurable
  timer.
- **Pull** deltas of catalog/users/settings/identity via `updated_at`/change markers.
- **Stock reconciliation:** pull the recomputed **stock baseline** and apply
  `reconciled stock = baseline − local unsynced out-movements` (never blind-overwrite).
- Enforce cycle order: **version gate → push → (queue, M8) → pull → heartbeat**.

> **Baseline, snapshot and reconciled stock are three numbers, and this phase uses all three**
> (`docs/glossary.md` §4): the **stock baseline** is what Postgres hands back, a **stock
> snapshot** is a `stock_snapshots` row updated on every ledger insert, and **reconciled stock**
> is the subtraction — the figure the till sells against. The reconciliation is **additive, not
> conflict resolution**: there is no last-write-wins anywhere in this system.
>
> **Pull applies to *pulled* tables**, i.e. online-authoritative ones — not to everything
> "mirrored". `inventory_ledger` is mirrored yet is the one table both sides insert into, and
> only its `type = 'stock_adjustment'` rows come down.

## Milestone
Online catalog/user edits reach the branch within seconds of reconnect; local stock stays
correct across a sell-offline-then-sync cycle.

---

# Phase M8 — Inventory update queue

## Goal
Let online branch-targeted adjustments happen without ever driving stock negative.

> **Settled: an Owner or Admin, on any branch device, while online, may target any branch of
> their business.** They pick the target branch in the in-app admin screens; the queue row
> records it (`branch_id`) and the issuing user (`issued_by`). The undefined actors "online
> admins" (`MULTIBRANCH-BRAINSTORM.md` §10) and "HQ" (`BranchGrants.php`,
> `SchemaParityTest::POSTGRES_ONLY`) both meant exactly this.
>
> Two consequences this phase must carry:
>
> - **Add `inventory_update_queue` to `BranchGrants::INSERT` and re-run
>   `BranchRole::reapplyGrants()` across existing schemas.** No branch role holds INSERT on it
>   today — only the console's admin connection can create a row — so without this the feature
>   is unreachable from the screens meant to offer it. Update the comment in `BranchGrants` that
>   currently says "HQ issues the rows" at the same time.
> - **This widens the deferred RLS gap, knowingly.** A branch credential able to insert a queue
>   row for its own branch can insert one for any branch of the same business, since grants stop
>   at the schema boundary. Same exposure class as forged `branch_id` on history rows (§4's
>   annotation); one more table reaches it. Record it, don't quietly absorb it.

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
- Compare the device's **app version** (`config('nativephp.version')`, semver) against the
  schema's **min app version** (`schema_meta.min_app_version`, semver). On
  `app version < min app version`, **refuse both push and pull** while the till **keeps
  selling**; surface "update required" in-app and version-behind in the console.

> **This task previously read "sync carries the app's schema version"**, which named neither
> value in the comparison. There are **three** quantities and the gate tests exactly one pair
> (`docs/glossary.md` §6): **app version** (semver, the build), **schema version** (integer,
> `schema_meta.schema_version`) and **min app version** (semver). The schema version is
> *recorded* by both sides — `branch_status.schema_version`, `instance.schema_version` — and is
> never what the gate compares. Building this from the old sentence would produce a gate that
> compares the wrong pair and refuses (or admits) the wrong branches.
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
Central visibility into every branch of every business.

## Tasks
- Each branch device **upserts** its `branch_status` (app/schema version, last_sync_at,
  unsynced_count, last_seen_at, connection_state, last_error) every cycle — inside that cycle's
  connection (§11.5), never on a heartbeat connection of its own. `connection_state` is derived
  from the last cycle's outcome, not from a socket held open to observe it.
- Console **monitor** view aggregates `branch_status` across all schemas.

> **Settled: `connection_state` carries four values on both sides** —
> `online | offline | unauthorized | version_blocked` — so the local `sync_status`
> (`MULTIBRANCH-BRAINSTORM.md` §11.6) and `branch_status` agree and the heartbeat is a straight
> copy. Collapsing to `online | offline` would make a branch you disabled and a branch whose
> internet is down the same row in the monitor, and those need opposite responses. The same
> distinction matters at the till, where all three failure kinds look identical and only one is
> fixed by waiting.
>
> **No migration is needed** — the column is a plain `varchar` with no check constraint. Adding
> that constraint is worth doing here, since it is the only thing that makes the set enforceable
> rather than conventional, and this phase is the first code to write the column.
>
> Note this is the **monitor**, not `branch:list` (M3): that command reports what the console
> did, this reports what a branch is doing.

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
- Update `README.md` / release notes for the Evitan Plus build — including the product split
  itself: `README.md` still describes the single-install **Evitan POS** feature set ("no server,
  no cloud, no network dependency"), which stays true of `v1.0.0` and is false of Evitan Plus.

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
