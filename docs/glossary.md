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

### Rig
The two-server **development harness** that runs **two tills of one throwaway business** against
the live Supabase project — `MNL` on `127.0.0.1:8131`, `CEB` on `127.0.0.2:8132` — and destroys
the business schema, both branch roles and both local SQLite files on Ctrl-C.
*Anchor:* `tests/manual/rig.sh`; `composer.json` `scripts.rig` / `rig:wizard` / `rig:clean`;
`tests/manual/.runtime/state.env`.
✗ **Not** a till, a branch device or an install. One rig serves **two** of each, from two
`php -S` servers over two SQLite files — which is the whole point of it, and the reason §2's
four words have to stay apart when writing about it.
✗ **Not** a test or a gate. It asserts nothing and CI never runs it; correctness is
`composer verify`, `verify:hydrate` and `verify:console`. The rig is for *looking*.

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

### Device identity (the `identity` shared prop)
The **business name and branch this device is**, shared with every Inertia page — the login
screen included — so any screen can name the installation without asking for it. Two sources,
which is why it is one prop rather than two: `business` is a pulled setting, `branch`/`code`
come from [`instance`].
*Anchor:* the `identity` key in `HandleInertiaRequests::share()`;
`resources/js/components/branch-identity.tsx` (M11.5).
✗ **Not** the product name. The sidebar wordmark stays *Evitan Plus*; this is the shop's name
and its branch.
✗ **Not** a [branch roster] entry. A till knows only which branch it *is*.
✗ **Null** on a device the wizard has not finished with — it has no branch to name yet.

### Branch roster
The business's `branches`, read live from the business database by any screen that must name a
branch other than its own. Includes **disabled** branches by default: a disabled credential
stops a branch receiving work, it does not unmake what that branch already did.
*Anchor:* `App\Support\Sync\BranchRoster::read()` (extracted in M11.5 from M11's
`ReportsController::roster()`).
✗ **Not** mirrored, ever. See [online read].
✗ **Not** the same call everywhere: `InventoryQueueController` excludes disabled branches,
because that screen picks a branch to send an adjustment *to*.

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
An **online-authoritative** table whose rows flow **down** to the till: `categories`, `items`,
`variants`, `users`, `settings`, `branches`, `schema_meta`.
*Anchor:* `MULTIBRANCH-BRAINSTORM.md` §5.1.
✗ **Not** "never written by a branch", which is what this entry and §5.1 both used to say. A
branch may change the catalog, users and settings — from the in-app admin, while online, as an
**online write**. What it may never do is change them *offline*, or write them locally and
leave them there. `branches` and `schema_meta` remain genuinely read-only to a branch role.

### Local-write (of a table)
A table written **only** on the till, append-only, pushed **insert-only** keyed by UUID —
and, since M11.6, hydrated back **down** insert-only when a device is rebuilt:
`transactions`, `transaction_items`, `sale_returns`, `sale_return_items`, `inventory_ledger`
(sale/return/defect rows), `login_history`, `audit_logs`.
*Anchor:* `BranchGrants::INSERT`.

### Local-only (of a table)
A table that exists in the **local mirror and nowhere else** — it has no Postgres counterpart and
is exempt from the parity guard. The list is Laravel's own infrastructure (`sessions`,
`cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations`, and `sqlite_sequence`,
which SQLite materialises for the AUTOINCREMENT on `migrations`) plus M5's `instance` and
`instance_secret`, M6's `sync_outbox` and `sync_status`, and M7's `sync_cursor`.
✗ **`inventory_update_queue` is NOT here**, and a previous edition of this entry said it was.
It is **Postgres-only** — `SchemaParityTest::POSTGRES_ONLY`, and `MULTIBRANCH-BRAINSTORM.md`
records the local one as "decided against, not deferred". Everything about resolving a pending
adjustment rests on that: the list is an online read, and answering one is an online write.
*Anchor:* `SchemaParityTest::SQLITE_LOCAL_ONLY` (added in M4).
✗ **Not** "mirrored" — and the distinction is the whole reason this set exists rather than the
tables being folded into `MIRRORED`. A local-only table is deliberately a *different, narrower*
table than any Postgres one, so putting it in `MIRRORED` would make the guard demand a similarity
nobody wants (`MULTIBRANCH-BRAINSTORM.md` §11.6).
✗ **Not** "Postgres-only" (`SchemaParityTest::POSTGRES_ONLY`), which is the same idea pointing the
other way.

