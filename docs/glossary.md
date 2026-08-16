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
The programme of work — phases **M0–M13.5** — that turns Evitan POS into Evitan Plus. Use this
phrase for the *work*; use "Evitan Plus" for the *product*.
✗ **Not** "M0–M12", which this entry said until the audit that added this line. The initiative
ran past M12 four times — see [Phase numbering].

### Phase numbering
**M-numbers are not dense and not chronological.** Work that arrived between planned phases took
a decimal — M6.5, M11.5, M11.6, M11.7, M11.8, M13.5 — so the sequence reads
M11 → M11.5 → … → M11.8 → M12 → M13 → M13.5, and a higher number is not always later work.
*Anchor:* the `# Phase` headings of `MULTIBRANCH-ROADMAP.md`, in order.
✗ **Not** two meanings of **M12**. It means the **branch scope on sales and inventory stock**
phase. It briefly also titled a "Hardening, testing & release verification" phase in the roadmap
while three other documents were using it for the feature work; the hardening tasks are standing
obligations rather than a milestone, so they became the un-numbered **Ongoing** section and the
number resolved to the feature phase.
✗ **Not** a guide to what is built. M11.7 and M11.8 each reversed a decision of the phase before
— read the `~~strikethrough~~ **No longer true.**` markers, not the earliest section that
mentions a thing.

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
*Anchor:* `tests/manual/rig.sh`; `composer.json` `scripts.rig` / `rig:wizard` / `rig:clean` /
`rig:clean:all`; `tests/manual/.runtime/state.env`.
Its business is a **disposable schema** (§3), which is what lets `rig:clean:all` recover a run
that died leaving no state file at all.
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
✗ **Not** the same call everywhere: `InventoryQueueController` reads it **both** ways. Its
private `roster()` excludes disabled branches, because that list picks a branch to send an
adjustment *to*; `BranchRoster::read()` is used alongside it to *name* a branch on a card, and
must include disabled ones, because a request answered before the credential was disabled still
has to render. Since M11.8 the screen's main job is answering, so the naming call is the common
one.
*Anchor:* `InventoryQueueController::roster()` (excluding) vs its `BranchRoster` use (naming).

---

## 3. Stores and schemas

The word "schema" carries three unrelated meanings. All three are in active use, so each gets
its own phrase.

### Business schema
The Postgres schema **`biz_<32-hex of the business UUID>`** holding every table and **every
branch's** data for one business. A business never spans more than one.
*Anchor:* `console/app/Support/SchemaName.php` (`forBusiness()`, `/^biz_[a-z0-9_]{1,58}$/`).

### Disposable schema
A business schema named **`biz_test_<tail>`** instead of `biz_<hex>`, declaring itself
**throwaway**: `composer rig:clean:all` destroys every one of them, unasked and without
looking at what is inside.

Created by exactly two things — `business:provision --disposable` (the rig, the console
fixture, the integration fixture), which puts the 32-hex tail there; and
`console/tests/TestCase.php`'s own pre-migrated fixtures, which put twelve random
characters there. Both are throwaway, so the sweep tests the **prefix** and not the tail.

The point is that the namespace is **unreachable by accident**. `forBusiness()` maps a UUID
to `biz_<hex>` and cannot produce `biz_test_` without the flag, so no real business can land
in the swept namespace whatever its actor, business name or branch code. That is what makes
`sweep` a different act from the reaper `tests/manual/pg.php` refuses to be: a sweep over
`biz_%` is forbidden and always will be.

Two consequences worth knowing. The mark is the **name**, so it exists from `CREATE SCHEMA`
onward and a provision killed by a SIGINT is still swept — a mark in `provisioning_audit`
could not manage that, since it is written only on a terminal outcome. And a disposable
business is targeted by **`--schema=biz_test_<hex>`, never `--business=<uuid>`**: the reverse
derivation handles both prefixes, but the forward one cannot know which was meant.
*Anchor:* `SchemaName::DISPOSABLE_PREFIX` / `isDisposable()`; `tests/manual/pg.php sweep`;
`composer.json` `scripts.rig:clean:all`.
✗ **Not** a signal about the data. A disposable business is a real, fully migrated business
with real sales in it — the rig seeds a year of them. Disposable says who may delete it, not
what it holds.

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

### The four inventory screens
One sidebar group, four screens, and the split between them is load-bearing:

| Screen | Route | Is about | Branch scope |
|---|---|---|---|
| **Items** (the *catalog*) | `/items` | What the business sells — items, variants, SKUs, prices, categories at `/items/categories` | none |
| **Inventory** (the *shelf*) | `/inventory` | How many of each variant are on hand, one row per variant | `local` or a branch id, never `all` |
| **Pending Stocks** | `/inventory/stock-updates` | Movements asked for and not yet answered — the queue, one card per [request] | `local`, `all`, or a branch id |
| **Stock History** | `/inventory/stock-history` | Movements that already happened — the ledger | `local`, `all`, or a branch id |

