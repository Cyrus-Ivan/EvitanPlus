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
>   plainly rather than overclaimed. Since settled as an **accepted risk that is not planned**
>   (`docs/glossary.md` §9), not a deferred item.
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

# Phase M5 — Setup wizard (connect-and-hydrate, developer-run) ✅

## Goal
Replace the local Owner-creation wizard with a connect-and-hydrate flow.

> **Done.** A device given a credentials JSON and internet becomes a working
> branch: `/setup` imports the file, connects as the branch role, clears the
> version gate, copies the baseline into SQLite, seals the credential and records
> who the device is. `App\Support\Sync\` holds the pieces — `CredentialsDocument`,
> `BranchCycle`, `VersionGate`, `Hydrator`, `CredentialVault`, `ConnectAndHydrate`
> — and the first three are the primitives M6–M8 build their cycles on, not
> setup-only code. Scoping calls and findings:
>
> - **Nothing is persisted until the whole cycle succeeds**, and that decision
>   removed more design than it added. The hydrated rows, `instance`,
>   `instance_secret` and the audit entry are one SQLite transaction with the
>   Postgres work inside it, so a failed import leaves the device exactly as it
>   was. What that buys is the absence of a state machine: no "credentials
>   imported but not hydrated" screen, no retry route, no discard action, no way
>   for a half-run to be discovered later. Every failure has the same remedy —
>   fix what the message says, submit again — and the message is the whole
>   remedy because there is nothing to clean up first. It mirrors
>   `BusinessProvisioner`, which verifies before reporting success for the same
>   reason.
> - **The fresh-install gate moved from "no user exists" to
>   `instance.hydrated_at`.** The old question was right for a wizard that
>   *created* the Owner and is wrong now: users arrive *during* hydration, so
>   their presence would mark a device ready part-way through the copy. Because
>   the stamp shares the hydrate's transaction it cannot be half-true. The cost
>   landed in the test suite, in one place: `Tests\TestCase` seeds a hydrated
>   instance for anything using `RefreshDatabase`, or all 500-odd feature tests
>   would be funnelled to `/setup`. **It must not hang off
>   `afterRefreshingDatabase()`** — a trait's method beats an inherited one, so
>   `RefreshDatabase`'s own empty version of that hook silently replaces the
>   parent's, and the failure presents as "the gate ignores the instance row".
> - **The JSON arrives by path, not upload**, reusing `DirectoryPicker` (the
>   backup/restore picker) for a **Browse** button in the desktop build. The
>   reason is the one-time rule: knowing the real path is what lets the wizard
>   *delete* the credential after importing it, which §9 asks for and an upload
>   cannot offer. The checkbox is on by default and the toast says which happened.
> - **The copy is column-driven** — local `pragma table_info` names the columns,
>   exactly those are selected from Postgres — so a mirrored column added later
>   needs no edit in the hydrator. That is only safe because M4's parity guard
>   proves both sides carry the same columns; the guard and this are the same
>   decision seen twice.
> - **Two value shapes had to be converted, and both fail silently if missed.**
>   `pdo_pgsql` can return booleans as `'t'`/`'f'`, and `(bool) 'f'` is **true** —
>   a `must_change_password` of false would force a password change on every user
>   of a brand-new device. And `timestamptz` arrives with an offset while SQLite's
>   datetime columns hold naive UTC. Both are handled by normalising against the
>   local declared type, and the integration test asserts both against the real
>   database, because neither reproduces against a stand-in.
> - **`settings` is the one table the local mirror is deliberately WIDER than
>   Postgres**, because printer configuration lives in it and is device hardware.
>   So settings is upserted by `key`, restricted to `BusinessSettings::KEYS`;
>   a whole-table copy would erase the printer the developer had just configured.
> - **Prices confirmed a pre-existing property rather than a bug.** SQLite's
>   NUMERIC affinity stores `'25.50'` as `25.5`; it does that for a price the POS
>   writes itself too, and the `decimal:2` cast is what makes both read back
>   identically. The assertion goes through the model, because the requirement is
>   that a hydrated row is indistinguishable from a locally written one.
> - **History is not hydrated** (§16 lists catalog, users, settings, stock only),
>   and the consequence needs recording before M6: a device replacing an EXISTING
>   branch's hardware starts with empty branch-local reports, and — since the
>   serial is derived from `max(transaction_number)` — would restart its receipt
>   numbering at 1 and collide with rows already in Postgres. Fine for M5's
>   fresh-device milestone; **M6 must not assume a replacement device is the same
>   case as a new one.**
> - **A third gate exists: `composer verify:hydrate`.** It provisions a throwaway
>   business through the console CLI, runs the real wizard route against the
>   emitted JSON, checks the local mirror against what was provisioned, and drops
>   the schema and role. Tagged `supabase` and excluded from `composer verify`
>   (`--exclude-group=supabase`) so a fresh clone stays green, and run with
>   `--fail-on-skipped` so on a credentialed machine a silent skip is a failure —
>   M4's lesson, applied at the moment of writing rather than three phases later.
> - **Verified in a browser as well as in tests**: the wizard was driven headless
>   against a real provisioned business — funnel → import → hydrate → log in as
>   the pre-created Owner → the hydrated catalog on the POS screen with this
>   branch's stock quantities, then the schema and role dropped. The one path no
>   suite covers is the native file dialog itself, which is the same
>   `Dialog::new()->files()` call backup/restore has shipped since Phase 17.

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
- Run local SQLite migrations. *As built: nothing new — `LaunchProvisioner` already migrates on
  every launch, before the first page load, so the wizard inherits a current schema.*
- **Hydrate** the baseline: full catalog, **all** users (business-wide), settings/identity, this
  branch's stock snapshot. *Hydrate* is the one-time first fill of a new branch device — not a
  pull, which is the recurring delta. It is re-runnable from scratch, because the local mirror
  is the only store in the system that can be thrown away.
- Gate the app behind setup until hydration succeeds (extends the existing
  `RequireInitialSetup` behavior); no seeded local Owner. *As built: the gate is
  `instance.hydrated_at`, not "no user exists" — see the note above for why the old question
  stopped being answerable.*
- Audit the setup event. *As built: `system.setup_completed`, actor `null` — a system event on a
  device where nobody has signed in yet, carrying the per-table hydrated counts.*

## Milestone
A fresh device, given a JSON and internet, becomes a ready branch that logs in as the
pre-created Owner and can browse the hydrated catalog offline.

---

# Phase M6 — Sync engine: write path, outbox & push ✅

## Goal
Local-first writes with reliable, idempotent upward sync.

> **Done.** Sales made offline appear in Postgres after reconnect and re-running a push is a
> no-op. `App\Support\Sync\` gained `Outbox`, `Pusher`, `ConnectivityProbe` and `SyncCycle`;
> `sync_outbox` and `sync_status` are the first two of §11.6's proposed tables to exist;
> `POST /sync` is the one entry point, and the header carries an indicator on every
> authenticated screen. Scoping calls and findings:
>
> - **The receipt serial now carries its branch code, stored, not rendered.**
>   `transaction_number` is `MNL-0000000001` in both schemas. The alternative — store the bare
>   serial and add the prefix at print time — was rejected on the project's own history: a value
>   with two spellings is the failure class `docs/glossary.md` exists to prevent, and a customer
>   reading a number back off a receipt must be able to find it. `return_number` keeps **no**
>   prefix: §12 asks for one on the receipt serial only, and Postgres already scopes returns to
>   `(branch_id, return_number)`.
> - **The `(int)` trap was real and the roadmap was right to flag it early.** It does not
>   surface as an error: `(int) "MNL-0000000009"` is 0, every sale then claims serial 1, the
>   UNIQUE index rejects it, and `PosController`'s bounded retry absorbs the whole thing. The
>   symptom is checkouts that intermittently fail for no stated reason. Pinned by a regression
>   test rather than by the docblock that describes it.
> - **A replacement device would have lost its first day's takings, silently.** Hydration
>   copies no history, so a device swapped in for existing hardware restarts its serial at 1
>   against numbers Postgres already holds — and the push is insert-only, so the collision is
>   absorbed rather than raised: a clean sync, a zero backlog, and the sales gone. M5's note
>   said "M6 must not assume a replacement device is the same case as a new one"; this is what
>   that meant. `Hydrator::serialFloors()` reads the branch's high-water marks at setup and both
>   generators continue from them.
> - **Both of Laravel's insert helpers are wrong here, in opposite directions.** `upsert()`
>   compiles to `on conflict (...) do update set ...` on Postgres with no way to ask for
>   anything else, and every pushed table carries a BEFORE UPDATE immutability trigger, so a
>   re-push would raise. `insertOrIgnore()` compiles to a **bare** `on conflict do nothing` with
>   no target, which absorbs a conflict on *any* unique constraint — including
>   `(branch_id, transaction_number)`, i.e. exactly the collision above. The statement is
>   therefore hand-written as `on conflict ("id") do nothing`: a repeat of the same row is
>   absorbed, a different row wearing a taken serial raises. Proved both ways in
>   `tests/Integration/PushTest.php`.
> - **Timestamps were being converted wrongly in BOTH directions, and only the round trip
>   showed it.** This app has stored **naive business-local** strings since v1.0.0
>   (`ApplyBusinessTimezone` sets the default timezone at boot), not naive UTC. M5's hydrator
>   converted incoming `timestamptz` to UTC, so every hydrated row read eight hours early in
>   Manila; M6's first push stamped `+00` on local values, pushing every sale eight hours the
>   other way. Each was individually plausible — the dates sorted correctly and looked like
>   dates — and `HydrationTest` asserted only the *shape* of the string, which is why it passed
>   for a whole phase. Both now convert against the business timezone, the hydrator resolving it
>   from the `settings` row it copies first, and `HydrationTest` asserts the value.
> - **`branch_id` is not on every mirrored table**, which the first real push discovered.
>   `transaction_items` and `sale_return_items` inherit their branch from their parent row.
>   `Pusher` asks `information_schema` which tables have the column rather than carrying a list —
>   the same reasoning as the column-driven payload, and for the same reason: a hand-kept list is
>   a fourth place to remember and the one nothing checks.
> - **Postgres `stock_snapshots` is maintained here, and it exposed a gap worth recording.**
>   Postgres has no trigger behind that table; the branch role holds INSERT and UPDATE on it
>   because the till is what maintains it. The push recomputes it from Postgres's **own** ledger
>   — idempotent, and authoritative rather than a copy of the local figure, which from M7 will be
>   *reconciled* stock and deliberately different. But that is only correct while Postgres's
>   ledger is complete, and **`business:provision` seeds no opening stock at all**. Seed a
>   snapshot without the movement that produced it and the recompute reads the sum of this
>   branch's sales alone, goes negative, and `stock_snapshots_non_negative` refuses the entire
>   cycle — a branch that can never sync. So: **whatever puts opening stock into a business
>   schema must write a ledger entry, not just a snapshot.** That binds M8's queue (which
>   already creates a ledger entry on acceptance) and any future online CSV import.
> - **`row_id`, not `id`, in the outbox** — a deviation from §11.6's ERD. The table's key is
>   `sequence`, and an Eloquent model whose `id` attribute is not its key is a trap: `find()`,
>   `$entry->id` and every relation read as though they meant the outbox entry when they mean
>   the sale.
> - **Enqueue hangs off the model, not the write sites.** `PosController::persistSale`,
>   `ProcessSaleReturn`, `StockLedger::record`, `AuditRecorder` and `LoginHistoryRecorder` are
>   five places today and a sixth is one feature away. A path that forgets to enqueue does not
>   fail — it sells, prints, and the sale never reaches Postgres. `PushedUpward` on the seven
>   local-write models makes forgetting require bypassing Eloquent entirely.
> - **The probe is HTTPS to the project's REST endpoint**, never a Postgres connection (§11.3).
>   The project ref comes from `connection.username`, which Supavisor requires to be
>   `<role>.<project-ref>`. **Any** HTTP answer counts, 401 included: something spoke HTTP, so
>   the link is up. An underivable endpoint answers "reachable" rather than "offline" — a probe
>   that guessed offline would leave such a device unable to sync at all, reporting a network
>   error that never happened.
> - **`sync_status` carries all four `connection_state` values now**, not M10's two. A revoked
>   credential, a version-blocked build and a dead connection are indistinguishable at the till
>   and only one is fixed by waiting. M10's heartbeat becomes a straight copy.
> - **The manual "Sync now" button landed here** though §11.3 files it under M7: it is the same
>   route as the opportunistic sync and the only way to drive any of this by hand.
> - **`Tests\TestCase` gained a `$connected` switch** alongside M5's `$hydrated`, for the same
>   reason: CSV import is now online-only, so without a default answer the whole suite would
>   test it in its refusing state.

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
>
> *As built: the strip, not the separate column. The prefix is **stored**, so there is one
> number with one spelling everywhere and no second column to keep in step; and the max is
> compared as a string, which is exact because every candidate carries the same prefix and the
> same fixed-width padding. The floor added for replacement devices goes through the same
> comparison.*

## Milestone
Sales made offline appear in Postgres after reconnect; re-running a push is a no-op.

---

# Phase M6.5 — Online writes: the upward path for shared data ✅

## Goal
Make a change to shared business data — the catalog, staff, settings — reach the business
database instead of the device it was typed on.

> **Done.** A product created at one branch now appears at another; that is proved end to end
> in `tests/Integration/OnlineWriteTest.php` against two real provisioned branches, and it is
> the first time this system does the thing the initiative is named for.
>
> **This phase should not have existed, and how it came to is the finding.**
> M6 built the upward path for what a branch *authors* (sales, returns, logins). M7 builds the
> downward path for what the business *shares*. Nobody built the upward path for shared data,
> and the reason is a single sentence: `MULTIBRANCH-BRAINSTORM.md` §5.1 states that the till
> "**never** edits them locally". That is a claim about the shipped app which has never been
> true — Item, Category, User and Settings CRUD have written to local SQLite since v1.0.0. The
> data model was built on it, so no sync phase had a reason to consider the case, and the work
> needed is not a sync feature but a rewrite of five v1.0.0 CRUD paths — which falls between
> `ROADMAP.md` (pre-Postgres) and every M-phase (sync, provisioning, console).
>
> §17 examined exactly this for CSV import and settled *"Keeping import online-only resolves it
> without weakening anything"*, treating "requires internet" as equivalent to "reaches
> Postgres". It is not, and being written down as settled is why it stopped being questioned.
> M6 implemented that conclusion faithfully and gated the screen; the rows still went nowhere.
>
> Scoping calls and findings:
>
> - **The grant matrix had to widen, and §7's least-privilege claim weakens with it.** No branch
>   role held INSERT or UPDATE on `categories`, `items`, `variants`, `users` or `settings`, so
>   §5.1's "edited online … in-app admin" described an operation the credential could not
>   perform. A leaked branch credential can now rewrite that one business's catalog, prices and
>   staff, where before it could only append history. It still cannot delete anything, reach
>   another business, or run DDL. **DELETE stays granted on nothing** — archiving is
>   `UPDATE archived_at` and removing a user is `UPDATE deleted_at`, so the never-delete
>   invariant costs nothing here.
> - **`schema:reapply-grants` finally exists.** `BranchRole::reapplyGrants()` was written in M2
>   and *nothing had ever called it*; the only way to give an existing branch a privilege was
>   `branch:rotate`, which changes the password and so obliges a fresh AppImage and a new JSON
>   to every till — an enormous price for a GRANT. Widening the matrix for
>   already-provisioned businesses forced the issue. This retires the caveat the console menu
>   carried on its upgrade action and pulls M9's deferred call forward.
> - **`ON CONFLICT DO UPDATE` requires the UPDATE privilege**, which cost an hour and is the
>   kind of thing that presents as the wrong problem. Upserting an `inventory_ledger` row fails
>   with `permission denied for table inventory_ledger` — reading as a missing grant when it is
>   the matrix being exactly right. Append-only tables get `do nothing`; only genuinely mutable
>   ones get `do update`.
> - **The safety story needed no M7.** An earlier reading had this waiting for the pull, on the
>   grounds that a local write failing after Postgres succeeded needs the downward flow to
>   repair it. `ConnectAndHydrate` already ships the answer: put the Postgres work *inside* the
>   local SQLite transaction and write locally first, so any failure at either end leaves the
>   device untouched. The residual window is a local COMMIT failing after Postgres accepted —
>   the same one hydration has always accepted, self-healing because the local mirror is
>   disposable.
> - **CSV import is now the first path that puts opening stock into a business schema as a
>   ledger row**, which M6's note said everything else would be bound by. Its
>   `type = 'stock_adjustment'` rows go straight up, synchronously — §17's two-writer objection
>   was about *queueing* them, which this does not do — and `RemoteStock::recompute()` then
>   rebuilds the upstream stock cache Postgres keeps no trigger for.
> - **The `conflict` error kind, separately unclaimed.** §11.6 names four `last_error_kind`
>   values and M6 shipped three, so a push refused by the DATA (a duplicate serial, a missing
>   grant) was reported as `offline` — telling staff to wait for a connection they already had
>   while the backlog grew. Classified by SQLSTATE class: 23 (integrity) and 42 (access rule,
>   i.e. a missing grant) are conflicts and pair with `connection_state = online`; 08 stays a
>   network fault. `PushTest`'s weakened `assertNotSame(ONLINE)` is now an equality.
> - **The suite needed a third default.** `Tests\TestCase` gained an `OnlineWrite` stand-in
>   beside M5's `$hydrated` and M6's `$connected`, for the same reason: several hundred tests
>   written before this phase are about the local half, and there is no Postgres for them to
>   reach. The real two-database behaviour is not faked — `OnlineWriteTest` uses the real class.

## Tasks
- Widen `BranchGrants` (INSERT/UPDATE on the five shared tables, INSERT on `import_history`);
  keep DELETE empty and keep `branches`/`schema_meta` refused.
- `schema:reapply-grants`, wired into `console:run`, audited per role.
- `App\Support\Sync\OnlineWrite` over M5/M6's `BranchCycle`, `VersionGate`, `ConnectivityProbe`;
  `RowMapper` and `RemoteStock` extracted so the push and the online write share one
  local-row-to-Postgres-row implementation.
- Rework the five write paths and refuse them offline, once, in `bootstrap/app.php`.
- The `conflict` error kind end to end.

## Milestone
A product created at one branch is visible at another; a shared write attempted offline changes
nothing on either side.

---

# Phase M7 — Sync engine: connectivity detection & pull + stock reconciliation ✅

## Goal
Near-instant reconnect and correct bidirectional data flow.

> **Done.** A price changed at one branch reaches another **through a sync cycle**, on a device
> that has been live all along — which is the first time data has moved *down* in this system at
> all. `App\Support\Sync\` gained `Puller` and `StockReconciler`; `sync_cursor` is the last of
> §11.6's proposed tables to exist; polling for the backlog was replaced by a listener on the
> outbox. Proved end to end in `tests/Integration/PullTest.php` against two real provisioned
> branches. Scoping calls and findings:
>
> - **A pre-existing defect had to be fixed first, because this phase turned it from invisible
>   into destructive.** `StockAdjustmentController` wrote its `type = 'stock_adjustment'` ledger
>   row to local SQLite and stopped there — `InventoryLedger::shouldPush()` excludes those rows
>   from the outbox (correctly; they are online-authoritative), so a shelf recount typed at a till
>   reached nothing. Harmless while nothing read the upstream number. The moment a baseline is
>   pulled, `reconciled = baseline + unsynced sales` restores the old count on the next cycle and
>   on every cycle after it, with a green indicator throughout. It is now an **online write**,
>   exactly as CSV import became in M6.5 — and it is the *same* defect, on the one write path that
>   phase did not reach. Worth stating plainly: **M6.5's finding was not fully worked out.** That
>   phase enumerated "the five write paths" from §5.1's list of shared tables, and stock is not a
>   table on that list — it is a *derived quantity* whose writer is `inventory_ledger`, which §5.1
>   splits by a column value rather than naming. A list of tables was the wrong instrument for
>   finding it.
> - **The cursor is stored as Postgres's own string and never parsed locally.** `timestamptz`
>   carries microseconds and an offset; this app's datetime convention is a naive
>   second-resolution local string. Round-tripping the watermark through a `datetime` column would
>   land the bookmark up to a second away from the row it was read off — re-reading a second of
>   rows every cycle, or stepping over one, and stepping over is silent and permanent. So
>   `sync_cursor.pulled_through` is a `varchar`, bound straight back, and only Postgres ever
>   interprets it. `tests/Integration/PullTest.php` asserts the stored shape, because the moment it
>   reads as a bare `Y-m-d H:i:s` the precision is already gone.
> - **`>=`, not `>`.** Two rows edited in the same instant share a timestamp; with `>` the second
>   lands behind the bookmark the first one set and is never read again — no error, no gap, nothing
>   to notice. The cost is re-reading the boundary row each cycle, which the upserts absorb. Both
>   halves are pinned by tests, because the fix and its cost are easy to mistake for a bug in
>   either direction.
> - **The bookmark is the newest row actually READ, never `now()`** — and this is the subtle one. A
>   Postgres row's `updated_at` is stamped when its transaction *begins* but becomes visible when
>   it *commits*, so an edit starting at :00 and committing at :09 carries :00. A cycle running at
>   :05 does not see it, and had it bookmarked :05 that row would sit permanently behind the mark:
>   a branch on a stale price forever, reporting a healthy sync. Taken by position rather than by
>   comparison, since the query is ordered by the watermark — comparing `timestamptz` renderings as
>   strings happens to work for Postgres's current format and would break silently if it changed.
> - **Deletions need no handling, and that is a property of the schema rather than luck.**
>   Categories, items and variants archive; users soft-delete. Both are UPDATEs, so both ride the
>   same cursor. The never-delete invariant is precisely what makes a watermark pull sufficient — a
>   schema that really deleted rows would need tombstones and none of this would work.
> - **A fifth reason to avoid Eloquent appeared, on top of M5's four.** The hydrator writes through
>   the query builder to dodge the single-Owner guard, the `hashed` cast, UUID generation and the
>   `SoftDeletes` scope. The pull adds one the hydrate never faced: a model write fires
>   `PushedUpward::created`, which would enqueue every pulled row into the outbox and hand the
>   business's own data straight back to it.
> - **`RowMapper` now owns both directions.** `Hydrator::normalise()` and the new pull needed the
>   identical Postgres→SQLite conversion, and M6 already demonstrated the cost of writing the two
>   directions apart: hydration converted timestamps to UTC while the push stamped local values as
>   UTC, so a date moved eight hours down and eight hours back up, each plausible alone. They are
>   inverses and now sit in one class. The timezone is an explicit parameter, because the two
>   callers know it differently — a fresh device's `config('app.timezone')` is still the build's
>   default until the hydrate copies `settings`.
> - **The outbox listener replaced polling, and needed no new plumbing.** Every response already
>   carries `unsynced_count` in the shared `sync` prop, read from the outbox, so the client learns
>   the backlog grew for free — a sale, return, login or audit row produces a response and a cycle
>   fires seconds later. M6's explicit post-checkout `runSync()` in `pos.tsx` is retired by it: one
>   mechanism now covers returns, logins and audit rows too, where the special case covered only
>   sales. It is client-side rather than a `dispatchAfterResponse`, because NativePHP serves from
>   one PHP process and a cycle stalling on a dead connection would occupy it during shutdown —
>   reintroducing at the process level the blocking the outbox exists to prevent.
> - **The listener needs a suppression rule or it retries forever.** A backlog that cannot clear —
>   revoked credential, version-blocked build, refused payload — leaves `unsynced_count` non-zero
>   on every subsequent response, so a naive listener fires on every page navigation for something
>   only a person can fix. Suppressed unless the last cycle ended `online` with no `conflict`, and
>   keyed on the count so a stalled cycle costs one retry per *new* write rather than one per
>   navigation.
> - **The backstop is 5 minutes, and its job changed.** §11.3 sized this timer to stop a till being
>   "stuck offline indefinitely" and `docs/glossary.md` recorded 15 minutes. With the listener
>   taking the push, the timer's only remaining duty is the one nothing else can do: nothing local
>   signals that another branch changed a price, so it is now the freshness bound on shared data,
>   not a safety net. A branch opening in the morning would otherwise trade a quarter of an hour on
>   yesterday's catalog. Still one interval, hard-coded, same online and offline — §11.3's actual
>   constraint is untouched.
> - **`branches` and `schema_meta` are not pulled, though §5.1 lists them as pulled tables.**
>   Neither has a local counterpart. Branch identity lives in `instance`, written once from the
>   credentials JSON, and no console command renames a branch or sets `disabled_at` — so a
>   `branches` pull would have no consumer today. `schema_meta` *is* pulled, by `VersionGate`, on
>   every cycle before anything else runs; `SyncCycle::rememberVersions()` records it on `instance`.
>   That is its pull, and duplicating it in `Puller` would give one table two readers.
> - **Reconciliation writes only `stock_snapshots`, never a ledger row.** Nothing happened — it is
>   the same stock counted against a better source — so routing it through `StockLedger` would
>   invent inventory history and double-count against the pulled adjustment rows. It is also
>   clamped at 0: unreachable today, since nothing subtracts a branch's stock remotely without M8's
>   queue, but the column is what the POS reads to decide what may be sold and the never-negative
>   invariant needs a floor rather than a comment.
> - **§10's "baseline − out-movements" is implemented as an ADDITION of signed quantities.** The
>   local ledger is signed — a sale negative, a return positive, a defect zero — so subtracting
>   absolute values gets returns exactly backwards and hands the branch stock it never regained. A
>   test exists for the return case alone.
> - **Driving the rig found a defect no suite could: `tests/manual/pg.php` seeded opening stock
>   as `stock_snapshots` rows with no `inventory_ledger` behind them.** M6's note states the rule
>   — *whatever puts opening stock into a business schema must write a ledger entry, not just a
>   snapshot* — and the rig, written around the same time, broke it. The consequence is total and
>   was invisible: `RemoteStock::recompute()` sums Postgres's own ledger after a push, so the
>   first sale from a rig branch summed to `-1`, `stock_snapshots_non_negative` refused it, and
>   the refusal took the whole cycle down. **No rig branch had ever been able to sync a sale.**
>   Nothing caught it because every automated suite seeds its own opening stock correctly; the
>   rig is the one path that did not, and the rig is the one path no test drives.
>
>   Two things worth keeping from it. The failure surfaced as **"Sync stuck"** on the indicator —
>   `online` + `conflict`, M6.5's error kind doing exactly its job on the first real conflict this
>   project has produced, rather than the `offline` M6 would have shown and the pointless wait
>   that implies. And the rule is now stated where the seeder can be read: a zero opening balance
>   still gets no ledger row, because that is the absence of a movement rather than a movement of
>   nothing.
> - **Two test layers, deliberately split.** `tests/Feature/Sync/PullTest.php` runs the cursor
>   algorithm against a SQLite stand-in (`FakesTheBusinessDatabase`), credential-free and in
>   milliseconds; `tests/Integration/PullTest.php` runs the round trip against real Supabase.
>   The split is not convenience: `timestamptz` precision, `pdo_pgsql` returning booleans as
>   `'t'`/`'f'`, the timezone conversion and the real grants all pass against a stand-in and fail
>   in a shop, so a finding belonging to the round trip must never be asserted in the fast half.
>
> **Closed afterwards, before M8 started.** M7 shipped with two things open that were recorded
> nowhere in the repo, which is its own defect — a flagged item held only in an agent's memory is
> not flagged:
>
> - **The four sync triggers had no automated coverage at all**, having been verified once by
>   driving a browser by hand. That is the code least able to afford it: this app sets no
>   persistent Inertia layout, so every navigation unmounts and remounts the header, the indicator
>   and the hook, and a version keeping its bookkeeping in a ref would run a cycle per click
>   forever. That bug was caught by *reading* the file. `tests/e2e/sync.spec.ts` now pins all four
>   — mount, navigation-remount, the `online` event, the five-minute backstop — plus the outbox
>   listener's suppression rule, counting cycles at the **network layer** (`page.on('request')`),
>   which is what makes the instrumentation traps of the manual runs unreachable. Playwright's
>   clock API tests the backstop for real rather than asserting an interval is registered. Every
>   assertion was confirmed to fail against a deliberately broken hook.
>   What it deliberately cannot cover is the listener's other half — a growing backlog on a
>   *healthy* branch — which needs `state = 'online'` and therefore a real credential; forcing it
>   would mean rewriting `props.sync` in flight and testing a fabricated prop. It stays rig-verified,
>   and the spec says so and says where.
> - **The one cycle reading that was never explained is explained by elimination.** During the
>   first (badly instrumented) rig run a cycle appeared ~3m25s after the previous one, inside the
>   throttle; four properly instrumented runs afterwards showed none. Only two paths are
>   unthrottled. The device in that run was parked with no new local writes, which rules out the
>   outbox listener, and the spec above now proves the throttled paths are throttled — leaving a
>   spurious `online` event from the OS, which Chrome emits on interface churn (VPN, wifi roam).
>   One extra cycle is the correct response to it, so **no code change**: guarding the reconnect
>   trigger would trade §11.3's near-instant reconnect for suppressing a cycle that costs nothing.

## Tasks
- **Connectivity:** webview `online`/`offline` trigger + confirming **probe** + **one
  fixed-interval backstop poll** (same rate online and offline, no knob) + manual "sync now".
  §11.3 is normative on why it is one interval and not adaptive polling plus a configurable
  timer. *As built: four triggers, not three — an **outbox listener** off the shared `sync` prop
  replaced polling for the upward direction entirely, and retired M6's post-checkout special
  case. The interval is 5 minutes and its job is now the pull; see the note above.*
- **Pull** deltas of catalog/users/settings/identity via `updated_at`/change markers.
  *As built: `settings`, `users`, `categories`, `items`, `variants` and `type =
  'stock_adjustment'` ledger rows. Identity is `settings`; `branches` has no local counterpart
  and `schema_meta` is already pulled by the version gate.*
- **Stock reconciliation:** pull the recomputed **stock baseline** and apply
  `reconciled stock = baseline − local unsynced out-movements` (never blind-overwrite).
  *As built: `baseline + Σ(signed unsynced)`, which is the same arithmetic said in signed values
  — see the note above for why the absolute-value reading breaks returns.*
- Enforce cycle order: **version gate → push → (queue, M8) → pull → heartbeat**. *As built:
  `SyncCycle::run()` enforces it. M8 filled the queue step; the heartbeat is the last gap and
  belongs to M10.*
- *Not in the task list, and not optional:* **make the manual stock adjustment an online write**,
  or the reconciliation silently reverts every shelf recount. See the first note above.

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

# Phase M8 — Inventory update queue ✅

## Goal
Let online branch-targeted adjustments happen without ever driving stock negative.

> **Done.** An adjustment issued at one branch for another is refused by that branch when its own
> unsynced sales have already spent the stock, and applied when they have not — proved against two
> real provisioned branches in `tests/Integration/InventoryQueueTest.php`. `App\Support\Sync\`
> gained `QueueProcessor` and `OnlineRead`; step 2 of a cycle is no longer a comment.
> Scoping calls and findings:
>
> - **Only ANOTHER branch goes through the queue.** An adjustment aimed at this device stays the
>   direct online write M7 made of it, because the whole reason the queue exists is that the
>   issuer cannot see the target's unsynced sales — and when the target is this device, it can.
>   `StockLedger` already validates against reconciled stock and refuses a negative outcome on the
>   spot. Routing it through the queue would make a shelf recount wait for a cycle, and would add a
>   reject path that could never fire. One control, two destinations, and the dialog says which.
> - **Accepting writes UPSTREAM ONLY, and that is the load-bearing decision.** The accepted entry
>   is inserted into Postgres, `RemoteStock` recomputes the upstream snapshot, and the entry comes
>   back down *in the same cycle* through the ordinary pull — which is exactly why step 2 sits
>   between the push and the pull rather than after both. Writing it locally as well would give
>   `type = 'stock_adjustment'` two writers, the single-writer rule that keeps last-write-wins out
>   of this system, and would enqueue the row in the outbox to be handed back to the business that
>   issued it.
> - **The ledger entry's id IS the queue row's id**, and this is not tidiness. A cycle that
>   inserted the entry and then lost its connection leaves a `pending` row whose adjustment has
>   already been applied — and by the next cycle the baseline *contains* it, so re-deciding could
>   **reject an adjustment that is already in the ledger**. Recognising it is one primary-key
>   lookup, the retry collides with itself under `on conflict do nothing`, and `ledger_entry_id`
>   becomes a link anyone can follow. A rejected row that is re-issued is a new row, so the ids
>   never contend.
> - **The resolution must not touch `updated_at`.** `BranchGrants::UPDATE_COLUMNS` is column-level
>   and names four columns; a fifth in the SET list is `permission denied for table
>   inventory_update_queue` — which reads as a missing grant and is in fact the matrix being
>   exactly right. Only a real Postgres can catch this, which is half of why the integration test
>   exists.
> - **Validating against reconciled stock is strictly stricter than Postgres's own constraint.**
>   `available = upstream snapshot + Σ(signed unsynced local)`; the unsynced term is where the
>   negative movements are, so if reconciled stock survives the adjustment the upstream figure
>   survives it too. Neither side can go negative, which is the whole claim §10 makes. The terms
>   are taken from `StockReconciler::unsyncedMovement()` rather than re-written, because two
>   spellings of "unsynced" is precisely how one of them quietly stops counting returns.
> - **Order is by age, and it matters more than it looks.** Two subtractions that each fit alone
>   may not fit together, so which one is turned down has to be decided by `created_at` rather
>   than by whatever Postgres returned first. Each acceptance recomputes the upstream snapshot, so
>   a cumulative pass converges without any state carried between rows.
> - **The rejection reason carries figures, not a category.** It is read on another device,
>   possibly days later, by someone deciding whether to re-issue and for how much. "Would drive
>   stock negative" tells them nothing they can act on.
> - **`branches` and `inventory_update_queue` are read live and mirrored nowhere** — a new verb,
>   **online read** (`App\Support\Sync\OnlineRead`, glossary §5). `SchemaParityTest::POSTGRES_ONLY`
>   already said why each has no local counterpart, and giving them one would have meant a
>   migration, a `sync_cursor` row and a parity entry to serve two Owner/Admin screens that cannot
>   function offline anyway. It carries no version gate, unlike an online write: the gate stops a
>   stale build WRITING (§13), and refusing to render a screen would hide the display of the very
>   problem.
> - **The roster is fetched when the adjust dialog opens, not passed as a page prop.**
>   `ConnectivityProbe` never opens Postgres on purpose, and the inventory list is the screen a
>   cashier lives on — a roster read in its render would occupy a Supavisor client slot on every
>   page view, re-imposing the pressure `BranchCycle` exists to relieve.
> - **"Set to count" is suppressed for a remote target.** The only on-hand figure available to the
>   issuer is a baseline that excludes the target's unsynced sales, so a derived delta would be a
>   guess presented as a count — and the subtraction it produced would be rejected at the far end
>   for reasons invisible from here. A signed quantity is also exactly what the `quantity` column
>   stores.
> - **No local audit row per resolution.** The record is the queue row itself (`status`,
>   `resolved_at`, `rejection_reason`, `ledger_entry_id`) plus the ledger entry, both visible to
>   whoever issued it. Writing one would also make a *cycle* create outbox work, which the outbox
>   listener answers with another cycle — a new self-triggering path for no new information. The
>   issuing action is a user action and is audited.
> - **M7's warning was worth having and found nothing.** Its third open item said to look, before
>   this phase assumed its write paths were complete, for anything shared between branches whose
>   writer is selected by a column value rather than by table name. The audit: the queue insert is
>   a Postgres-only table, the resolution is Postgres-only, and the accepted ledger entry is the
>   online-authoritative half of `inventory_ledger` written from the online-authoritative side.
>   Still exactly one column-selected shared writer, and this phase is on the correct side of it.
> - **One shipped statement changed for a test, and it is worth saying so.** `now()` became
>   `CURRENT_TIMESTAMP` in `QueueProcessor` and `RemoteStock` — identical in Postgres, standard
>   SQL, and the difference between the fast tests running the shipped statement and running a
>   rewritten one. The stand-in also grew two unique indexes, because `ON CONFLICT (cols)` needs
>   something to target; they are the only constraints it carries and the reason is the same.

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
>   currently says "HQ issues the rows" at the same time. *Done, and delivered to existing
>   businesses with `schema:reapply-grants`; `BranchRoleGrantsTest` now proves a branch role can
>   issue a pending row and still cannot delete one.*
> - **This widens the cross-branch reach, knowingly.** A branch credential able to insert a queue
>   row for its own branch can insert one for any branch of the same business, since grants stop
>   at the schema boundary. Same exposure class as forged `branch_id` on history rows (§4's
>   annotation); one more table reaches it. Record it, don't quietly absorb it. *Recorded in
>   `BranchGrants`, in the migration, and in `docs/glossary.md` §9 — and asserted rather than
>   described: a test issues a row against another branch and expects it to succeed. It is bounded
>   three ways: the branch may only ever write `pending`, the TARGET decides whether it becomes a
>   ledger entry, and DELETE is granted on nothing.*
>
>   **Settled afterwards: row-level security is not planned, and this is not a deferred gap.**
>   Every branch of one business belongs to one Owner and runs on a device that business controls,
>   so a credential in the wrong hands already means the till is in the wrong hands — forging
>   another branch's `branch_id` is a marginal escalation on top of that, not a new class of
>   access. The boundary that carries weight is business-to-business, which is schema-level,
>   enforced, and separately tested. RLS is also not cheap here: it wants a per-branch session
>   variable, and branch devices connect through the transaction pooler, where no session state
>   survives a cycle. The consequence is the part to hold on to — **the enumerated grant matrix is
>   now the only boundary of this kind, so it must not erode**: per-table grants only, DELETE on
>   nothing, column-level UPDATE on the queue, and any future widening states its reason in that
>   phase's note. Full reasoning in `docs/glossary.md` §9, which is normative.

## Tasks
- Online adjustments to a branch land in `inventory_update_queue` as **pending** (not yet a
  ledger entry). *As built: only adjustments aimed at ANOTHER branch. One aimed at this device
  stays the direct online write of M7 — see the first note above.*
- On sync, after pushing real movements, process the queue in order: **accept** (create ledger
  entry) when reconciled stock stays `≥ 0`; **reject** a subtractive adjustment that would go
  `< 0`. *As built: `App\Support\Sync\QueueProcessor`, step 2 of the cycle, writing the accepted
  entry upstream only and letting the same cycle's pull bring it down.*
- **Flag rejected adjustments in the in-app admin** (Owner/Admin screens) for review/re-issue.
  *As built: `/inventory/stock-updates` lists pending, applied and turned-down rows — not
  rejections alone, or an adjustment sent to a closed branch would be invisible until it
  resolved. Re-issue creates a NEW pending row; the rejection stands as the record of what
  happened.*
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
