# Evitan Plus — Glossary

> **This document is normative.** Where it and any prose in `MULTIBRANCH-BRAINSTORM.md`,
> `MULTIBRANCH-ROADMAP.md`, `BRAINSTORM.md` or `ROADMAP.md`
> disagree on what a word means, **this document wins** — the same convention those specs
> already use for their "As built" annotations.
>
> **Why it exists.** M0–M3 shipped, and a large share of the "As built" corrections they
> required were not design changes at all: they were a word meaning one thing in the spec and
> another in Postgres. §7 named a grant on `inventory_update_queue.status` that a check
> constraint makes *unusable*; §8.3's "delete a branch" named an operation that deletes a role
> and no branch; §9's "the credential" collapsed two values that must stay separate — a bug
> that, in the spec's own words, "surfaces only in the field, at setup, on a customer's
> device". Each cost implementation time to rediscover. M4–M12 are written from the same
> prose, so the vocabulary is fixed here first.
>
> **How entries are written.** Every definition names a **code anchor** — a class, column,
> command or config key that can be checked — so this file is verifiable rather than merely
> asserted. A **✗ Not** line names the term the entry is most often confused with.
>
> **Adding a term.** A word that carries weight in a spec or a phase note gets an entry here.
> A word with no anchor is a word two people can read differently.

---

## 1. Products and actors

### Evitan POS
The **single-install, offline-only** retail POS released as **v1.0.0** (2026-07-20). One
business, one machine, no network dependency; SQLite is the whole database. Its data lives
under `~/.config/evitan-pos/`.
*Anchor:* `RELEASE-NOTES.md` v1.0.0 entry; `ROADMAP.md` phases 0–19; `BRAINSTORM.md`.
✗ **Not** Evitan Plus. This is the product being *evolved from*, and it still exists as a
released artifact with its own on-disk identity.

### Evitan Plus
The **multi-branch, cloud-connected** product this initiative builds: many branches of one
business sharing one authoritative Postgres schema on Supabase, each branch still selling
offline. Application id `com.evitan.plus`, data under `~/.config/evitan-plus/`.
*Anchor:* `.env.example` `APP_NAME`; `MULTIBRANCH-BRAINSTORM.md`; `MULTIBRANCH-ROADMAP.md`.
✗ **Not** Evitan POS. The two names mark **different products**, not old and new spellings of
one. A build of Evitan Plus does not adopt an Evitan POS install's data.

### The multi-branch initiative
The programme of work — phases **M0–M12** — that turns Evitan POS into Evitan Plus. Use this
phrase for the *work*; use "Evitan Plus" for the *product*.

### Developer
The single human who operates the Provisioning Console, prepares hardware, installs the build
and runs the setup wizard. The only actor who ever holds admin Postgres credentials.
*Anchor:* `MULTIBRANCH-BRAINSTORM.md` §2 actor table.
✗ **Not** "operator". That word survives only as the identity string the console stamps on its
own audit rows (`OperatorIdentity::resolve()` → `user@host`); it is not a second actor.

### Permission role
One of **Owner > Admin > Cashier** — what a *person* may do inside the app.
*Anchor:* `app/Enums/UserRole.php` (`Owner`, `Admin`, `Cashier`, with `level()`/`atLeast()`).
✗ **Not** a branch role. These are unrelated systems: permission roles are app-enforced and
per-user; branch roles are Postgres logins and per-branch.

### Branch role
The **Postgres login role** a branch device authenticates as, named `branch_<32-hex of the
branch UUID>`, least-privilege and scoped to one business schema.
*Anchor:* `console/app/Support/RoleName.php` (`forBranch()`, pattern `/^branch_[a-z0-9_]{1,55}$/`);
privilege matrix in `console/app/Support/BranchGrants.php`.
✗ **Not** derived from the branch code. Postgres roles are cluster-wide while branch codes are
unique only within one business, so a code-derived name would collide across businesses.
✗ **Not** user-aware. It is shared by everyone using that branch device; per-user visibility
(notably Item Cost being Owner-only) is never a DB-grant concern.