*Anchor:* `ItemController`, `InventoryController`, `Inventory\StockHistoryController`,
`Inventory\InventoryQueueController`; the sidebar group in `resources/js/components/app-sidebar.tsx`.
✗ **Not** one "Inventory page". Items and Inventory shared a screen until **M13** split them,
because a total is not a shelf: nothing on the catalog is per-branch, and everything about stock
is. Do not use "Inventory" for the catalog.
✗ **Not** "Stock Updates", which is only the route segment. The screen is **Pending Stocks**, and
it holds unfinished work only — what happened is in Stock History.
✗ **Not** all gated alike. Issuing a movement is Admin+; *answering* one is open to any role at
the affected branch, so Pending Stocks itself gates on nothing. See [Resolution].

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

And **`batch_id`** (schema 23, nullable, no FK): the stock batch this movement was one line of,
carried from the queue row by the resolver so "these came off the shelf together" reads offline
too. Null on anything issued singly. See §"Stock batch".

`csv_import` stayed in the `reference_type` list through M13 with **nothing writing it**, because
the item file describes products and a spreadsheet cannot assert what is on a shelf. It is
written again by the **opening-stock import**, and only inside the opening window — see
§"Opening stock" and §"Opening window" below. Which is why keeping the value was worth doing:
these rows render on every screen and on every build back to Phase 7 without a change anywhere.

*Anchor:* `console/database/migrations/2026_07_26_000010_create_inventory_ledger_table.php`;
`app/Support/Inventory/StockLedger::record()`. Since schema 21 there are exactly **two** writers
of `type = 'stock_adjustment'`: `App\Support\Inventory\AdjustmentResolver`, and
`App\Support\Inventory\OpeningStockImporter` while the opening window is open. There were three
before schema 21, one after it, and the second one back is a deliberate carve-out with a stated
reason — see §"Opening window". Do not add a third.

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
that the other branch has one. The page says so (`BaselineNotice`). `local` remains the only
reconciled view, and is the till's default — which is also why asking that screen for *this*
branch by id gets `local` back rather than this branch's own baseline.

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
happened**. Since schema 21 every stock movement starts as one — a recount typed at this till,
an adjustment aimed at another branch, a stock batch, and both legs of a transfer — with the
single exception of an **opening-stock import** (see §"Opening window"). It becomes a ledger
entry only when a person at the affected branch says it really happened.
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
**unfinished work only**: rows are grouped into *requests* by `batch_id ?? parent_id ?? id`, and a
request is shown while any leg is still pending. The record of what happened is the ledger row,
read in Stock History.
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

### Stock batch
**One set of pending stock adjustments issued together**, sharing a `batch_id` and one note. Either
an adjustment at one branch or a transfer between two, never a mix — the quantity on a line means a
different thing in each. Every issuing path can produce one; what makes it a batch is that a person
asked for all of it in a single act, and Pending Stocks answers it as a single card.
*Anchor:* `inventory_update_queue.batch_id` and `inventory_ledger.batch_id` (schema 23, both
nullable, no foreign key); `App\Http\Controllers\Inventory\StockBatchController`;
`StoreStockBatchRequest`; `AdjustmentQueue::enqueue()` writes the column and
`AdjustmentResolver::secondLeg()` carries it onto a transfer's receiving legs.
✗ **Not** "mass update". The batch is the *act*, not the size of it: a batch of one is a batch, and
nothing about it is bulk-editing anything.
✗ **Not** a fourth `reference_type`. A batch's rows are `manual_adjustment` or `transfer` like any
others; `batch_id` is orthogonal to what kind of movement each row is.

### Batch reference
**The name of a stock batch that a person can say out loud** — `MNL-B-000001`: the code of the
branch that *asked*, then a zero-padded running serial. It is the second name of the same act, and
the two are not interchangeable: `batch_id` is the **identity** everything groups and joins by, and
the reference is what a card prints, somebody writes on a delivery note, and the Stock History
`?batch=` filter takes. That filter has a **search box** on the screen and matches a **substring**
of the reference, case-insensitively: the part of the name a person reads back off a delivery note
is the serial, and a whole reference still resolves to one batch because the serial is padded to a
fixed width, so no reference is a prefix of another. Minted once, when the batch is issued, and
**stored** on every queue row and every ledger row it produces, so it reads the same on both and
renders with no connection.

The shape is the receipt number's for the receipt number's reason: branches mint independently, so
the branch prefix is the only thing keeping two branches' serials apart. `-B-` is what stops it
being read as a receipt number, since both appear in this app to the same people. The prefix names
the branch that **issued** the batch, which for a transfer arranged from head office is neither end
of it.