### Hydrate
The setup wizard's **one-time first fill** of a new branch device: catalog, all users,
settings/identity, this branch's stock snapshot, **and this branch's history** — its
transactions and their items, sale returns and their items, inventory ledger, login history,
audit logs and import history. Happens once per device, inside a normal sync cycle's
connection.
*Anchor:* `App\Support\Sync\Hydrator` (`TABLES`, `INSERT_ONLY`, `PARENT_SCOPED`);
`instance.hydrated_at`; `MULTIBRANCH-BRAINSTORM.md` §16.
✗ **Not** a pull, and **hydrated ⊄ pulled** — the two sets overlap without either containing
the other. A pull is the recurring delta of online-authoritative tables; a hydrate is the
one-time baseline, and the history half is hydrated once and then never pulled again, because
from that moment on the device authors it. A device can be re-hydrated from scratch because
the local mirror is disposable.
✗ **Not** other branches' history. Everything in the history half is filtered to this branch —
directly by `branch_id`, or through a parent for the two line-item tables Postgres does not
scope. The local schema has no `branch_id` to hold anyone else's rows in.
The history half arrived in **M11.6**, when removing the backup feature made the wizard the
only way to rebuild a device. Before it, a replacement device came up with the right catalog
and stock but no sales, receipts or audit trail.

---

## 4. Inventory and stock