### Console admin connection
The console's **privileged** Postgres connection, which owns all DDL, migrations and role
management. Runs over Supabase's **session** pooler (`5432`), because DDL, `CREATE`/`ALTER
ROLE` and its `search_path` pin all need session state to survive between statements.
*Anchor:* `console/app/Support/SchemaConnection.php`, `SupabaseEndpoint`.
✗ **Not** an "admin" in the permission-role sense, and never present in a shipped build.

### In-app admin screens
The **business's own** management UI — inventory, users, settings, reports. Where a rejected
inventory adjustment is flagged.
✗ **Not** the Provisioning Console, and ✗ **not** the Admin permission role. Say "the in-app
admin screens" for the surface and "an Admin" for the person.

---

## 2. Branches, devices and tills

These four words are interchangeable **only** while MVP holds one device per branch. That
constraint is explicitly scheduled to end (`MULTIBRANCH-BRAINSTORM.md` §19, "multiple tills per
branch"), at which point every sentence that conflated them becomes ambiguous. Keep them apart
now.

### Branch
A **business location**: one row in `branches`, one branch code, one branch role. A business
has one or more; all of them live in the same business schema.
*Anchor:* the `branches` table; `branches_code_unique`.

### Branch code
The short uppercase identifier for a branch (`MNL`, `CEB`) that **prefixes every receipt
serial** it prints. Load-bearing, not cosmetic: since each branch generates its own gapless
serial offline, the prefix is the only thing making receipt numbers unique business-wide.
*Anchor:* `console/app/Support/BranchCode.php` (max 16, `/^[A-Z0-9][A-Z0-9-]{0,15}$/`);
`check (code = upper(code))` on `branches`.
✗ **Not** an input to the branch role name (see *Branch role*).

### Branch device
The **physical machine** at a branch. Holds one SQLite file and one branch credential.

### Till
The **running Evitan Plus app as the selling surface** — the subject of every "never stops
selling" guarantee. Prefer this word in offline-behaviour sentences; it is the one that stays
correct when a branch gains a second till.

### `instance`
The **local SQLite singleton table** holding branch identity (business id, schema name, branch
id, branch code, versions, `hydrated_at`). One row, `id = 1`, written by the setup wizard from
the credentials JSON. Its sibling `instance_secret` holds the sealed credential, split off so
the hot path never loads ciphertext.
*Anchor:* `App\Models\Instance`; `database/migrations/2026_07_29_000001_create_instance_tables.php`;
`MULTIBRANCH-BRAINSTORM.md` §11.6.
✗ **Not** a synonym for a device or a business. "App instance" and "business instance" are
retired; say *branch device* or *business*.

### Business
One customer of Evitan Plus. Owns exactly one business schema, one Owner, and one or more
branches.

---

## 3. Stores and schemas

The word "schema" carries three unrelated meanings. All three are in active use, so each gets
its own phrase.

### Business schema
The Postgres schema **`biz_<32-hex of the business UUID>`** holding every table and **every
branch's** data for one business. A business never spans more than one.
*Anchor:* `console/app/Support/SchemaName.php` (`forBusiness()`, `/^biz_[a-z0-9_]{1,58}$/`).

### Console schema
**`evitan_console`** — the developer-side schema holding `provisioning_audit` and
`credential_issuance`. Append-only by trigger. No branch role can reach it, and it is never
inside a `biz_*` schema.
*Anchor:* `SchemaName::CONSOLE`; `console:migrate`.

### Schema version
An **integer** naming a state of the Postgres table structure. Raised by migrations.
*Anchor:* `schema_meta.schema_version` (singleton row, `check (id = 1)`).
✗ **Not** the app version. See §6.

### Local mirror
The **branch device's SQLite database** — branch-scoped for everything except `users`, which is
mirrored business-wide so any user can log in at any branch offline. It is the **only store
that can be thrown away**: everything in it is either re-derivable from Postgres or already
pushed there.
*Anchor:* `database/nativephp.sqlite`; `database/migrations/` (the mirrored tables);
`MULTIBRANCH-BRAINSTORM.md` §11.6 (the sync tables).

### Mirrored (of a table)
A table whose **structure must agree** on both sides, enforced by the parity guard. Exactly 14
tables today.
*Anchor:* `console/tests/Feature/SchemaParityTest.php` `MIRRORED`.
✗ **Not** "readable", ✗ **not** "pulled", and ✗ **not** "local-only". The four sets are different
sizes and different memberships — see below. `MULTIBRANCH-BRAINSTORM.md`'s old phrase
"mirrored/append tables" is retired precisely because it blurred them.

### Readable (of a table)
A table the **branch role may `SELECT`**. Effectively the whole business schema minus Laravel's
`migrations` ledger — 18 tables — because the till reads the catalog, all users, settings, its
own history, *and* `schema_meta` on every sync.
*Anchor:* `BranchGrants::SELECT`.

### Pulled (of a table)
An **online-authoritative** table whose rows flow **down** to the till and are never written
locally: `categories`, `items`, `variants`, `users`, `settings`, `branches`, `schema_meta`.
*Anchor:* the complement of `BranchGrants::INSERT` within `BranchGrants::SELECT`.

### Local-write (of a table)
A table written **only** on the till, append-only, pushed **insert-only** keyed by UUID:
`transactions`, `transaction_items`, `sale_returns`, `sale_return_items`, `inventory_ledger`
(sale/return/defect rows), `login_history`, `audit_logs`.
*Anchor:* `BranchGrants::INSERT`.

### Local-only (of a table)
A table that exists in the **local mirror and nowhere else** — it has no Postgres counterpart and
is exempt from the parity guard. The list is Laravel's own infrastructure (`sessions`,
`cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations`, and `sqlite_sequence`,
which SQLite materialises for the AUTOINCREMENT on `migrations`) plus M5's `instance` and
`instance_secret` and M6's `sync_outbox` and `sync_status`. M7's `sync_cursor` and M8's
narrowed `inventory_update_queue` join it.
*Anchor:* `SchemaParityTest::SQLITE_LOCAL_ONLY` (added in M4).
✗ **Not** "mirrored" — and the distinction is the whole reason this set exists rather than the
tables being folded into `MIRRORED`. A local-only table is deliberately a *different, narrower*
table than any Postgres one, so putting it in `MIRRORED` would make the guard demand a similarity
nobody wants (`MULTIBRANCH-BRAINSTORM.md` §11.6).
✗ **Not** "Postgres-only" (`SchemaParityTest::POSTGRES_ONLY`), which is the same idea pointing the
other way.

### Hydrate
The setup wizard's **one-time first fill** of a new branch device: catalog, all users,
settings/identity, and this branch's stock snapshot. Happens once per device, inside a normal
sync cycle's connection.
*Anchor:* `App\Support\Sync\Hydrator`; `instance.hydrated_at`;
`MULTIBRANCH-BRAINSTORM.md` §16.
✗ **Not** a pull. A pull is the recurring delta; a hydrate is the initial baseline, and a
device can be re-hydrated from scratch because the local mirror is disposable.
✗ **Not** history. A hydrate brings down no transactions, ledger or audit rows — which is why a
device replacing an existing branch's hardware is a different case from a new one.

---

## 4. Inventory and stock

### Ledger entry type — the three columns
The spec's old six-value vocabulary (`restock / manual adjustment / import / sale / return /
defect`) **does not exist**. `inventory_ledger` carries **three independent columns**:

| Column | Allowed values | Meaning |
|---|---|---|
| `type` | `sale` \| `stock_adjustment` | Whether the movement came from selling or from adjusting |
| `sale_type` | `sale` \| `return` \| `defect` (null for adjustments) | Which after-sales event, when `type = sale` |
| `reference_type` | `transaction` \| `manual_adjustment` \| `csv_import` \| `return` | What kind of record `reference_id` points at |

*Anchor:* `console/database/migrations/2026_07_26_000010_create_inventory_ledger_table.php`;
`app/Support/Inventory/StockLedger::record()`; real writes in
`StockAdjustmentController.php:44`, `CsvInventoryImporter.php:972`.

✗ **`restock` is not a term.** It appears in no code path. A restock is a *positive*
`stock_adjustment` with `reference_type = manual_adjustment` — a UI framing of an existing
shape, not a value. Do not use it in specs.
✗ `adjustment` and `import` are likewise not values: they are `stock_adjustment` rows
distinguished by `reference_type`.

### Ledger classification for sync
Which way a ledger row moves is decided by its columns, not by a separate type:
- **Local-write, pushed up:** `type = sale` (any `sale_type`).
- **Online-authoritative, pulled down:** `type = stock_adjustment`.

### Stock snapshot
The current on-hand quantity for one variant at one branch, stored as a row and updated on
every ledger insert. `check (quantity >= 0)`.
*Anchor:* `stock_snapshots`; `StockLedger::record()` locks it with `lockForUpdate()`.
✗ **Not** a backup, and ✗ **not** the stock baseline.

### Stock baseline
The **figure pulled from Postgres** during a sync cycle. It reflects online entries and
already-pushed local movements — never the till's *unsynced* ones.

### Reconciled stock
`stock baseline − local unsynced out-movements`. The number the till actually sells against.
Self-healing: once the local movements push, the next baseline already contains them and the
unsynced set is empty.
✗ **Not** a conflict resolution. Stock is the one shared quantity, and it reconciles
**additively**; there is no last-write-wins anywhere in this system.

### Inventory update queue
`inventory_update_queue` — pending, branch-targeted stock adjustments **awaiting the branch's
confirmation**. A queue row is *not* a ledger entry; it becomes one only on acceptance, which
is what keeps stock from going negative behind an offline branch's back.
*Anchor:* the `inventory_update_queue` table; `check ((status = 'pending') = (resolved_at is
null))`; `BranchGrants::UPDATE_COLUMNS` (the four columns a resolution touches:
`status, resolved_at, rejection_reason, ledger_entry_id`).
✗ **Not** the outbox. See §5.

---

## 5. Sync

### Sync cycle
The **single ordered unit of sync**, and the only thing that opens a Postgres connection:

```
0. version gate  →  1. push  →  2. queue  →  3. pull  →  4. heartbeat
```

The order is load-bearing. Push runs first so the pulled baseline already reflects this
branch's own movements; the queue runs after push so adjustments validate against true
reconciled stock; the heartbeat runs last so it describes the cycle that just finished.
A cycle **owns its connection**: connect → run → disconnect in a `finally`. (`DB::purge`, not
`DB::disconnect`: disconnect closes the PDO but leaves the connection registered with the
credentials it was built with, ready to reconnect behind the caller's back.)
*Anchor:* `App\Support\Sync\SyncCycle` runs the ordered steps and records the outcome;
`App\Support\Sync\BranchCycle` is the narrower piece that owns the connection those steps run
inside. Two classes, one cycle — say "the cycle" for the sequence and name `BranchCycle` only
when the subject is the connection itself. `MULTIBRANCH-BRAINSTORM.md` §11.2/§11.5.
✗ **Not** a session. No `SET`, no `LISTEN`, no temp tables, no advisory locks, no transaction
spanning steps — a branch connects through the **transaction** pooler (`6543`), where no
session state survives.

### Probe
The lightweight **connectivity check** that decides whether a cycle should run at all. An
HTTPS request to the project's REST endpoint, derived from `connection.username`'s
`<role>.<project-ref>` suffix; **any** HTTP answer, 401 included, means reachable.
*Anchor:* `App\Support\Sync\ConnectivityProbe` (M6).
✗ **Must never open Postgres.** Every connected client holds a Supavisor client slot (capped
per tenant in the low hundreds), so a probing offline till would permanently occupy one — the
exact ceiling that connecting per cycle exists to avoid.

### Push
Sending local-write rows up, **insert-only and UUID-keyed**, so a re-push is a harmless no-op.

### Pull
Fetching **deltas** of pulled tables by `updated_at`, plus the recomputed stock baseline.

### Heartbeat
The **upsert of this branch's `branch_status` row** at the end of a cycle. One row per branch —
current state, not history (`branch_id` is the primary key).
*Anchor:* `console/database/migrations/2026_07_26_000018_create_branch_status_table.php`.

### Opportunistic sync
A cycle fired **immediately after a checkout** when online, so Postgres trails by seconds
rather than a full interval. Still never in the commit path.

### Backstop timer
The long fallback interval (default 15 min) that runs a cycle even if nothing else triggered
one.

### Outbox
`sync_outbox` — the local FIFO of **rows waiting to push**. Stores *pointers, not payloads*:
every local-write table is immutable, so a row can be re-read at push time and cannot have
changed. `sequence` is an autoincrement integer because the outbox needs strict FIFO; the row
it points at is named by `table_name` + **`row_id`** (not `id` — the table's key is `sequence`,
and a model whose `id` is not its key misreads every relation).
*Anchor:* `App\Support\Sync\Outbox`; the `sync_outbox` table (M6).
✗ **Not** the inventory update queue. Two unrelated structures, opposite directions: the outbox
holds *this branch's* work going **up**; the queue holds *adjustments issued elsewhere* coming
**down**. Reserve the bare word "queue" for the latter; use "outbox" or "backlog" for the
former.

### Unsynced count
`count(*) where synced_at is null` in the outbox. One definition, one place, no counter to
drift.
*Anchor:* `App\Support\Sync\Outbox::unsyncedCount()`; `branch_status.unsynced_count`
(`check (unsynced_count >= 0)`); `MULTIBRANCH-BRAINSTORM.md` §11.6 rationale.

### Serial floor
The highest `transaction_number` / `return_number` **Postgres already holds for this branch**,
read once at hydration and stored on `instance`. Both local generators continue from
`max(local max, floor)`.
It exists for one case: a device **replacing** an existing branch's hardware. Hydration copies
no history, so such a device's local max is empty and it would restart at 1 against serials
Postgres already has — and because the push is insert-only, that collision is *absorbed* rather
than raised, losing the sales behind a clean sync and a zero backlog.
*Anchor:* `instance.transaction_number_floor`, `instance.return_number_floor`;
`App\Support\Sync\Hydrator::serialFloors()`.
✗ **Not** a ceiling. Once the device has outrun it, it stops applying; the return floor also
stops applying at midnight, when the daily prefix moves on.

### Sync status
`sync_status` — the local singleton answering "why is this device not syncing":
`connection_state` (`online | offline | unauthorized | version_blocked`), `last_error_kind`
(`auth | version | network`), `last_sync_at`, `blocked_since`. Written at the end of every
cycle.
*Anchor:* `App\Models\SyncStatus`; `App\Support\Sync\SyncCycle::run()`.
✗ **Not** `branch_status`, which is the Postgres row the M10 heartbeat upserts. Same four
`connection_state` values on purpose, so the heartbeat is a straight copy rather than a lossy
one.

---

## 6. Versions and the gate

Three quantities, two of which are routinely confused. The gate compares exactly one pair.

### App version
The **build on a branch device**. A semver string.
*Anchor:* `config('nativephp.version')` (`NATIVEPHP_APP_VERSION`, default `1.0.0`);
recorded in `branch_status.app_version`.

### Schema version
The **integer** state of the Postgres table structure, raised by migrations.
*Anchor:* `schema_meta.schema_version`; echoed in `branch_status.schema_version` and
`instance.schema_version`.

### Min app version
The **oldest app version the current schema can safely talk to**, a semver string declared by
the schema. Raised **only** for a breaking migration — an additive one must leave it alone, or
every routine update needlessly freezes every branch.
*Anchor:* `schema_meta.min_app_version`; carried to the device in the credentials JSON and
stored as `instance.min_app_version`.

### Version gate
The comparison a cycle makes **before step 1**:

> **app version ≥ min app version**

If it fails, sync is refused in **both** directions — a stale push would write old-format rows
into a migrated Postgres, and a new-format pull would write columns the local SQLite lacks;
both are corruption. The till **keeps selling** throughout, and the console shows the branch as
version-behind.
*Anchor:* `App\Support\Sync\VersionGate` (M5), which reads `schema_meta` **live** rather than
trusting the version pair the credentials JSON was issued with — a schema migrated since
issuance still carries the old numbers there.
✗ **Not** a schema-version comparison. `MULTIBRANCH-BRAINSTORM.md` §13 previously said the sync
carries "the app's schema version", which named neither of the two values actually compared.
The schema version is *recorded* by both sides; it is not what the gate tests.

---

## 7. Console lifecycle verbs and credentials

The console's verbs were a live source of error — `branch:revoke` exists because
`branch:delete` "invites exactly the wrong reading at exactly the wrong hour". The
distinctions are fixed here rather than re-derived each phase.

### Onboarding
The **whole developer-run chain**: provision → prepare hardware/OS → install the build → run
the setup wizard → hand over a ready device.
✗ **Not** "provisioning", which is only the first step.

### Provision (a business)
`business:provision` — **create-only**. Creates the schema, migrates it, seeds the Owner,
business settings and first branch, creates the branch role, emits the credentials JSON. An
existing schema is a **refusal**, never an upgrade path; that is what makes its compensating
undo safe, since it can only ever remove what this run just created.

### Provision (a branch)
`branch:provision` — adds a branch to an **existing** business: one `branches` row, one role,
one credentials JSON. No new schema, no re-migration.
✗ The **schema is never in this undo stack**. It already holds other branches' sales history.

### Rotate / re-create
`branch:rotate`, with `--recreate` for the missing-role case. One act under two starting
conditions; the flag is **required in both directions** so a rotation can never silently become
a re-creation.
✗ **A rotated password cannot be rolled back** — the previous secret was written to one file
and never stored. A failed re-issue therefore keeps everything and reports `verified => false`
rather than discarding the only copy of a live credential.

### Disable / enable
`branch:disable`, `branch:enable` — revoke/grant **`LOGIN` on the role**, *and* terminate its
backends, because Postgres checks `LOGIN` only at connect time while Supavisor serves new
clients from a warm pool.
✗ **Never touches `branches.disabled_at`.** A credential disabled on suspicion of a leak is not
a branch that has closed; conflating them would make re-enabling a credential silently reopen a
branch.

### Revoke
`branch:revoke` — **drops a Postgres role**. The branch row, its sales and its ledger are
untouched and could not be deleted from here. The audit action is still `branch.delete`.
✗ **Not** "delete a branch". Nothing in this system deletes business records.

### Archive / soft delete
The **app-side** never-delete mechanisms: categories, items and variants are *archived* (flag);
users are *soft deleted*. Both stay visible in all historical records.
✗ Unrelated to `revoke`, which concerns credentials, not business records.

### Branch credential
The **role name + password pair** a branch device authenticates with. Two values, and they are
**not the same string**:
- **Role name** — the bare Postgres role (`branch_<32-hex>`). What rotate/disable/revoke
  operate on. *Anchor:* `role.name` in the JSON.
- **Pooler username** — what you actually authenticate as through Supavisor:
  `<role>.<project-ref>`. *Anchor:* `connection.username` in the JSON.

✗ Collapsing these into one field reintroduces a bug that surfaces only in the field, at setup,
on a customer's device.

### Credentials JSON
The **one-time file** the console emits per branch and the setup wizard imports. A versioned
envelope (`format: evitan.branch-credentials`, `format_version: 1`), written with an exclusive
create at `0600` inside a `0700` directory; an existing path is a refusal, never an overwrite.
*Anchor:* `console/app/Support/BranchCredentials.php` (emitting);
`App\Support\Sync\CredentialsDocument` (reading).
Two importer rules that are easy to get wrong:
- Merge `connection` **over** `config('database.connections.pgsql')`; do not replace it. The
  block is a subset and a wholesale assignment drops `url`, `charset`, `prefix`,
  `prefix_indexes`.
- Assign `connection.search_path` **as-is (`null`)**. The schema is pinned server-side by
  `ALTER ROLE ... SET search_path`, which is what survives transaction pooling.

### Fingerprint
A deterministic sha256 of an issued credential — **never the password** — stored so the console
can answer "is this device still holding what I last issued?" without either side transmitting
the secret.
*Anchor:* `credential_issuance` in `evitan_console`; `console/app/Support/BranchSecret.php`;
`App\Support\Sync\CredentialVault` on the device. The domain separator
(`evitan.branch-credential.v1|`) is duplicated in both apps — §18 forbids a runtime reference
either way — and a unit test reads the console's file to keep them byte-identical.

---

## 8. Settled by the vocabulary pass

Three terms could not be defined from code because the decision behind them had never been
made. All three are now settled. Each carries work into a later phase; the phase notes in
`MULTIBRANCH-ROADMAP.md` hold the tasks.

### Issuing branch — replaces "online admin" and "HQ"
An **Owner or Admin, on any branch device, while online, may issue a stock adjustment
targeting any branch of their business.** They choose the target in the in-app admin screens;
`inventory_update_queue` records the target (`branch_id`) and the issuing user (`issued_by`).
*Anchor:* the `inventory_update_queue` table.
✗ **"HQ" and "online admin" are retired.** There is no head-office application, no separate
console-side actor, and no fourth permission role. Both words meant this one actor.
⚠ **M8 must add `inventory_update_queue` to `BranchGrants::INSERT`** and re-run
`BranchRole::reapplyGrants()`. No branch role holds INSERT on it today, so as built only the
console's admin connection could create a row — leaving the feature unreachable from the screens
that offer it. This knowingly widens the deferred RLS gap: grants stop at the schema boundary,
so a credential able to queue an adjustment for its own branch can queue one for any branch of
the same business.

### `connection_state`
**Four values, identical on both sides:** `online | offline | unauthorized | version_blocked`.
`unauthorized` = the branch role was disabled by the console; `version_blocked` = the §6 gate
refused; `offline` = no connectivity. The heartbeat copies the local value straight up.
*Anchor:* `branch_status.connection_state`; `sync_status.connection_state`
(`MULTIBRANCH-BRAINSTORM.md` §11.6).
The distinction earns its place twice: at the till all three failure kinds look identical and
only one is fixed by waiting; in the console monitor, a disabled credential and a dead internet
connection would otherwise be the same row. **None of the four stop the till selling.**
⚠ No migration needed — the column has no check constraint. M10 should add one, since that is
what makes the set enforceable rather than conventional.

### CSV import — online-only
A CSV import is an **online-only operation**, alongside catalog and user CRUD. Consequently
`import_history` **is** online-authoritative and does **not** sync upward; the branch role's
`SELECT`-without-`INSERT` on it is correct and stays.
*Anchor:* `BranchGrants::SELECT` includes `import_history`; `BranchGrants::INSERT` does not.
**Why:** an in-app import writes `type = 'stock_adjustment'` ledger rows, which are
online-authoritative and pulled down — so the outbox would never carry them, and a branch that
imported while offline would create stock movements that could never reach Postgres and would
diverge permanently and silently. Pushing them instead would give `stock_adjustment` rows **two
writers**, breaking the single-writer-per-table rule that is the reason no last-write-wins
exists anywhere in this system.
⚠ **M6 owes a connectivity gate** on the shipped v1.0.0 import screen, so it refuses cleanly
offline rather than silently producing unsyncable rows.

---

## 9. Still open

- **Row-level security between branches inside one business schema.** Grants stop at the schema
  boundary, so a compromised branch credential can write rows tagged with another branch's
  `branch_id` — and, once M8 lands, queue adjustments against another branch. One business still
  cannot reach another's data. Deferred, and tracked in `MULTIBRANCH-BRAINSTORM.md` §19.