Null on anything issued singly and on every row written before schema 24; every surface that prints
it falls back to what it printed before.
*Anchor:* `App\Support\Inventory\StockBatch` (the object holding both names, and the mint);
`inventory_update_queue.batch_reference` and `inventory_ledger.batch_reference` (schema 24, both
nullable, neither unique — every row of one batch carries the same value, and what keeps it unique
per batch is the advisory lock in `StockBatch::mint()`).
✗ **Not** a batch *number* in the receipt's sense. A receipt serial is gapless because a tax
authority requires it; a rolled-back batch simply never claims its name, and nothing audits the
series.
✗ **Not** derived from `batch_id`. It is not a shortened uuid — it is minted from the queue's own
max, and reading one off the other is impossible in either direction.
✗ **Not** the outbox, and not a *request* either — a request is one card, and a batch is what makes
a card span many products. Reserve "batch" for the issued set. A set of *answers* submitted
together (what `resolve` takes) is not one.
✗ **Not** answered as a unit. "Approve all" posts the card's ids to the same route one Approve
uses, and `AdjustmentResolver` re-reads each of them filtered to this branch. There is no
batch-shaped write path on the answering side, deliberately — and per the line above, that set of
answers is not itself "a batch".
✗ **Not** derivable. Two rows issued a second apart by the same person are not a batch, which is
exactly why the column exists rather than a heuristic over `created_at`.

### Request
**One card on Pending Stocks**: the queue rows that were asked for as a single act, grouped by
`batch_id ?? parent_id ?? id`. One row for a lone adjustment, two for a transfer, many for a
[stock batch], and a batch transfer is many rows at each of two branches. Shown while **any** of
its legs is still pending.
*Anchor:* the grouping key in `InventoryQueueController`; `resources/js/components/inventory/
pending-request-card.tsx`; the progress stepper (Requested → Sent → Received).
✗ **Not** a row. A row is a [leg]; the request is what the legs add up to. This is why `item` and
`variant` sit on the leg rather than the request — a batch's legs are different products.
✗ **Not** a Laravel `Request`. `StockHistoryController::request()` and the route
`stock-history.request` return the *queue row behind a ledger entry*; `IssueTransferRequest` and
`ResolveAdjustmentRequest` are FormRequests. Three unrelated meanings — say "the request card"
when the card is meant.
✗ **Not** a batch. A batch is one *cause* of a card spanning many products; a transfer is
another, and a lone adjustment is a card too.

### Leg
**One row of `inventory_update_queue` seen as part of a [request]**, and the unit a person
actually answers. An adjustment is one leg; a [transfer] is two, linked by `parent_id` and
answered at two different branches.
*Anchor:* `inventory_update_queue.parent_id`; `AdjustmentResolver::secondLeg()`.
✗ **Not** interchangeable with "row". Use "leg" when the pairing matters — that a shortfall on
the sending leg is what the receiving leg is issued for, and that the sender's answer is shown on
the receiver's card because the receiver is answering for what the sender said left.

### Line
**The pairing key on a [leg]'s payload**: `parent_id ?? id`, so a transfer's two legs share one
`line` and a lone adjustment is a line of its own. It is what lets one [request] card span many
products and still show the sending and receiving answers for *the same product* side by side —
without it, a batch transfer of six products is twelve legs with nothing saying which receiving
leg answers which sending one.
*Anchor:* `InventoryQueueController` builds it per leg as `(string) ($leg->parent_id ?? $leg->id)`,
alongside `requested`.
✗ **Not** a column. `inventory_update_queue` has no `line`; it is derived on the way to the
screen, from the `parent_id` that already links the pair. Nothing persists or indexes it.
✗ **Not** the same key the card is grouped by. Grouping is `batch_id ?? parent_id ?? id` — one
card; `line` subdivides that card into products.
✗ **Not** a line *item*. Those are `transaction_items` and `sale_return_items`, on a sale.
✗ **Not** a row of a CSV. That is a **file line**, which both importers call `line` in their own
row arrays — an unrelated third use.