### Ledger entry type — the three columns
The spec's old six-value vocabulary (`restock / manual adjustment / import / sale / return /
defect`) **does not exist**. `inventory_ledger` carries **three independent columns**:

| Column | Allowed values | Meaning |
|---|---|---|
| `type` | `sale` \| `stock_adjustment` | Whether the movement came from selling or from adjusting |
| `sale_type` | `sale` \| `return` \| `defect` (null for adjustments) | Which after-sales event, when `type = sale` |
| `reference_type` | `transaction` \| `manual_adjustment` \| `csv_import` \| `return` \| `transfer` | What kind of record `reference_id` points at |

A **transfer** is a `reference_type`, not a fourth `type`. `type` decides which side of the sync
owns a row (`InventoryLedger::shouldPush()`, `Puller`'s filter, `StockReconciler`'s sum all key
on it), and a transfer leg is an ordinary `stock_adjustment` on both counts. Adding a `type`
value would silently be neither pushed nor pulled.

`inventory_ledger` also carries **`counterparty_branch_code`** (schema 21, nullable): the OTHER
branch a movement involved — who asked for it, or the far end of a transfer. A **code** and not
an id because Stock History is a Cashier-level screen that must render offline, and `branches`
is never pulled to a device, so an id could not be turned into a name there.

*Anchor:* `console/database/migrations/2026_07_26_000010_create_inventory_ledger_table.php`;
`app/Support/Inventory/StockLedger::record()`. Since schema 21 there is exactly **one** writer of
`type = 'stock_adjustment'`: `App\Support\Inventory\AdjustmentResolver`. There were three.

✗ **`restock` is not a term.** It appears in no code path. A restock is a *positive*
`stock_adjustment` with `reference_type = manual_adjustment` — a UI framing of an existing
shape, not a value. Do not use it in specs.
✗ `adjustment` and `import` are likewise not values: they are `stock_adjustment` rows
distinguished by `reference_type`.

### Movement kind
The reading of a ledger row a person recognises, as **one predicate over the three columns
above**: `sale`, `return`, `defect`, `manual_adjustment`, `csv_import`, `transfer`. It exists
because no single column answers "what kind of movement was this" — `type` alone reads
*Adjustment* for a recount, a CSV import, another branch's request and both legs of a transfer
alike, which since schema 21 is every non-sale row there is. Return and defect split on
`sale_type` and never on `reference_type`: both carry `reference_type = 'return'`. A transfer is
one kind and **two labels**, *Transfer out* / *Transfer in*, taken from the quantity's sign per
"Counterparty branch" below.
*Anchor:* `App\Support\Inventory\MovementKind` (which rows come back — the `movement` query
parameter on `/inventory/stock-history`); `resources/js/lib/stock-movement.ts` (what each is
called). Adding a kind means adding it in both.
✗ **Not a column and not a fourth `type`.** It is a presentation and filter vocabulary only.
Nothing about sync may key on it — direction of travel is decided by `type` alone
("Ledger classification for sync"), so a kind mistaken for a `type` value would be neither
pushed nor pulled.

### Ledger classification for sync
Which way a ledger row moves is decided by its columns, not by a separate type:
- **Local-write, pushed up:** `type = sale` (any `sale_type`).
- **Online-authoritative, pulled down:** `type = stock_adjustment`.

### Stock snapshot
The current on-hand quantity for one variant at one branch, stored as a row and updated on
every ledger insert. `check (quantity >= 0)`.
*Anchor:* `stock_snapshots`; `StockLedger::record()` locks it with `lockForUpdate()`.
✗ **Not** the stock baseline.

### Stock baseline
The **figure pulled from Postgres** during a sync cycle. It reflects online entries and
already-pushed local movements — never the till's *unsynced* ones.
*Anchor:* `App\Support\Sync\StockReconciler` reads it from Postgres's `stock_snapshots` on its
own `sync_cursor` row (M7).
✗ **Not** an internal number since M12. Ask `/inventory` for another branch's stock and the
baseline is what is on screen, unreconciled — that branch's unsynced sales are not deducted, so it
reads **high**, and it reads high in exactly the moment somebody is about to promise a customer
that the other branch has one. The page says so (`BaselineNotice`). This branch's own slice of an
"All branches" total is the baseline too, deliberately: one query reads every branch upstream,
because a total stitched from two sources at two moments is harder to explain than one that is
uniformly "as of last sync". `local` remains the only reconciled view, and is the till's default.

### Reconciled stock
`stock baseline − local unsynced out-movements`. The number the till actually sells against.
Self-healing: once the local movements push, the next baseline already contains them and the
unsynced set is empty.
*Anchor:* `StockReconciler::reconcile()`, which writes local `stock_snapshots` and nothing else.
✗ **Not** a conflict resolution. Stock is the one shared quantity, and it reconciles
**additively**; there is no last-write-wins anywhere in this system.
✗ **Not** implemented as the subtraction this entry names it by. It is `baseline + Σ(signed
unsynced quantities)` — identical arithmetic, but the local ledger is signed (a sale negative, a
return positive, a defect zero), so subtracting *absolute* values gets returns backwards and
hands the branch stock it never regained.
✗ **Not** a ledger movement. Reconciling appends no `inventory_ledger` row: nothing happened, it
is the same stock counted against a better source. Writing it through `StockLedger` would invent
inventory history and double-count against the pulled adjustment rows.

### Pending stock adjustment
A row of `inventory_update_queue`: a stock movement that has been **asked for and has not
happened**. Since schema 21 **every** stock movement starts as one — a recount typed at this
till, a CSV import, an adjustment aimed at another branch, and both legs of a transfer. It
becomes a ledger entry only when a person at the affected branch says it really happened.
*Anchor:* the `inventory_update_queue` table; `check ((status = 'pending') = (resolved_at is
null))`; `App\Support\Inventory\AdjustmentQueue` writes them,
`App\Support\Inventory\AdjustmentResolver` answers them,
`App\Http\Controllers\Inventory\InventoryQueueController` displays them.
✗ **Not** the outbox. See §5.
✗ **Not** a ledger entry *while it is pending*. Every answer now becomes one, including a
refusal, which writes a row of **quantity 0** (M11.8). It used to write nothing, and the branch
that asked never found out: `Puller` filters `inventory_ledger` to one branch, so the queue row
was the only record of a refusal anywhere and the page needed a "Sent to other branches" section
to show it. A movement of nothing plus §"Branch scope" on Stock History replaced both.
✗ **Not** kept on the page once answered. `/inventory/stock-updates` ("Pending Stocks") shows
**unfinished work only**: rows are grouped into *requests* by `parent_id ?? id`, and a request is
shown while any leg is still pending. The record of what happened is the ledger row, read in
Stock History.
✗ **Not** only for other branches, which is what M8 built and what schema 21 widened. The
narrow version mediated the cross-branch case because the issuer cannot see the target's unsynced
sales — an argument about arithmetic. The wider rule rests on something arithmetic cannot settle:
whether the goods are on the shelf.
✗ **Not** answered by the issuer. The branch the row is aimed at answers it, and
`AdjustmentResolver` re-reads every row filtered to that branch before writing anything.

### Resolution
Answering a pending stock adjustment. Three outcomes, and only the first two move stock:
- **Approve** — all of it happened.
- **Partial approve** — some of it did. Records `approved_quantity` (same sign as `quantity`,
  smaller in magnitude, never zero) plus a required reason.
- **Reject** — none of it did. Requires a reason. Moves no stock and still writes a ledger row,
  of quantity 0 (M11.8) — see the entry above for why.
*Anchor:* `AdjustmentResolver::resolve()`; `ResolveAdjustmentRequest`;
`BranchGrants::UPDATE_COLUMNS` — the **six** columns a resolution touches
(`status, resolved_at, resolution_reason, ledger_entry_id, approved_quantity, resolved_by`).
✗ **Not** `partial` as a fourth `status`. The DB constraint allows `pending | accepted |
rejected`; a partial approval is `accepted` with `approved_quantity` disagreeing with `quantity`.
✗ **Not** role-gated. **Any** authenticated user may resolve, Cashier included: whether a
delivery arrived is known by whoever is in the shop. Issuing one is still Admin+.
✗ **Not** possible offline, and never was — the list is an online read.
✗ **Not** `rejection_reason`. That column was renamed to `resolution_reason` at schema 21,
because it now also carries the reason for a partial approval.

### Transfer
Stock moving from one branch to another, as **two** pending adjustments answered by two different
people. The sending branch confirms what left (negative); confirming it spawns the receiving
branch's row (positive) for **what was actually sent**, not what was first asked for. Either end
may approve, partially approve, or reject.
*Anchor:* `reference_type = 'transfer'`; `inventory_update_queue.parent_id` links leg 2 to leg 1;
`AdjustmentResolver::secondLeg()`; `IssueTransferRequest`.
✗ **Not** one movement. Stock leaving one shop and arriving at another are two physical claims,
either of which can be false on its own. One row would make whichever branch did not answer the
author of the other's stock.
✗ **Not** signed by the operator. They enter how many are moving; the legs' signs are the
system's.

### Write-off in transit
The gap when a transfer's receiving branch approves less than was sent. The sender is down three,
the receiver up two, and **the business is genuinely one unit short** — that unit no longer
exists. Nothing is returned to the sender, and no compensating row is written: that would put
stock on a shelf that does not hold it. The receiver's resolution reason, carried onto their
ledger row's `notes`, is the record of where it went.

### Counterparty branch
The **other** branch a movement involved: who asked for an adjustment, or the far end of a
transfer. Null when a branch is adjusting its own stock, which is most rows.
*Anchor:* `inventory_update_queue.counterparty_branch_id` (an id, upstream);
`inventory_ledger.counterparty_branch_code` (a code, so it reads offline).
✗ **Not** the issuing *user*, which is `issued_by`.
✗ **Not** a direction. One column serves both ends of a transfer; the quantity's sign says
whether the stock went there or came from there.

---

## 5. Sync

### Sync cycle
The **single ordered unit of sync**, and the only thing that opens a Postgres connection:

```
0. version gate  →  1. push  →  2. pull  →  3. heartbeat
```

The order is load-bearing. Push runs first so the pulled baseline already reflects this
branch's own movements; the heartbeat runs last so it describes the cycle that just finished.
There was a **queue step between push and pull** until schema 21, which answered this branch's
pending adjustments automatically. A person answers them now, as an ordinary online write that
needs no cycle — and freezing builds that still run the old step is exactly why that schema
raised `MIN_APP_VERSION` for the first time.
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
✗ **Not** something a logged-in user is needed for. `SyncCycle::run()` takes no arguments and
reads its identity from [`instance`] and the sealed credential, so `POST /sync` is reachable by
a guest from M11.5 — see [pre-login cycle].

### Pre-login cycle
A cycle run **from the login screen**, by the same triggers and the same route as any other.
It exists for the pull: `users` comes down with it, so a staff member created at another branch
can sign in at this till without anyone signing in first.
*Anchor:* `POST /sync` outside the `auth` group; `SyncIndicator` rendered by
`resources/js/layouts/auth/auth-simple-layout.tsx` (M11.5).
✗ **Not** a new mechanism. Nothing about the cycle changed; only what could reach it did.
✗ **Not** unguarded. `RequireInitialSetup` still applies, so it is reachable only on a device
that already holds a branch credential.

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

### Online write
A change to an **online-authoritative** table — the catalog, users, settings, an import —
made from any branch device **while online**, and written to Postgres and the local mirror as
one atomic act. Refused when the device has no connection.
*Anchor:* `App\Support\Sync\OnlineWrite`; `App\Exceptions\OfflineWriteException` (M6.5).
✗ **Not** a push. A push carries what a branch *authors*, on its own schedule, and works
offline by design. An online write carries what every branch *shares*, so it has to be agreed
centrally at the moment it is made — queueing one would give those tables a second writer.
✗ **Not** "online-only". That phrase (§17) means only *requires a connection*, and treating it
as equivalent to *reaches Postgres* is the error that left every catalog and staff edit stranded
on one device until M6.5.

### Online read
A **live query against the business database serving one screen**, keeping nothing. Three things
are read this way and none has a local counterpart: the branch **roster** (`branches`), the
**pending stock adjustments** (`inventory_update_queue`), and the [consolidated report].
*Anchor:* `App\Support\Sync\OnlineRead`; `OfflineWriteException::forReading()` (M8).
✗ **Not** a pull. A pull fills the local mirror so the till can read it offline forever; an
online read mirrors nothing, which is exactly why these two tables need no migration, no
`sync_cursor` row and no line in the parity guard.
✗ **Not** version-gated, unlike an online write. The gate stops a stale build *writing* (§6);
refusing to render a screen would hide the display of the very problem.

### Consolidated report
The sales report recomputed **across branches** from the business database, rather than from this
device's mirror. Owner-only and online-only, on the same `/reports` screen as the branch-local
one, selected by [branch scope].
*Anchor:* `App\Support\Reports\SalesFigures` handed the Postgres connection (M11).
✗ **Not** a different recompute. One class serves both, with the connection as an argument, so
the two screens cannot disagree about what net sales means.
✗ **Not** available offline, and not because it was left out: `Puller` carries neither
`transactions` nor `sale_returns`, so no branch holds another branch's sales and none ever will.
✗ **Not** exempt from Item Cost being Owner-only. COGS and profit are omitted from the payload
for a non-Owner here exactly as everywhere else.

### Branch scope
Which branches a screen covers: **`local`** (this device's SQLite mirror — the default, works
offline), **`all`**, or a **branch id**. Echoed back to the screen *after* normalisation, so it
always names what was actually read rather than what was asked for.
*Anchor:* the `scope` query parameter on `ReportsController` (M11), `LoginHistoryController`
(M11.5), `Inventory\StockHistoryController` and `Inventory\InventoryQueueController` (both
M11.8), `SalesController` and `Inventory\ItemController` (both M12); `BranchScope` in
`resources/js/types/index.ts`; the picker
`resources/js/components/branch-scope-select.tsx` and
`App\Http\Controllers\Concerns\OffersBranchScope`, which fills it.
✗ **Not** a filter over one dataset. `local` and the other two read different databases.
✗ **Not** necessarily a swap of the whole page's source. On Inventory (M12) it changes **stock and
nothing else**: the item and variant list is read locally in every scope, because the catalog is
mirrored business-wide and the local rows *are* the upstream rows, and only `stock_snapshots` is
read live. The scoped figure lands on a key of its own (`scoped_stock`, plus a per-branch
`branch_stock`) — never on `current_stock`, which is a get-only cached accessor over the local
snapshot and would ignore the write while rendering this device's number under another branch's
heading.
✗ **Not** three values plus a fourth for this branch. **This** branch's id *is* `local`: it is
stripped from the picker's roster and `?scope=<own id>` snaps back to `local`, which answers the
same question offline and includes rows this device has not pushed yet. The two coincide only
while the MVP holds one device per branch (§"Till"), which is what makes the id redundant rather
than a second answer.
✗ **Not** silently downgraded when the connection is down. The screen falls back to `local` and
says so (`consolidatedOnline: false`); presenting one branch's rows under an "All branches"
heading is the failure this design refuses.
✗ **Not** one permission. Reports restrict the wider scopes to the **Owner** (they carry cost
and profit); login history, stock history, sales and inventory stock allow **Admin+**, per
`MULTIBRANCH-BRAINSTORM.md` §6; Pending Stocks gates it on nothing at all, because every row it
lists was already visible to every role and what bounds *answering* one is the branch rather than
the role (§"Resolution"). A viewer who may not widen it is snapped back to `local` rather than
refused, since the local view is theirs by right and sits on the same route. Sales is Admin+
rather than Owner-only for the reason Reports is not: a sales *list* carries no cost and no
profit. The sales **detail** still gates cost per line, in both scopes — and there the guard is
the SELECT column list alone, because `HidesCostFromNonOwners` is a model hook and does not run on
the query-builder rows a cross-branch read returns.
> Called **report scope** until M11.5, when a second screen took the same parameter with the
> same meaning. One word, because a synonym is exactly what §0 forbids.
> Stock History took it in M11.8 for a reason the other three did not have: not "the Owner would
> like to see everything" but that a **refusal is unreadable without it**. The pull filters the
> ledger to one branch, so the branch that asked another branch to move stock can only ever read
> the answer upstream.

### Pull
Fetching **deltas** of pulled tables by `updated_at`, plus the recomputed stock baseline.
*Anchor:* `App\Support\Sync\Puller` (M7) — `settings`, `users`, `categories`, `items`, `variants`
and `type = 'stock_adjustment'` ledger rows.
✗ **Not** a hydrate. See §3.
✗ **Not** every *pulled* table. `branches` has no local counterpart (identity lives in
`instance`), and `schema_meta` is pulled by the version gate at the top of every cycle, which is
why `Puller` does not read it again.

### Sync cursor
`sync_cursor` — one row per pulled table recording how far down it this device has read. The
bookmark that turns "re-read the whole catalog" into "what changed since?".
*Anchor:* `App\Models\SyncCursor`; the `sync_cursor` table (M7).

Three properties, each load-bearing and none of them obvious:

- **`pulled_through` is Postgres's own rendering of the watermark, stored as a string and never
  parsed locally.** `timestamptz` carries microseconds and an offset; this app's datetime
  convention is a naive second-resolution local string. Converting would land the bookmark up to
  a second away from the row it was read off — re-reading a second of rows every cycle, or
  stepping over one, silently and permanently.
- **The comparison is `>=`, never `>`.** Two rows edited in the same instant share a timestamp,
  and `>` strands the second behind the bookmark the first one set. The cost is re-reading the
  boundary row each cycle, which the upserts absorb.
- **The position is the newest row actually READ, never `now()`.** A Postgres row's `updated_at`
  is stamped when its transaction begins and becomes visible when it commits, so a slow edit
  carries a timestamp earlier than the moment it appears. Bookmarking the clock would leave such
  a row behind the mark forever.

✗ **Not** one global watermark. Per table, so a failure pulling `variants` does not discard the
progress made on `users`, and a table added later starts from zero without re-pulling everything
else.

### Heartbeat
The **upsert of this branch's `branch_status` row** at the end of a cycle — step 4. One row per
branch — current state, not history (`branch_id` is the primary key).
*Anchor:* `App\Support\Sync\Heartbeat` (M10); the table is
`console/database/migrations/2026_07_26_000018_create_branch_status_table.php`.
✗ **Not** a connection of its own. It runs inside the cycle's connection, on both ways out of
it: after the pull on success, and from a `catch` before the exception is re-thrown.
✗ **Not** able to report every state it can carry. `offline` and `unauthorized` are diagnosed
by the connection failing to open, so the branch they describe never gets to write them —
which is why **last seen** and not `connection_state` is what the monitor colours.
✗ **Not** allowed to fail loudly. It swallows its own errors: a report about a cycle may never
become that cycle's outcome.

### Last seen
`branch_status.last_seen_at` — the last moment a branch **reached Postgres at all**, written on
every heartbeat including the failing ones.

### Last sync
`branch_status.last_sync_at` (and `sync_status.last_sync_at` locally) — the last moment a cycle
**ran all the way through**. A failed beat leaves it standing, so the gap between the two is
how long a reachable branch has been stuck.
✗ **Not** interchangeable with [Last seen]. Fusing them erases the one branch worth calling
about: the one that is online, talking, and getting nothing done.

### Monitor
The console screen showing **what every branch is doing** — app version, schema version,
backlog, last sync, last seen, connection state — aggregated from `branch_status` across every
business schema.
*Anchor:* `monitor` (`Provisioning\Console\Commands\MonitorCommand`, M10), reading
`Provisioning\Support\BranchStatusDirectory`.
✗ **Not** `branch:list`, which reports what the console DID to a branch's credential. The two
answer different halves of "why has this till gone quiet", which is why the monitor prints the
credential state of any branch that is not `active`.

### Outbox listener
The client-side trigger that asks for a cycle **when the backlog grows**. Every server response
carries `unsynced_count` in the shared `sync` prop, read from the outbox, so a sale, return,
login or audit row produces a response and a cycle fires seconds later.
*Anchor:* `useSyncTriggers()` in `resources/js/hooks/use-sync.ts` (M7), mounted once in
`SyncIndicator` — which the app header renders on every authenticated screen and the auth layout
renders on the login screen (M11.5). Those two never coexist, so there is only ever one mount and
one backstop timer.
✗ **Not** polling. It reacts to a number already on its way to the client; nothing asks the
server whether there is work.
✗ **Not** able to see the pull. Branch A editing a price writes nothing at all on Branch B, so
B's outbox stays empty however much changes upstream. That is the backstop timer's job.
✗ **Not** unconditional. Suppressed while the last cycle ended `unauthorized`,
`version_blocked`, `offline`, or `online` with a `conflict` — a backlog only a person can clear
would otherwise re-fire on every page navigation.

### Opportunistic sync
A cycle fired **immediately after a checkout** when online, so Postgres trails by seconds
rather than a full interval. Still never in the commit path.
*As built (M7): retired as a distinct mechanism.* M6 implemented it as an explicit `runSync()`
in `pos.tsx`; the outbox listener above now covers it, and covers returns, logins and audit rows
that the special case never did. The behaviour it names is unchanged — a cycle within seconds of
the sale, never awaited — so the term stays useful for describing *when* a cycle happens.

### Backstop timer
The fixed interval that runs a cycle even if nothing else triggered one. **5 minutes**,
hard-coded, the same online and offline.
*Anchor:* `BACKSTOP_MS` in `resources/js/hooks/use-sync.ts`.
*As built (M7): the value moved from 15 minutes and the job changed with it.* §11.3 sized this
timer to stop a till being "stuck offline indefinitely". Once the pull exists it is also the
**only** trigger for an already-online till, because nothing local signals upstream change — so
it is the freshness bound on shared data, not merely a safety net. One interval with no knob,
which is §11.3's actual constraint, is untouched.
✗ **Not** something that refreshes the screen. Like every automatic trigger it brings back the
indicator's prop alone — see **Manual sync**.

### Manual sync
A cycle asked for by a **person pressing the Sync now button**, as against the four automatic
triggers. The cycle itself is identical; what differs is what comes back — a manual sync also
**re-runs the current page's controller**, an automatic one refreshes only the shared `sync`
prop the indicator reads.
*Anchor:* `run(true)` from `SyncIndicator`'s `onClick`, against the bare `run()` of
`useSyncTriggers()`, in `resources/js/hooks/use-sync.ts`.
✗ **Not** an optimisation of the automatic triggers, and not conditional on the cycle moving
anything. The consolidated `scope` views are **online reads** inside their controller and
`login_history` is not in the puller at all, so for those screens re-running the controller is
the *only* mechanism that shows another branch's rows — a refresh gated on `pulled > 0` would
skip exactly the case it exists for.
✗ **Not** withheld from the automatic triggers because state would be lost. Inertia's
`reload()` forces `preserveState`, so nothing is ever lost; the reason is **movement** — a
background cycle must not re-sort a POS grid or shift table rows under someone mid-task.

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

### Conflict (as an error kind)
`sync_status.last_error_kind = 'conflict'`, paired with `connection_state = 'online'`: the
branch reached the business database and the business database **refused the data** — a
duplicate serial, a missing grant. The backlog will not clear on its own.
*Anchor:* `SyncStatus::KIND_CONFLICT`; classified by SQLSTATE class in
`SyncCycle::isConflict()` — 23 (integrity constraint) and 42 (access rule, i.e. a missing
GRANT) are conflicts; 08 (connection exception) stays `network` (M6.5).
✗ **Not** `offline`, which is what M6 reported for it. The two need opposite responses: one is
fixed by waiting and this one never is.

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
✗ **Not** `branch_status`, which is the Postgres row the [Heartbeat] upserts. Same four
`connection_state` values on purpose, so the heartbeat is a straight copy rather than a lossy
one — though only `online` and `version_blocked` ever survive the trip, since the other two
mean the connection never opened.

---

## 6. Versions and the gate

Three quantities, two of which are routinely confused. The gate compares exactly one pair.

### App version
The **build on a branch device**. A semver string.
*Anchor:* `config('nativephp.version')` (`NATIVEPHP_APP_VERSION`, default `1.0.0`);
recorded in `branch_status.app_version`.

### Schema version
The **integer** state of the Postgres table structure, raised by migrations — concretely, **the
number of migrations a fully-migrated business schema has applied**. Not a number anyone
chooses: `SchemaVersionTest` asserts it equals the file count, so it cannot drift from the set
it describes.
*Anchor:* `Provisioning\Support\SchemaVersion::SCHEMA_VERSION` and its `migrationCount()`;
stamped into `schema_meta.schema_version` and echoed in `branch_status.schema_version` and
`instance.schema_version`.
✗ **Never compared.** Both sides record it so a human can tell which structure a schema is in.
The gate compares min app version and nothing else.

### Min app version
The **oldest app version the current schema can safely talk to**, a semver string declared by
the schema. Raised **only** for a breaking migration — an additive one must leave it alone, or
every routine update needlessly freezes every branch.
This is the one quantity here that **cannot be checked by anything**: whether a change breaks an
older build is a claim about what that build's code does. The schema-version tripwire above
exists largely to force the question at the moment it arises, since the same edit is where this
decision has to be made. What *is* checked is that it never exceeds the app version this repo
can build — a floor above the newest possible build freezes every branch with nothing to install.
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

### Version stamp
The **act** of writing the declared pair — schema version and min app version — into a
schema's `schema_meta`. The three entries above are quantities; this is the only thing in the
project that ever *moves* two of them after a schema is created.
*Anchor:* `Provisioning\Support\SchemaStamp::apply()`, reading
`Provisioning\Support\SchemaVersion`'s two constants; performed by `schema:migrate` after a
clean run (M9).
Two rules travel with the word: a stamp is written **only when the pair changed** (so
`applied_at` means "when this schema reached this version"), and it **never moves either value
backwards** (a schema ahead of the console running against it was migrated by a newer checkout,
and lowering it would un-freeze branches a breaking migration deliberately froze).
✗ **Not** a migration. Nothing about the table structure changes; a stamp only records what
structure the schema is now in.

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

### Advance (a schema)
A `schema:migrate` run in which **at least one pending migration actually ran**, as against a
run that applied nothing. The word carries weight because the two outcomes are treated
differently: only an **advanced** schema gets its branch grant matrix re-applied, since only an
advanced schema can have gained a table those roles hold no privilege on
(`Provisioning\Support\BranchGrants` enumerates per table for exactly that reason).
*Anchor:* `SchemaMigrateCommand::migrateOne()`, which decides it by counting the `migrations`
ledger before and after (M9).
✗ **Not** a synonym for "migrated". `schema:migrate` is idempotent and most runs are no-ops;
calling those "migrated" is true and useless, and re-granting on each one would train an
operator to stop reading the output.

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
An **Owner or Admin, on any branch device, while online, may ask for a stock adjustment at any
branch of their business, or move stock between two of them.** They choose the target in the
in-app admin screens; `inventory_update_queue` records the target (`branch_id`), the other end
where there is one (`counterparty_branch_id`), and the asking user (`issued_by`).
*Anchor:* the `inventory_update_queue` table.
✗ **"HQ" and "online admin" are retired.** There is no head-office application, no separate
console-side actor, and no fourth permission role. Both words meant this one actor.
✗ **Not the same actor as the one who ANSWERS.** Asking is Admin+; answering is any role at the
affected branch, and `resolved_by` records who. See [Resolution] in §4.
✓ **Built (M8, widened at schema 21).** `inventory_update_queue` is in `BranchGrants::INSERT`,
delivered to existing businesses with `schema:reapply-grants`. It knowingly widened the deferred
RLS gap — grants stop at the schema boundary, so a credential able to queue an adjustment for its
own branch can queue one for any branch of the same business. See §9.

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
✓ **The connectivity gate this entry owed is built** (M6.5): `InventoryImportController` refuses
on `ConnectivityProbe::online()`, `import.tsx` renders `OfflineNotice` and disables the form, and
`CsvInventoryImporter` runs inside an `OnlineWrite` — so an offline import is refused by the
server, not merely discouraged by the screen.

---

## 9. Accepted risks and open questions

Two different things, kept apart on purpose. An **accepted risk** is settled: somebody decided not
to build the mitigation, and the reason is written down so the decision is not made again by
whoever reads this next. An **open question** has no answer yet.

### Accepted: no row-level security between branches inside one business schema

Grants stop at the schema boundary, so a compromised branch credential can write rows tagged with
another branch's `branch_id`, and since M8 can queue a stock adjustment against another branch.
This is **not planned**, and that is a decision rather than a backlog item.

**Why it is acceptable.** Every branch of one business belongs to **one Owner**, and a branch
credential lives on a device that business physically controls. Anyone holding that credential
already has the till: they can sell, refund, read that branch's entire history and reach the whole
catalog. Forging another branch's `branch_id` is a marginal escalation on top of already having
all of that — not a new class of access. The boundary that carries real weight is
**business-to-business**, and that one is schema-level, enforced by Postgres, and proved by
`BranchRoleGrantsTest::test_it_cannot_reach_another_business`. It is untouched by any of this.

**Why RLS is not a cheap fix either.** It would mean a policy on every table plus a per-branch
session variable, and branch devices connect through Supabase's **transaction pooler**, where no
session state survives a cycle (§5 "sync cycle" — no `SET`, no session GUCs). The mechanism RLS
normally leans on is the one thing this architecture has already given up.

**Therefore the grant matrix is the only boundary of this kind, and it must not erode.** These are
rules, not preferences:
- `BranchGrants` is enumerated **per table** — never `GRANT ... ON ALL TABLES`, never
  `ALTER DEFAULT PRIVILEGES`, so a table added by a future migration grants nothing by default.
- **DELETE is granted on nothing, anywhere.** There is no `DELETE` constant in `BranchGrants` at
  all.
- `inventory_update_queue` stays **column-level** UPDATE on its resolution columns — **six**
  since schema 21 (`status, resolved_at, resolution_reason, ledger_entry_id, approved_quantity,
  resolved_by`), because a person rather than a cycle now answers these and can agree to PART of
  what was asked. Still strictly less than table-level, which would expose `quantity`,
  `variant_id`, `branch_id` and `issued_by` — a branch that could rewrite those could approve
  itself more than it was sent, or aim a transfer somewhere else.
- Any future widening states its reason in that phase's note, the way M6.5, M8 and schema 21 did.

*Anchor:* `BranchGrants`; `BranchRoleGrantsTest::test_the_live_grants_match_the_documented_matrix`
keeps live Postgres equal to the matrix, so nothing widens quietly;
`test_it_can_issue_a_queued_adjustment_for_any_branch_of_its_business` asserts the accepted reach
rather than describing it.

### Open: nothing currently

Items deferred for scheduling rather than decided against live in
`MULTIBRANCH-BRAINSTORM.md` §19.