### Opening window
The stretch of a branch's life **before it has sold anything**: no `inventory_ledger` row of
`type = 'sale'` exists. While it is open, and only while it is open, opening stock may be imported
from a spreadsheet straight to the ledger.
*Anchor:* `App\Support\Inventory\OpeningWindow::isOpen()`; the `openingWindow` prop on
`/inventory`; `OpeningStockImportRequest::authorize()`; `OpeningStockImporter::assertWindowOpen()`.
**Why it exists.** The queue's rule — that a stock figure is a claim about a shelf somebody has to
go and look at — is right about a shelf with a history and empty about a shelf with none. A device
off the setup wizard has every variant at zero: for a new business because `business:provision`
seeds no catalog, for a new branch because a branch that has never traded has no `stock_snapshots`
rows to hydrate. Routing an opening count through the queue would make one person confirm a file
they typed a moment earlier, and would run a six-hundred-line catalog into a hundred-line batch cap.
✗ **Not** stored. It is asked of the ledger on every render, so it cannot drift. Two things fall
out: a **rebuilt** device at an established branch hydrates that branch's sales (M11.6) and so is
correctly shut, and a genuinely new branch stays open across as many sittings as onboarding takes.
✗ **Not** a general exemption from schema 21. It is one carve-out with one reason, and the reason
expires the moment the branch trades. A second writer of `type = 'stock_adjustment'` outside it
would be the thing §"Ledger entry type" says not to add.
✗ **Not** closed by a sale alone: a return or a defect closes it too. All three are `type = 'sale'`
and split on `sale_type`, and any of them means the branch is trading.

### Opening stock
The starting count a branch's shelf is set to before it trades, and the **only bulk path into
stock** there is. A two-column CSV — `sku, quantity` — imported from `/inventory` while the
opening window is open, written straight to `inventory_ledger` through `StockLedger` inside one
online write and mirrored upward with `RemoteStock::recompute()` behind it.
*Anchor:* `App\Support\Inventory\OpeningStockImporter`; `OpeningStockCsvSchema`;
`App\Http\Controllers\Inventory\OpeningStockController`; `import_history.mode = 'opening_stock'`;
`reference_type = 'csv_import'` on the rows it writes.
✗ **Not** the item CSV, and deliberately a second file. That one describes **products** and is the
same at every branch; this one describes **one branch's shelf**. Columns it does not recognise are
ignored, so one spreadsheet with a `quantity` column added can be uploaded to both in turn — but a
`stock` column on the item file still does nothing, as it has since M13.
✗ **Not** an amount to add. A quantity is the **resulting on-hand**, applied as
`quantity − current stock` — the `set` semantics of §"Stock batch", legal here because the shelf
being subtracted from is this device's own reconciled figure. Which makes the file re-uploadable:
run it twice and every delta is zero, so a mistyped count is fixed by uploading again.
✗ **Not** written for a quantity that changes nothing. A line already matching the shelf writes
**no** ledger row, including a zero on a variant at zero — the absence of a movement, not a
movement of nothing (`MULTIBRANCH-ROADMAP.md` M7). A snapshot with no ledger behind it makes the
upstream recompute go negative and refuses every cycle the branch ever runs again.
✗ **Not** able to create products. Every SKU must already exist; an unknown one fails the whole
file. That is what makes the new-branch case "the stocks of the current catalog" rather than a
second way into the catalog.
✗ **Not** possible offline, like every other write to shared data.
✗ **Not** aimable at another branch, and the request must **say so**. It carries a `scope` that
`OpeningStockImportRequest` refuses unless it is `local` or this branch's own id — including when
it is absent, since omission would be the one bypass a stated intent must not have. This is the
only place a branch scope is an *assertion by the caller* rather than a choice: `/inventory` shows
another branch's stock under that branch's heading while the rest of the page stays local, and an
import fired from there would be stamped `branch_id` from `instance` by `OnlineWrite`, land on
this branch, and report success. Hiding the button under a wider scope is the convenience; the
refusal is the rule.

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
A **live query against the business database serving one screen**, keeping nothing. Two of the
tables read this way have no local counterpart at all — the branch **roster** (`branches`) and
the **pending stock adjustments** (`inventory_update_queue`) — and from M11 onwards the same
mechanism also serves the wider [branch scope] on screens whose tables *are* mirrored: reports,
sales, login history, stock history and inventory stock all read other branches' rows live,
because a mirror holds only this branch's.
*Anchor:* `App\Support\Sync\OnlineRead`; `OfflineWriteException::forReading()` (M8).
✗ **Not** three things, which this entry said until the audit that added this line. It is
injected into six controllers plus `OffersBranchScope`; the count grew at M11, M11.5, M11.8,
M12 and M13 without the entry being touched, which is why the entry now names the *rule* rather
than a list.
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
always names what was actually read rather than what was asked for. Inventory offers only two of
the three — see the fourth bullet.
*Anchor:* the `scope` query parameter on `ReportsController` (M11), `LoginHistoryController`
(M11.5), `Inventory\StockHistoryController` and `Inventory\InventoryQueueController` (both
M11.8), `SalesController` and `Inventory\InventoryController` (both M12; the latter was
`Inventory\ItemController` until M13 split the catalog off it); `BranchScope` in
`resources/js/types/index.ts`; the picker
`resources/js/components/branch-scope-select.tsx` and
`App\Http\Controllers\Concerns\OffersBranchScope`, which fills it.
✗ **Not** a filter over one dataset. `local` and the other two read different databases.
✗ **Not** necessarily a swap of the whole page's source. On Inventory (M12) it changes **stock and
nothing else**: the variant list is read locally in every scope, because the catalog is mirrored
business-wide and the local rows *are* the upstream rows, and only `stock_snapshots` is read
live. The scoped figure lands on a key of its own (`scoped_stock`) — never on
`current_stock`, which is a get-only cached accessor over the local snapshot and would ignore the
write while rendering this device's number under another branch's heading.
✗ **Not** the same three values on every screen. Inventory takes **`local` or a branch id and
never `all`**: everywhere else a wider scope adds rows to a list, but there it would have to add
them to a *number*, and a business-wide total per variant answers nothing on its own — twenty
units is twenty spread evenly or twenty at one branch and none at the other four. It is the one
value of the parameter that needed a second display (a per-branch breakdown under every figure)
to be readable at all, so it was dropped rather than kept. `InventoryController::normalizeScope()`
snaps `?scope=all` back to `local` like any other scope it cannot serve, and the shared picker
takes `allowAll={false}` there.
✗ **Not** three values plus a fourth for this branch. **This** branch's id *is* `local`: it is
stripped from the picker's roster and `?scope=<own id>` snaps back to `local`, which answers the
same question offline and includes rows this device has not pushed yet. The two coincide only
while the MVP holds one device per branch (§"Till"), which is what makes the id redundant rather
than a second answer.
✗ **Not** silently downgraded when the connection is down. The screen falls back to `local` and
says so (`consolidatedOnline: false`); presenting one branch's rows under an "All branches"
heading is the failure this design refuses.
✗ **Not** on every screen with data worth widening. **Items** (`/items`) carries no scope at all,
and that is the M13 split working: nothing on the catalog is per-branch, so there is nothing for a
scope to change. Stock moved to `/inventory`, and the parameter went with it.
✗ **Not** on every screen that reads across branches. **Employee Performance**
(`/employee-performance`) has no scope either, and for the opposite reason to Items: it does not
carry too little branch data to widen, it is **already as wide as it goes**. It is read only from
the business database and always covers all of it. Every other scoped screen asks about a
**branch** — what did this till sell, who signed in here, what is on this shelf — and `local` is an
honest default there because the branch's own rows are on the device. This asks about a **person**,
and a person is not a branch: somebody signs in at Manila on Monday and Cebu on Tuesday, so the
local mirror is not a smaller true answer but a **wrong** one — a calendar that renders perfectly
and says an employee posted elsewhere never came in all month. It is therefore the one read screen
with **no offline path at all**: offline it renders and says it cannot report, rather than falling
back (which is why `online: bool` replaces `consolidatedOnline: ?bool` there). Each shift instead
carries the **branch code** it was worked at, and the summary splits the hours by branch — the
per-branch answer the parameter would have given, on every row, without having to be asked for.
*Anchor:* `EmployeePerformanceController` (no `normalizeScope()`, no `OffersBranchScope`);
`AttendanceFigures::events()` for the `branches` join that only runs on `pgsql`.
✗ **Not** one permission. Reports restrict the wider scopes to the **Owner** (they carry cost
and profit); login history, stock history, sales, inventory stock and employee performance (which
has no scope to gate, only a route) allow **Admin+**, per
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
> [Report email] takes the same parameter with the same Owner-only widening, refused by the same
> `ReportsController::normalizeScope()` before a document is built — so an emailed report can
> only ever cover branches its sender was entitled to read. The **scheduled** send is `local`
> always: there is no actor to authorize a wider one and no screen to fall back on (§10).

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
The **upsert of this branch's `branch_status` row** at the end of a cycle — step 3 of the four,
counting from the gate at 0. One row per branch — current state, not history (`branch_id` is the
primary key).
✗ **Not** "step 4", which this entry said until the audit that added this line. It was step 4
when the cycle had five steps; M11.7 deleted the queue-processing step and renumbered everything
after it, and the cycle diagram above (`0. version gate → 1. push → 2. pull → 3. heartbeat`) is
the one to trust.
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
It exists for one case: a device **replacing** an existing branch's hardware, which would
otherwise restart at 1 against serials Postgres already has — and because the push is
insert-only, that collision is *absorbed* rather than raised, losing the sales behind a clean
sync and a zero backlog.
*Anchor:* `instance.transaction_number_floor`, `instance.return_number_floor`;
`App\Support\Sync\Hydrator::serialFloors()`.
✗ **Not** justified by "hydration copies no history", which is what this entry said until the
audit that added this line, and which stopped being true at M11.6 — the hydrate copies this
branch's `transactions` and `sale_returns`, so a rebuilt till's local max is **not** empty. The
floor survives that change because it is read from Postgres rather than derived from what came
down, so it is still right when a row is unpushed, filtered out, or lost. See
`TransactionNumberGenerator`'s docblock, which names the same correction.
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
*Anchor:* `config('nativephp.version')` (`NATIVEPHP_APP_VERSION`, default `1.1.0`);
recorded in `branch_status.app_version`. The default **is** the app version — the env key is set
nowhere in the repo, and `console/tests/Unit/SchemaVersionTest.php` reads that default out with a
regex to assert the floor never exceeds it.
✗ **Not** `1.0.0`, which this entry said until the audit that added this line. M11.7 raised the
build to 1.1.0 alongside the floor, and this anchor — inside the section that is normative on the
version pair — was the one place left declaring the old value.

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
✗ **Not one word for three things.** `issued_by` is the **user** who asked; `issued_by_branch_id`
is the **device's branch** they asked from (schema 22); the **issuing branch** is this entry's
actor concept. They come apart routinely — an Owner at head office can arrange a transfer between
two branches that are neither of them the one they are standing in, which is precisely why
`issued_by_branch_id` could not be derived from `branch_id` or `counterparty_branch_id` and had to
be stored. Prefer the explicit column name over the bare noun "issuer" when a specific one is
meant.
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
✓ **Enforced (M10).** The four words are a check constraint on both sides, not a convention —
console migration `2026_08_02_000020_add_connection_state_check_to_branch_status.php`. That
migration is also the first exercise of M9's stamp-and-re-grant machinery: additive, so it raised
`SCHEMA_VERSION` to 20 and left `MIN_APP_VERSION` at 1.0.0.
✗ **Not** "M10 should add one", which this entry said until the audit that added this line — M10
did add it, and the entry was never updated.

### CSV import — online-only
**Both** CSV imports are **online-only operations**, alongside catalog and user CRUD: the item
import (products, business-wide) and the opening-stock import (this branch's shelf, and only
inside the §"Opening window"). Each runs inside an `OnlineWrite`, so an offline one is refused by
the server rather than discouraged by the screen.
*Anchor:* `InventoryImportController::store()` and `OpeningStockController::store()` both re-probe
`ConnectivityProbe::online()`; `CsvInventoryImporter` and `OpeningStockImporter` both run inside
`OnlineWrite::run()`; both modals render `OfflineNotice` and disable the button.
**Why:** an import that reaches only this device diverges from the business permanently and
silently. For the item import that is catalog every branch sells from. For opening stock it is
sharper: those are `type = 'stock_adjustment'` ledger rows, which are online-authoritative and
**pulled down**, so the outbox does not carry them — written offline they would be the branch's
whole opening balance sitting in a local ledger, waiting to be reverted by the first pull's
baseline. Pushing them instead was rejected because it would give `stock_adjustment` rows a
second *direction of travel*, and the single-writer rule is about a class of row having one
authoring path.
✗ **Not** "`import_history` does not sync upward", which this entry said until the opening-stock
work and which had been false since M6.5. `BranchGrants::INSERT` includes `import_history`; a
successful import mirrors its row inside its own online write (`mirrorImport()`) and a failed one
afterwards as a rescued single-row write (`mirrorFailure()`). Both matter for a **rebuilt** device,
where hydration is the only recovery path.
✗ **Not** true any more that "an in-app import writes `stock_adjustment` ledger rows" without
qualification. The *item* import has written none since M13 — it describes products. The
opening-stock import writes them, and only while the branch has never sold anything.

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

### Open: four, all deferred rather than decided against

Unlike the entries above, none of these is a settled decision — they are work not yet done, and
they are recorded here because the previous version of this section said "nothing currently"
while `MULTIBRANCH-BRAINSTORM.md` §19 held two claims that had themselves gone stale. This list
is the one to trust.

- **The user-facing guide is pre-multi-branch.** `docs/Evitan-POS-Demo.pdf` predates every M
  phase: it documents the removed Database Backup page and the old Owner-creation setup flow, and
  says nothing about branches, sync, the queue or Pending Stocks. It is the only artifact in the
  repo that still describes Evitan POS as if it were the shipping product.
- **No E2E for the credential lifecycle.** `tests/e2e/` covers the wizard funnel
  (`setup.spec.ts`) but not **rotation** (fresh build + re-issued JSON) or **disable/enable**,
  both of which touch a device's ability to sync at all. The console side is unit-tested; the
  device side of those two is not exercised end to end.
- **There is no branch-closure command.** `branch:disable` suspends a credential on suspicion of
  a leak; a branch that has genuinely *closed* is a different event and has no verb. Deferred at
  M3 and never picked up — the console has provision / rotate / disable / enable / revoke / list /
  monitor / migrate and nothing that means "this shop is shut".
- **`sync_outbox` is never pruned.** `Outbox::markSynced()` sets `synced_at` and nothing ever
  deletes the row, so the table grows for the life of the install. Harmless at one shop's volume,
  unbounded in principle.

---

## 10. Report delivery

The sales report leaves the device by **email** and by nothing else. The PDF download
(`reports.export`) was removed outright when this was built; there is no fallback, and no other
export remains in the product — CSV export went at M13 and Database Backup at M11.6.

Everything in this section is a **device-local setting**, which is the load-bearing term the whole
design rests on and which had no entry before now.

### Device-local setting
A key/value row in the local `settings` table whose key is **not** in
`App\Support\Settings\BusinessSettings::KEYS`. Two enforcement points make that membership
meaningful rather than a naming convention: `Puller::scope()` narrows the settings *read* to those
six keys and `Puller::applySettings()` upserts by `key`, and `Hydrator::copySettings()` does both
again. A key outside the list is therefore invisible to a pull and to a re-hydrate.
*Anchor:* `App\Support\Printing\PrinterSettings::DEFAULTS` (Phase 11) and
`App\Support\Email\ReportEmailSettings::DEFAULTS`. Two tests assert the guarantee rather than
reasoning about it: `PullTest`'s
`test_settings_are_restricted_to_the_business_keys_and_leave_the_device_local_ones_alone` and
`HydrationTest::test_the_device_local_settings_survive_the_settings_copy`.
✗ **Not** a business setting. Those six are SHARED: an edit is an [online write], mirrored to
Postgres and pulled down by every other branch. These are this device's own, writable offline,
mirrored nowhere.
✗ **Not** a second table. Both live in `settings`, which is exactly why the two enforcement points
above exist — the local table is deliberately **wider by row** than the Postgres one.
✗ **Not** a candidate for tidying into `KEYS` later. Folding the printer keys in would retune
every branch's receipt printer from one device; folding the report-email keys in would do the same
to every branch's mail account, and would put an SMTP password into a schema every branch role can
`SELECT`. The password is sealed under the **per-install** `APP_KEY`, which does not travel
between installs, so a mirrored copy would be unopenable at the far end anyway.

### Report email
Emailing the sales report on screen, as a PDF attachment, to a list of recipients. Admin+ like
`/reports` itself, with the consolidated [branch scope] still Owner-only and still refused inside
the controller.
*Anchor:* `ReportsController::email()`, `App\Mail\SalesReportMail`,
`App\Support\Email\ReportMailer`.
✗ **Not** a download. `reports.export` is gone, route and action, and there is no secondary
button: a report that lands in the Downloads folder of a shop counter has not left the building.
✗ **Not** queued. `config/nativephp.php` starts no queue worker and nothing in the app is
`ShouldQueue`, so the send is **synchronous** inside the request, bounded by
`SmtpTransportConfig::TIMEOUT_SECONDS`.
✗ **Not** one message per recipient. One message carries them all on `To:` — they are the
business's own people and the document is identical, and N sends would only add a partial-success
state with nothing honest to show for it.
✗ **Not** allowed to fail loudly. A refused or failed send is an error toast plus a
`report.email_failed` audit row, never a 500 and never a lost page — the same rule the receipt
printer has had since Phase 11.

### Default recipients
The stored address list a send starts from. The dialog ticks them all on open and lets the sender
untick, remove, or add one **for that send only**.
*Anchor:* `ReportEmailSettings::recipients()`, key `report_email_recipients`, stored
newline-separated; `resources/js/components/reports/email-report-dialog.tsx`.
✗ **Not** users. `App\Models\User` has no email column — authentication is by username — and
nothing derives an address from an account.
✗ **Not** changed by sending. `ReportsController::email()` reads the recipients from the request
and never writes back; the list is the Owner's to manage in Business Settings.
✗ **Not** JSON. A blob that fails to parse has to fail somewhere, and the two available failures
are a 500 on the settings page or a silent degrade to "no recipients" that stops the scheduled
send forever. `explode()` is total, and `email:rfc` forbids a newline inside an address.

### Schedule
One automatic send: a **kind** (`daily | weekly | monthly | yearly`), whether it is switched on, a
**send time**, and — for daily only — the weekdays it fires on. Four of them, independent.
*Anchor:* `App\Support\Email\ReportSchedule`, built by `ReportEmailSettings::schedules()` from the
keys `report_email_<kind>_enabled`, `report_email_<kind>_time` and `report_email_daily_days`;
walked by `App\Console\Commands\SendScheduledSalesReport`.
✗ **Not** a "sending period" — the single `off | daily | weekly | monthly | yearly` value M14
shipped, which made the four mutually exclusive. Removed; a migration carries an installed
device's old value onto the matching toggle.
✗ **Not** the report's `period`, though a schedule's kind is spelled the same and resolves to it
(`ReportSchedule::period()`). One is *how often a send happens*, the other is *what window a
document covers*.
✗ **Not** a background service. NativePHP ticks `artisan schedule:run` once a minute **while the
app window is open** and not otherwise, which is why the command is marker-driven rather than
fired at an instant it could simply miss.
✗ **Not** cost-free by default. A scheduled report omits cost, COGS and profit unless the Owner
has ticked `report_email_include_cost` — one decision covering all four: Item Cost is Owner-only
to *view*, and an emailed report has no viewer whose role that could be true of. A report sent by
hand follows the sender's own gate, exactly as the download did.

### Send time
The time of day a schedule fires, on the quarter-hour: `00:00` … `23:45`.
*Anchor:* `ReportSchedule::times()` (the closed list), `ReportSchedule::lastDueAt()` (the
comparison), and the same list as a `Rule::in` in `ReportEmailSettingsRules`.
✗ **Not** any minute. The command ticks every fifteen minutes, so a time it cannot land on would
be a picker that lies. The grid is the tick's, not a display convention.
✗ **Not** a deadline. A device shut at the chosen time sends **late**, on the next launch —
`lastDueAt()` looks backward to the most recent firing instant that is not in the future, never
forward to the next one.
✗ **Not** readable by `bootstrap/app.php`. That closure runs on every artisan invocation,
including `migrate` on a device whose settings table does not exist yet, so `->dailyAt()` is not
available and every time-of-day decision lives inside the command.

### Last sent marker
The record of which window has already gone out for one kind: a bare `Y-m-d` window start, under a
key that names the kind — `report_email_last_weekly`.
*Anchor:* `ReportEmailSettings::lastSent()` / `markSent()`, keys `report_email_last_<kind>`,
deliberately outside `DEFAULTS` so no form can write one and no settings diff can mention one.
✗ **Not** a timestamp of the send. It names the *window*, which is what makes the question
answerable at any moment rather than only at one.
✗ **Not** one shared row. M14 had a single `report_email_last_period` holding `"<kind>:<date>"`,
because a weekly window's start can equal a daily window's start. One key per kind makes that
collision unrepresentable rather than merely handled.
✗ **Not** a backlog. Each kind's window is the period that finished before its most recent firing
instant, so a device that was off for three weeks sends **one** weekly report — last week's — not
three. A marker holds a position, not a queue; the missed windows are still on the device and any
of them can be emailed by hand.
> **Known consequence.** Every device with a schedule on sends its own branch-local report. One
> device per branch (§2 "Till") makes that one email per branch; the day that constraint ends it
> becomes N duplicates, and this is the entry to come back to.

### Sender credentials
The SMTP account the report is sent from: host, port, encryption, username, password, from-address
and from-name. The password is sealed with `Crypt::encryptString()` and never leaves the device
in the clear or otherwise.
*Anchor:* `ReportEmailSettings::password()` / `::seal()`, key `report_email_smtp_password`;
`App\Support\Email\SmtpTransportConfig` builds the runtime mailer from them.
✗ **Not** the [branch credential] (§7). Different secret, different scope, different lifecycle,
and the console knows nothing about this one. Collapsing the two into "the credential" is exactly
the failure §0 exists to prevent.
✗ **Not** something the frontend ever sees. `forDisplay()` omits the key entirely and reports only
`password_set`; a blank field on submit means "keep the stored one" and the request omits the key
so the ciphertext is never handled.
✗ **Not** something the audit log ever sees either. `forAudit()` substitutes `(set)`/`(unset)`
before the diff is taken, because `App\Models\AuditLog` is pushed upward and anything in
`old_values`/`new_values` reaches a schema every branch role can `SELECT`.
✗ **Not** guaranteed openable. Settings rows moved to an install with a different `APP_KEY`
decrypt to nothing; `password()` reports and returns null rather than throwing, so the Owner can
still open the page that fixes it.
✗ **Not** "encryption: none means plaintext". Symfony's ESMTP transport opportunistically
STARTTLSes when the server advertises it and there is no knob that forbids it, so the field says
*"the server may still upgrade it"* rather than promising a plain connection.

### Test email
A short message with no attachment and no figures, sent to the stored [default recipients] from
Business Settings, to prove the [sender credentials] work.
*Anchor:* `ReportEmailSettingsController::test()`, `App\Mail\TestReportEmailMail`; the analogue of
`PrinterSettingsController::test()`, and worded the same way when it refuses.
✗ **Not** a report. No PDF, no totals — a recipient who gets one should not have to wonder
whether it was.
✗ **Not** a substitute for `isConfigured()`. That answers whether a send can be *attempted*;
credentials are only ever proven by trying them, which is what this is for.
