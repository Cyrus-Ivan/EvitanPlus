# Evitan Plus — sync architecture, as built

> **Scope.** How a branch device and the business database actually behave in the code
> that exists today (M0–M13.5). `MULTIBRANCH-BRAINSTORM.md` is the spec of record and
> `MULTIBRANCH-ROADMAP.md` is the phase plan; where either describes an intention this
> document describes the implementation. On what a *word* means,
> [`glossary.md`](glossary.md) outranks both.
>
> Every claim below names the class or table that anchors it. If the two disagree, the
> code is right and this file is stale.

---

## 1. Three stores, and what each is for

| Store | Lives | Authority over |
|---|---|---|
| **Business schema** `biz_<32-hex>` | Supabase Postgres | Everything. One schema per business, holding every branch's rows, scoped by a `branch_id` column the local tables do not have. |
| **Local mirror** | SQLite on the branch device | Nothing permanently. It is the only store in the system that may be thrown away and re-filled — every row in it is either re-derivable from Postgres or already pushed there. |
| **Console schema** `evitan_console` | Same Postgres project | The provisioning audit trail and `credential_issuance`. Developer-only; the shipped app never reads it (`MULTIBRANCH-BRAINSTORM.md` §18). |

Every table is in exactly one of four classes, and the classification is **enforced**, not
documented: `console/tests/Feature/SchemaParityTest.php` fails when a table appears on one
side without being declared.

- **Mirrored** — exists on both sides with the same columns: `users`, `categories`,
  `items`, `variants`, `settings`, `transactions`, `transaction_items`,
  `inventory_ledger`, `stock_snapshots`, `sale_returns`, `sale_return_items`,
  `login_history`, `audit_logs`, `import_history`.
- **Postgres-only** — `branches` (the till knows which branch it *is*, never the roster),
  `schema_meta` (the version gate lives server-side), `inventory_update_queue`
  (coordination, not branch data), `branch_status` (heartbeats the console reads).
- **SQLite-only** — `instance`, `instance_secret`, `sync_outbox`, `sync_status`,
  `sync_cursor`. Each is about *this device* and has no counterpart to add.
- **Postgres-only columns** — just one: `branch_id`. That single column is why one schema
  can hold every branch while a till's SQLite holds only its own rows and has nothing to
  scope by.

Reading a Postgres-only table therefore has to be a live query, never a copy — see §7.

---

## 2. Becoming a branch: hydration

A packaged install with no `instance` row is funnelled to `/setup` by
`RequireInitialSetup`. The developer imports the **credentials JSON** the console emitted
for that branch, and `App\Support\Sync\ConnectAndHydrate` does the whole job in order:

```
parse → connect → version gate → hydrate → record identity → audit
```

**Nothing persists unless all of it succeeds.** The local writes — the hydrated baseline,
`instance`, `instance_secret`, the audit row — are one SQLite transaction with the
Postgres work inside it. So the remedy for every failure is identical ("fix what the
message says, submit again"), there is no half-imported state, and no screen has to exist
for one. It also means `instance.hydrated_at` cannot lie, which matters because that
column is the fresh-install signal read on every request.

`Hydrator` copies two groups. The **online-authoritative baseline** — the catalog, **all**
users business-wide, the settings, and this branch's stock snapshot — where copying down
cannot lose a local edit because the till never writes them offline. And, since **M11.6**,
**this branch's history**: `transactions`, `transaction_items`, `sale_returns`,
`sale_return_items`, `inventory_ledger`, `login_history`, `audit_logs` and
`import_history`.

The history half exists because hydration is now the **only** way to rebuild a device.
Until M11.6 the Owner could restore a file backup instead, so a hydrate that skipped
history left a gap somebody else could close; with that feature removed, three-quarters of
a device is not a recovery. A replacement till used to come up with the right catalog and
stock but an empty `/sales`, `/audit-logs` and `/login-history`, no branch-local
report figures, and no receipt to reprint.

Four things make the history copy correct, and each of them fails silently if got wrong:

- **It is scoped to this branch.** Directly by `branch_id` — detected per table through
  `RowMapper::isBranchScoped()` rather than listed — except `transaction_items` and
  `sale_return_items`, which Postgres deliberately does not scope (a line inherits its
  parent's branch) and which are filtered through a subquery on that parent
  (`Hydrator::PARENT_SCOPED`). The local schema has no `branch_id` column, so an unscoped
  copy would land another branch's trading here with nothing to mark it as anyone else's.
- **It is insert-only** (`Hydrator::INSERT_ONLY`, `insertOrIgnore`). Seven of the eight
  tables carry `BEFORE UPDATE` immutability triggers, and an upsert's `ON CONFLICT DO
  UPDATE` arm fires them. A first hydrate has no conflicts and would never notice; a
  **re-hydrate** would abort on the first row it had already copied.
- **The baseline half upserts on the row's real identity, which is not always `id`**
  (`Hydrator::CONFLICT_TARGET`). `stock_snapshots` is the one mirrored table whose uuid the
  two databases mint independently — upstream in `RemoteStock::recompute()`, locally in
  `StockLedger` and `StockReconciler` — so for any variant that reached the device through a
  **pull** rather than through the hydrate, the two sides' ids differ. Keyed on `id` the
  incoming row looks new, SQLite attempts an INSERT and dies on the `UNIQUE` index over
  `variant_id`. Keyed on `variant_id` it converges the row, id included, which is what
  `settings` (keyed on `key`) has always done for the same reason.
- **It makes `inventory:reconcile` a no-op instead of a wipe.** `LaunchProvisioner` runs
  that command on every launch, and it rebuilds snapshots from the *local* ledger — so
  before M11.6 a freshly hydrated device had snapshots with no ledger behind them and the
  first launch zeroed every variant. It reproduces them now because upstream a snapshot
  *is* its ledger sum by construction (`RemoteStock::recompute()`), which is why "write a
  ledger entry, not just a snapshot" binds anything that seeds opening stock.

`Hydrator::serialFloors()` is unchanged and still read: with `transactions` copied the
generators would find the branch's high-water mark locally too, but the floors on
`instance` cost nothing and keep them correct however the copy is scoped later.

Hydration writes through the **query builder, never models** — models would fire the
single-Owner guard on users arriving from the authority, re-hash already-hashed passwords,
regenerate UUIDs the business owns, hide the very `deleted_at` values being copied, and —
now that history is in reach — trip the `Immutable` trait and enqueue every pulled-back row
into the outbox to be pushed straight up again.

**Rebuilding a device that is already set up.** There is no in-app repair, re-hydrate or
reset, and deliberately so — once `instance.hydrated_at` is set, `RequireInitialSetup`
redirects `/setup` to login unconditionally, because a "re-sync from the business" button is
indistinguishable from a "lose whatever I did offline" button to the person pressing it. The
supported rebuild is to **delete the local SQLite database and relaunch**:
`LaunchProvisioner` runs the migrations, finds no `instance` row, and the wizard runs as it
does on a fresh install. That path needs the branch's credentials JSON again, and it does
not restore three things: the **printer configuration** (device-local by design — it has no
upstream row at all, and wizard step 2 re-collects it), the **report-email configuration**
(device-local for the same reason plus one more, since its SMTP password is sealed under the
per-install `APP_KEY`; the wizard does not collect it, so it is re-entered in Business
Settings) and any **unpushed outbox backlog**.

**What is still not recoverable.** Work a device did offline and never pushed dies with the
hardware: nothing else has a copy, and after M11.6 nothing in the product claims otherwise.
That window is bounded by how long the branch ran offline, and `sync_outbox`'s pending count
is the size of it — the header shows it on every screen.

`import_history` reaches Postgres by a path of its own. `ImportHistory` has no
`PushedUpward`, so nothing enqueues it: a **successful** import mirrors its row inside the
import's own online write (`CsvInventoryImporter::mirrorImport()`), and a **failed** one —
recorded outside that write, where `OnlineWrite::mirror()` has nothing to attach to — is
sent up afterwards as a separate best-effort one-row write
(`CsvInventoryImporter::mirrorFailure()`, rescued, because a failure to record a failure
must not become the user's error). Both are hydrated back down, so a rebuilt till shows the
branch's Upload History rather than a blank page.

---

## 3. The sync cycle

`App\Support\Sync\SyncCycle` is the only thing in the app that runs one:

```
0. version gate  →  1. push  →  2. pull  →  3. heartbeat
```

The order is load-bearing:

- **push before pull**, so the baseline the pull reads already reflects this branch's own
  movements and reconciliation has less to subtract;
- **heartbeat last**, so it describes the cycle that just finished rather than the one
  about to start.

There was a **queue step between push and pull** until schema 21. It answered this branch's
pending stock adjustments by arithmetic, accepting whatever fit. A person answers them now
(§6), which is an ordinary online write and needs no cycle — so the step was deleted rather
than moved. That deletion is why schema 21 is the **first breaking change**: a build still
running the old step 2 would walk every row waiting for a human and auto-approve it.

### Nothing here may stop a sale

`SyncCycle::run()` has **no failure path out**. Every outcome — a revoked credential, a
schema that refuses this build, a dropped connection — becomes a `sync_status` row and a
returned array. The till never learns a cycle failed except by looking.

### Four outcomes, not two

`offline`, `unauthorized`, `version_blocked` and a `conflict` (reported as `online`) are
indistinguishable at the counter and need three different human responses — wait, call the
developer, install a newer build. `SyncCycle::classify()` decides once, for two readers:
the local `sync_status` row and the heartbeat. A failure the till calls `unauthorized` and
the console calls `offline` is worse than either answer alone.

`isUnauthorized()` walks the exception chain for the console's fingerprints (`password
authentication failed`, `is not permitted to log in`, `role "…" does not exist`,
`SQLSTATE[28…]`). `isConflict()` decides by **SQLSTATE class** — `23xxx` (integrity: a
duplicate serial, a negative-stock check) and `42xxx` (access rule, which is what a
missing GRANT looks like: 42501). Both mean the branch is *online and stuck*, and saying
"offline" there would tell the shop to wait for a connection it already has.

### The connection is owned by the cycle

`BranchCycle` is the one place the shipped app opens Postgres, and it disconnects in a
`finally`. Branch devices connect through Supabase's **transaction pooler (6543)**, where
every connected *client* holds a Supavisor slot whether or not a query is running, and
slots are capped per tenant in the low hundreds. A till that connected at boot and held it
all day would re-impose exactly the ceiling the port choice removes. Occupancy therefore
tracks branches *syncing right now*, not branches that exist.

Consequences: **no session state may survive a cycle** (no `SET`, no `LISTEN`, no temp
tables, no advisory locks, no transaction spanning steps) — the branch role's
`search_path` is pinned server-side with `ALTER ROLE`, which Postgres applies at backend
startup and so reaches every pooled backend. And the credentials JSON block is **merged
into** `config('database.connections.pgsql')`, never assigned over it, or four keys
silently vanish (`tests/Feature/BranchConnectionDefaultsTest.php` pins the subset).

### Reachability is answered over HTTPS

`ConnectivityProbe` must never open Postgres, for the slot reason above: a `select 1` on a
timer from every offline till would occupy one slot each, permanently, worst on the tills
least able to sync. It asks the project's REST endpoint instead, and **any HTTP response
is a yes** — `/rest/v1/` without an apikey answers 401, which proves something on the far
end spoke HTTP. Only a transport failure is offline. The project ref comes from the login
name, which Supavisor requires to be `<role>.<project-ref>`.

### When a cycle runs

Four triggers, in `resources/js/hooks/use-sync.ts`:

1. **on mount**, if due — an app launched while already online never receives an `online`
   event;
2. **the outbox listener** — rides the shared `sync` prop, *not* throttled, keyed on the
   unsynced count at module scope so a backlog that cannot clear costs one retry per new
   write rather than one per page opened;
3. **reconnect** (`window online`), not throttled;
4. **a 5-minute backstop timer**, throttled.

The throttled triggers share one due-check, which is what makes a till someone is clicking
through sync about every five minutes rather than once per click. Since M7 the timer's
real job is the **pull**: nothing local signals that another branch changed a price.

### What a finished cycle refreshes on screen

All four triggers above refresh the **indicator's shared `sync` prop and nothing else**. The
**Sync now** button additionally re-runs the current page's controller — `run(true)`, a full
`router.reload()`. The asymmetry is the point: a person who pressed a button expects the
screen to change, and a cashier three items into a sale does not, so an automatic cycle never
re-sorts a POS grid or shifts rows between pages of a table under someone's hands. Nothing is
*lost* either way — Inertia's `reload()` forces `preserveState`/`preserveScroll` after the
caller's options, so the page never remounts and carts, half-typed forms and on-screen
validation errors all survive.

The button needs the wider refresh because a **pull cannot substitute for it**. The
consolidated `scope` views (§8) are online reads
performed *inside* their controller, and `login_history` is not in the puller at all — so
another branch's rows reach the screen when the controller runs again, and by no other
mechanism. The refresh is therefore not conditional on the cycle having pushed or pulled
anything: the counts `SyncController` returns would read `pulled: 0` in exactly that case.

---

## 4. Going up: outbox and push

Every local-write row is enqueued in `sync_outbox` on create. An entry is a **pointer**,
not a payload — a table name and a row id. Every table it can name is immutable, so the
row is re-read at push time and cannot have changed; a stored copy would be strictly worse
than none, being a second representation of one sale able only to drift. The unsynced
count is `count(*) where synced_at is null` and nothing else — there is deliberately no
counter column anywhere.

The outbox is **not** the inventory update queue. Two unrelated structures moving in
opposite directions; reserve the bare word "queue" for the other one.

`Pusher` is insert-only and UUID-keyed, therefore idempotent — which is what lets a cycle
fail anywhere without knowing how far it got. Its INSERT is hand-written because both of
Laravel's helpers are wrong in opposite directions: `upsert()` compiles to `on conflict …
do update`, which the BEFORE UPDATE immutability triggers would raise on; `insertOrIgnore()`
compiles to a bare `on conflict do nothing` with **no conflict target**, which would
swallow a collision on `(branch_id, transaction_number)` and silently discard a device's
sales with a green indicator and a zero count. So: `on conflict ("id") do nothing`, naming
the id and only the id.

After a batch lands, `RemoteStock` recomputes Postgres's `stock_snapshots` **from
Postgres's own ledger** — recomputed, never copied, so a repeat converges instead of
double-counting. Postgres has no trigger behind that table; the till maintains it. Which
makes one rule binding on anything that puts opening stock into a schema: **write a ledger
entry, not just a snapshot**, or the recompute sums only what is there, goes negative, and
`stock_snapshots_non_negative` refuses the whole cycle.

---

## 5. Coming down: pull and reconciliation

`Puller` reads deltas of the catalog, users, settings and this branch's `stock_adjustment`
ledger rows. **A pull is not a hydrate**: the hydrate runs once and copies everything, this
runs every few minutes for the life of the install.

`settings` is the one table where the local mirror is deliberately **wider by row** than the
Postgres one: it also holds this device's printer configuration and, since M14, its
report-email configuration, neither of which has an upstream row. Both halves of the
carve-out matter — `Puller::scope()` narrows the *read* to `BusinessSettings::KEYS` and
`applySettings()` upserts by `key` rather than `id` — and `Hydrator::copySettings()` does
the same again. That membership is the whole definition of a **device-local setting**
(`docs/glossary.md` §10); adding a key to `KEYS` is what would retune every branch's printer,
and every branch's mail account, from whichever one saved last.

`sync_cursor` holds one watermark **per table** — per table, so a failure on `variants`
cannot discard the progress just made on `users`, and each table's rows and its cursor move
in the same local transaction. Two properties of that bookmark are load-bearing and neither
is obvious:

- **The comparison is `>=`, not `>`.** Two rows edited in the same instant carry the same
  timestamp; with `>` the second lands *behind* the bookmark the first set and is never
  read again — no error, no gap anything could notice. Re-reading the boundary row each
  cycle is absorbed by the upserts for free.
- **The bookmark is the newest row actually read, never `now()`.** A Postgres row's
  `updated_at` is stamped when its transaction *begins* but becomes visible when it
  *commits*. An edit starting at :00 and committing at :09 carries :00; a cycle at :05
  that bookmarked :05 would leave that row permanently behind the mark — a stale price
  with a green sync indicator, forever.

**Deletions need no handling because they do not happen.** Categories, items and variants
archive; users soft-delete. Both are UPDATEs, so both arrive as ordinary deltas. The
never-delete invariant is precisely what makes a watermark pull sufficient.

Like the hydrator, the puller uses the query builder — for the same four reasons plus one
of its own: an Eloquent write fires the `PushedUpward` trait's `created` hook
(`app/Models/Concerns/PushedUpward.php` → `Outbox::enqueue`), which would enqueue every
pulled row into the outbox and push the business's own data straight back at it.

### Stock is reconciled, never overwritten

```
reconciled = baseline + Σ(signed unsynced local ledger movements)
```

Three deliberately different numbers: the **baseline** is what Postgres hands back
(reflecting online entries and already-pushed movements, *not* this till's unsynced
sales); the **snapshot** is the `stock_snapshots` row updated on every local ledger
insert; **reconciled stock** is the sum above and the figure the till sells against.

It is an addition of *signed* quantities because the local ledger is signed — a sale
negative, a return positive, a defect zero. Subtracting absolute values would get returns
exactly backwards.

This is **not conflict resolution**. Both sides only ever INSERT into the ledger, so the
contributions never contest one another; they add. There is no last-write-wins anywhere in
this system, and `StockReconciler` is the one place someone would be tempted to introduce
it — overwriting the local figure with the baseline would discard the morning's takings on
a branch that has been offline all morning. It is self-healing: once the unsynced sales
push, the next baseline contains them and the correction converges to zero on its own.

---

## 6. Pending stock adjustments

Every stock movement is now asked for before it happens, with **one** exception named in §6.2. A
recount typed at this till, an adjustment aimed at another branch, a stock batch, and both legs
of a transfer all write a `pending` row into `inventory_update_queue`
(`App\Support\Inventory\AdjustmentQueue`) and stop. (A CSV import did too, until M13 took stock
out of the catalog file entirely — see §6.2.)
It becomes a ledger entry when a person at the affected branch says it really happened
(`App\Support\Inventory\AdjustmentResolver`), from `/inventory/stock-updates`.

M8 mediated only the cross-branch case, on the grounds that the issuer cannot see the target's
unsynced sales. True, and not the point: arithmetic was never what was in doubt. Stock that
moved on paper and stock that moved on a shelf are different things, and only somebody standing
in the shop can tell them apart. Three answers, and only the first two move stock: **approve**,
**partial approve** (`approved_quantity`, plus a reason), **reject** (a reason).

### A rejection writes a ledger row of ZERO (M11.8)

It used to write nothing, on the reading that the ledger records movement and a refusal is the
absence of one. Correct about the ledger and wrong about the business: the branch that **asked**
never found out. `Puller` filters `inventory_ledger` to one branch, so even a row written at the
target could not travel to them.

So a refusal is a movement of nothing, carrying the queue row's own id, the counterparty code and
a sentence saying what was asked for and that it did not happen. **Branch-scoped Stock History**
(§9) is what makes it reachable from the branch that asked. Ledger-derived stock is untouched —
zero sums to zero in `StockReconciler`, `RemoteStock::recompute()` and `StockLedger::reconcile()`
alike — and zero-quantity rows are not new: defect audit entries have written them since Phase 11.

### The page shows unfinished work only

`/inventory/stock-updates` ("Pending Stocks") groups rows into **requests** by
`batch_id ?? parent_id ?? id` — a whole stock batch, else a transfer's two legs, else one row on
its own — and shows a request while **any** leg is still pending. An answered adjustment leaves; a transfer stays, with the sender's answer on the card,
until the receiver answers too; a transfer its sender refused leaves, because refusing it spawns
no receiving leg. Where the record goes afterwards is §9.

`issued_by_branch_id` (schema 22) says where the person who asked was standing. It cannot be
derived: `IssueTransferRequest` takes both branch ids as free choices, so an Owner at head office
can arrange a move between two branches that are neither of them theirs.

### 6.1 A stock batch is one card (M13, schema 23)

Twelve products moving between two branches was twelve trips through the transfer dialog, twelve
unrelated pending rows, and twelve copies of one note for the receiving branch to read. That is
not what happened: one delivery left one shop. `batch_id` (nullable, no FK, on both
`inventory_update_queue` and `inventory_ledger`) is the name of that one act.

`App\Http\Controllers\Inventory\StockBatchController` mints it and writes one row per line, all sharing
one note. A transfer batch writes only the **sending** legs, as one always did; each receiving leg
is spawned by `AdjustmentResolver::secondLeg()` when the sender confirms, and inherits the batch
so the receiving branch also sees one card. `AdjustmentResolver::apply()` carries it onto the
ledger row, which is why Stock History can say a movement was part of a delivery on a till with no
connection — the same reason `counterparty_branch_code` is stored as a code.

Because the grouping key made a card span many products, the product moved from the request onto
the **leg**. A card names its branches and its note once.

**Additive.** Both columns are nullable and nothing older reads them, so `MIN_APP_VERSION` stayed
at 1.1.0: a 1.1.0 build against a 23 schema writes nulls, its cards do not group, and a transfer
batch it approves does not carry the batch onto the receiving legs. Degraded, not wrong. No grant
change either — INSERT and SELECT on both tables are table-level, and nothing ever UPDATEs
`batch_id`, so `UPDATE_COLUMNS` stays at six.

**The batch has a second name (schema 24).** `batch_id` groups a card, but a uuid is not something
anybody reads off a screen or repeats down a phone, and until schema 24 nothing anywhere printed
the batch at all. `batch_reference` — `MNL-B-000001`, the issuing branch's code and a running
serial — is the half a person uses; see §"Batch reference" in `docs/glossary.md`, which is
normative. `App\Support\Inventory\StockBatch` holds both names and mints the reference **inside the
online write's own transaction**, because the serial is read from `inventory_update_queue` — which
is Postgres-only, and a batch can only be issued online anyway. A `pg_advisory_xact_lock` on the
prefix serializes minting per branch, so two tills at one branch cannot be handed one name; it is
skipped on anything but Postgres, which means the SQLite stand-in the offline suite runs against.
From there it travels exactly as `batch_id` does — onto the receiving legs, onto the ledger — and
it is on the ledger for the same reason: the Pending Stocks card, the Stock History entry dialog
and that page's `?batch=` filter all print or read it, and the last two must work with no
connection. Additive on the same terms as above; `MIN_APP_VERSION` stayed at 1.1.0 and
`UPDATE_COLUMNS` stayed at six.

**Answering a batch needs no new path.** "Approve all" posts the card's ids to the same
`stock-updates.resolve` route one Approve uses, and the resolver re-reads each of them filtered to
this branch — which is the only thing stopping one branch writing another's stock, and not
something a bulk shortcut may skip.

### The resolver reads before it writes, and the filter is not defensive

`OnlineWrite` performs its local writes **before** any connection exists and mirrors them after,
stamping `branch_id` from `instance`. So the resolver re-reads every row from Postgres filtered
to `branch_id = <this branch> and status = 'pending'` before writing anything. Without that, a
device could answer a row aimed elsewhere and write **that branch's stock movement onto its own
ledger** — the stock would land in the wrong shop, and both branches' figures would be wrong in
opposite directions. The old step 2 could not make this mistake because the cycle handed it the
branch id it was already running as; a controller taking ids from a form can.

### Approving writes locally AND upstream

Unlike the old step 2, which wrote upstream only and let the pull bring the row down. A person
pressing Approve is not inside a cycle, and waiting up to five minutes to see the stock they just
agreed to would make the button look broken. So it is an ordinary online write — local ledger row
and snapshot first, mirrored up in the same act.

That does not reintroduce a second writer: the rule is about a class of row having one authoring
path, not one direction of travel, and `AdjustmentResolver` is the writer of
`type = 'stock_adjustment'` for every movement on a shelf that has a history (there were three
before schema 21; §6.2 is the one deliberate second door). Nothing double-counts either — the outbox stays
empty because `shouldPush()` is false for adjustments, `Puller` `insertOrIgnore`s the row it
re-reads, and `RemoteStock` recomputes with a `sum()` that converges.

The entry's id **is** the queue row's id, so two people pressing Approve at once collide on the
primary key and the loser becomes a no-op.

### The two-term formula is gone

Step 2 needed `upstream baseline + Σ unsynced local` because it ran before this branch's own
writes were guaranteed up. The approver *is* the branch, reading its own ledger, which already
contains its unsynced sales — the lower of the two figures. `StockLedger::record()`'s ordinary
local check is therefore at least as strict, and Postgres's `stock_snapshots_non_negative`
cannot fire.

### A transfer is two of these

The sending branch's row is negative; confirming it spawns the receiving branch's row
(`parent_id`) for **what was actually sent**. Either end may approve part of it. A shortfall on
arrival is **written off** — sender −3, receiver +2, the business one unit down, because that
unit no longer exists. Nothing bounces back.

### Two Postgres details that are easy to get wrong, both load-bearing

- **A resolution must not touch `updated_at`.** The branch role holds column-level UPDATE on
  exactly **six** columns (`BranchGrants::UPDATE_COLUMNS`); a seventh in the SET list is
  `permission denied`, which reads as a missing grant and is in fact the matrix being exactly
  right.
- **Timestamps come from the server's clock** (`CURRENT_TIMESTAMP`), never PHP's. These are
  `timestamptz` and this app's convention is a naive local string; stamping one with the other is
  the eight-hour drift M6 spent a phase finding.

### 6.2 Opening stock is the one movement nobody confirms

A device off the setup wizard has every variant at **zero**. For a new business because
`business:provision` seeds no catalog at all; for a new branch because `stock_snapshots` hydrates
scoped to a branch that has never traded, and upstream a snapshot is only written the first time
stock moves. Since M13 took stock out of the item CSV there was no bulk way off zero: six hundred
SKUs meant six hundred dialog lines, through a batch capped at a hundred.

`App\Support\Inventory\OpeningStockImporter` is that path, and it writes the ledger **directly**
— the only thing besides `AdjustmentResolver` that writes `type = 'stock_adjustment'`. What
bounds it is the **opening window** (`App\Support\Inventory\OpeningWindow`): no `type = 'sale'`
row exists for this branch. One sale, return or defect and the route 403s.

The argument is narrow on purpose. The queue exists so a second voice can check a claim against a
shelf that has a past; a branch that has never sold anything has no past for the claim to
contradict, the person uploading is the person standing at the branch, and asking them to confirm
a file they typed a moment earlier is the ceremony without the second voice. The window is what
keeps that from becoming a standing route around schema 21.

Four consequences worth knowing:

- **A quantity is the resulting on-hand**, written as `quantity − current stock` — the `set`
  semantics of §6.1, legal here because the figure being subtracted from is this device's own
  reconciled one. So the file is **re-uploadable**: run it twice and every delta is zero. A
  mistyped count is fixed by fixing the cell and uploading again.
- **A line that moves nothing writes nothing**, including a zero on a variant at zero. That is §4's
  rule, and it is the rule the M7 rig defect proved: a snapshot with no ledger behind it makes
  `RemoteStock::recompute()` sum an incomplete ledger, go negative, and
  `stock_snapshots_non_negative` refuse every cycle the branch ever runs again. The import writes
  through `StockLedger` and recomputes from Postgres's own ledger for exactly that reason.
- **It cannot create products.** Every SKU must already exist, which is what makes the new-branch
  case "the stocks of the current catalog." A new business runs the item CSV first; a new branch
  hydrated the catalog and runs only this one.
- **Nothing here needed a schema, grant or console change.** `reference_type = 'csv_import'` is
  unconstrained and has been a live movement kind since Phase 7 — nothing wrote it between M13 and
  this, but every screen and every build back to v1.0.0 still renders it. `inventory_ledger` has
  had INSERT since M2 and `stock_snapshots` INSERT/UPDATE since M6. The version pair does not move.

**The request has to name the branch it means.** `/inventory` carries a branch scope that changes
only the Stock column — the product list, the filters and the paging stay local in every scope —
so under `?scope=<other branch>` the screen is this device's catalog wearing another branch's
numbers, under that branch's heading. An import fired from there cannot write that branch and
every layer beneath would hide it: the delta is computed against this device's shelf,
`OnlineWrite` stamps `branch_id` from `instance` whatever the URL said, and `OpeningWindow` reads
the local ledger and so answers only for this branch. The failure is not an error but six hundred
rows on the wrong branch under a toast saying they succeeded.

So both posts carry a `scope`, and `OpeningStockImportRequest` refuses anything but `local` or
this branch's own id — **including its absence**, because omission is the one bypass a stated
intent must not have. It is the same shape as `confirm_updates` on the item import: the caller
states what it believes, the server checks the belief, and the modal is not where the rule lives.
The window is checked first, so a branch that has traded is told the true reason rather than sent
to fix a scope that was fine.

Proved in `tests/Feature/Inventory/OpeningStockImportTest.php` (the window, the arithmetic, the
refusals) and `tests/Integration/OpeningStockImportTest.php` (the round trip against a real
provisioned business, ending in a full `SyncCycle::run()` — the assertion that matters is not that
the numbers are right but that the branch can **still sync** afterwards).

---

## 7. Online write, online read

Two things that are not sync at all.

**`OnlineWrite`** (M6.5) — a change to shared data (catalog, staff, settings, CSV import)
written to Postgres *and* the local mirror as one act, or refused. Queueing one would give
those tables two writers and put the business one lost device away from a price only one
till knows about. The local SQLite transaction is the **outer** one and the local write
happens **first**, so ids, casts, guards and audit settle before the network is involved:

```
DB::transaction                    <- outer, local
  local writes (normal Eloquent)
  BranchCycle::run                 <- opens Postgres, releases it after
    version gate
    mirror the same rows upward
```

Any failure at either end rolls the local transaction back. The one residual window is a
local COMMIT failing after Postgres accepted — which is self-healing, because the local
mirror is the store that may be thrown away.

**`OnlineRead`** (M8) — a live query that mirrors nothing: it asks Postgres a question,
renders the answer, keeps none of it. Two of the tables it serves are Postgres-only by
decision (§1): `branches` and `inventory_update_queue` (including the read the resolver does
before it writes). From M11 onwards it also serves the wider **branch scope** on screens whose
tables *are* mirrored — reports, sales, login history, stock history and inventory stock — since
a mirror holds only this branch's rows and a consolidated view needs everyone's. See §8. Same credential, same probe, same `BranchCycle`
owning the connection — **but no version gate**, and that is the one deliberate difference:
the gate exists so a build below the floor cannot *write* stale data. Refusing to render a
read-only screen would take away the display of the very problem.

**Employee performance** (`/employee-performance`) is the one screen served by `OnlineRead`
*alone*. It has no `scope` and no local path: the report is about a **person**, who can sign in
at one branch on Monday and another on Tuesday, so the mirror's answer is not narrower but
wrong — a month of zeros for somebody posted elsewhere, rendered as confidently as a real one.
Offline it renders and says it cannot report (`online: false`), rather than falling back the way
every scoped screen does. `EmployeePerformanceBuilder` takes the connection as an argument like
every other figure class, and every employee on a multi-employee email is built inside **one**
closure, since `BranchCycle` holds a Supavisor client slot for its length. The branch code on
each shift comes from a LEFT JOIN onto `branches` inside `AttendanceFigures::events()` that runs
only on `pgsql` — the local `login_history` has no `branch_id` and deliberately never will.

`BranchRoster` is the one query behind every screen that names another branch, and it
includes **disabled** branches by default: a disabled credential stops a branch receiving
work, it does not unmake what that branch already did. The opposite case — picking a branch to
send an adjustment *to* — needs the disabled ones gone, which is why `InventoryQueueController`
keeps a private `roster()` of its own alongside its `BranchRoster` use: one list to choose a
target from, one to put a name on a card.

The roster is read on **every full render** of a scope-picker screen for a viewer who
may widen the scope — not only when a wider scope was asked for. It used to ride inside the
consolidated read, which is cheaper but left the picker empty in the default `local` scope, so
naming one branch meant first asking for all of them. `App\Http\Controllers\Concerns\OffersBranchScope`
holds that read, reuses the consolidated one when there was one, and strips **this** branch from
the list (§"Branch scope" in the glossary). It costs one short online read per visit to any of
them, and it is rescued to an empty list: a picker that could not be filled never fails a screen
whose default scope is served entirely from local SQLite. `branches` is deliberately absent from
every one of those pages' partial-reload prop lists, or each keystroke in a search box and each
step of a date filter would re-read it over the network.

---

## 8. Consolidated reporting

`/reports` takes a **`scope`**: `local` (SQLite, offline, the default and the only value an
Admin may use), `all`, or a branch id. The recompute lives in
`App\Support\Reports\SalesFigures`, which takes the **connection as an argument** so there
is exactly one copy of `net = sales − returns − defects` whichever database answers.
Consolidated scope is **Owner-only** and an online read, refused *inside* the controller
rather than by route placement, since the same route still serves the branch-local report
an Admin is entitled to.

Consolidated figures **cannot** come from the mirror, because they are not in it:
`Puller::TABLES` deliberately does not carry `transactions` or `sale_returns`, so a branch
holds no other branch's sales and never will. Cross-branch totals exist only upstream —
which is why this is a read and not a pull.

Three things do not port between the two databases, and all three fail **silently**:

- **Bucketing** — `strftime` vs `to_char`; the format is inlined from a constant map and
  the timezone is *bound*, because it is an Owner-editable setting.
- **Window bounds** — a Postgres bound must carry its **offset** (a naive bound reads as
  the session's UTC, eight hours out). The filter stays on the raw column so the
  `(branch_id, created_at)` indexes still apply.

  *(This bullet also claimed the bound must carry its **microseconds** — that `endOfDay()`
  is `.999999`, that `timestamptz` keeps them and that `toIso8601String()` truncates. It is
  **false for this schema**: `timestampTz` takes Laravel's default precision of 0, so every
  timestamp column is `timestamp(0) with time zone` and a `.999999` value is unrepresentable
  rather than merely untested. The offset half above is real, and is what
  `tests/Integration/ConsolidatedLoginHistoryTest.php` proves.)*
- **`numeric` SUMs** arrive as exact strings, not floats.

A report row is a time bucket **or a branch** depending on one GROUP BY, so the per-branch
table is the same seven aggregates and foots to the totals by construction. A failed read
**falls back to the branch-local report and says so** — presenting one branch under an "All
branches" heading is the one outcome worse than an error.

Cost, COGS and profit stay Owner-only *here too*: the `$withCost` flag does not hide them,
it means the cost queries are never run and the keys are absent from the payload.

### The other five screens that take a scope

Same parameter, same fallback, same trait — different databases underneath, and each one has a
trap of its own worth naming.

**Login history** (M11.5) and **Stock History** / **Pending Stocks** (M11.8) read
`login_history`, `inventory_ledger` and `inventory_update_queue`. Nothing new; they are the model
the rest copy.

**Sales** (M12, `SalesController`) reads `transactions`, `transaction_items`, `sale_returns` and
`sale_return_items`, and a remote row opens a **read-only** detail — no reprint, no return, because
neither the receipt nor the stock is this branch's to act on. Three things bite here that the
reports path does not:

- **`like` is case-sensitive in Postgres and not in SQLite.** The `LikeQuery` macros register on
  the *Eloquent* builder and are unavailable on the query builder anyway, so the remote search
  inlines `ilike` with the shared `LikeQuery::escape()`. Get this wrong and a lower-case receipt
  search matches locally and returns an empty screen upstream, with no error.
- **`withCount` does not exist on a query builder.** The line count is a second `whereIn` inside
  the same online read — cheaper than a joined subquery, which `paginate()`'s own `count(*)` would
  drag along a second time.
- **`created_at` is `timestamptz` precision 0.** Ordering needs an `id` tiebreak or a page
  boundary inside one second repeats or drops rows. Not `transaction_number`: it is unique per
  branch, not per business.

And the one that is not a dialect difference: **`cost` is guarded by the SELECT column list and
nothing else.** `HidesCostFromNonOwners` is a model serialization hook and never runs on a
query-builder row, so for a non-Owner the key must be *absent*, matching the local path.

**Inventory stock** (M12, `Inventory\InventoryController` — it was `Inventory\ItemController`
until M13 split the catalog off) is the odd one out twice over. First, the
scope changes **stock and nothing else**. The item and variant list is read locally in every scope
— the catalog is mirrored business-wide, so the local rows *are* the upstream rows — and the
online read is one `whereIn` over `stock_snapshots` for the variant ids already on the page. That
makes the fallback trivial (attach nothing, and the page is byte-identical to its offline render)
and means a failed read has no half-consolidated state to design a screen for.

Second, it is the one screen with **no `all`**: the parameter is `local` or a branch id, and
nothing wider. Everywhere else a wider scope adds rows to a list; here it would have to add them
to a *number*, and a business-wide total per variant says nothing on its own — twenty units is
twenty spread evenly or twenty at one branch and none at the other four. It shipped in M12 with a
per-branch breakdown printed under every figure to make it readable, and that much meaning in one
table cell was the thing that had to go, not the thing that fixed it. `normalizeScope()` snaps
`?scope=all` back to `local` so the option cannot return by URL; the shared picker takes
`allowAll={false}`; the other five screens are untouched.

The rule that survives all of it: the scoped figure lands on `scoped_stock`, **never** on
`current_stock`: that is a get-only cached accessor over the local snapshot, so a write to it is
silently discarded and the page renders this device's numbers under another branch's heading. A
variant the scoped branch holds no row for reads zero — upstream a snapshot is written the first
time stock moves, so no row means none has. What is on screen is that branch's *baseline*
(glossary §"Stock baseline"), which excludes its unsynced sales and therefore reads high; the page
says so.

---

## 9. Versions, and the gate

Three quantities; the gate compares exactly one pair.

| Quantity | Kind | Where |
|---|---|---|
| App version | semver | `config('nativephp.version')` |
| Schema version | integer | `schema_meta.schema_version` |
| Min app version | semver | `schema_meta.min_app_version` |

```
the gate is:  APP VERSION >= MIN APP VERSION,  and nothing else.
```

The schema version is **recorded by both sides and never compared** — it exists so a human
can tell which migration state a schema is in. Confusing it for the gate is the easiest way
to build this wrong.

Failing the gate refuses sync in **both** directions: a stale push writes old-format rows
into a migrated Postgres, a new-format pull writes columns the local SQLite has not got.
Both are corruption. The till keeps selling throughout.

The pair is raised by the console, in one place (`Provisioning\Support\SchemaVersion`), and
`schema:migrate` **stamps** it after every clean run — only when it changed, and never
backwards. The discipline is **half-enforced on purpose**: `SCHEMA_VERSION` *is* the
migration-file count and a test asserts it, and the floor may never exceed the app version
the build carries — but whether a migration is *breaking*, and so whether
`MIN_APP_VERSION` must rise, is not decidable by any check and stays a human call. The
enforceable half exists largely to force that question at the moment it arises.

### Schema 21 is the first raise, and what it cost

`MIN_APP_VERSION` sat at 1.0.0 from M1 to M11.6. Schema 21 moved it to **1.1.0**, and the
reason is not the column rename it also carries. Before 21, a till answered its own pending
stock adjustments automatically in step 2 of its cycle. A 1.0.0 build let loose against a 21
schema still runs that step: it would walk every row waiting for a human and **auto-approve
the ones that fit** — approving every adjustment and every CSV import somebody was meant to
confirm, and accepting a transfer's sending leg without ever spawning the receiving leg, so
stock leaves one branch and arrives nowhere. Nothing detects it, because from the schema's
side it looks exactly like a branch doing its job.

**Deployment order is migrate-then-install**, which is counter-intuitive and correct.
Stamping the floor first sends every old till to `version_blocked` at step 0, so none of them
reaches its step 2 during the rollout window. They keep selling offline throughout. The other
order means new builds writing `resolution_reason` into a schema that has not got it.

This section is *why*; [`releasing.md`](releasing.md) is *how* — the order of operations for
shipping a version, including which of these three quantities you move and what that costs.

---

## 10. Monitoring

`Heartbeat` upserts one row per branch into `branch_status` as the cycle's last step — inside its
own connection, never a connection of its own, and on **both** ways out: after the pull on
success, and from a `catch` before the exception is re-thrown. A branch frozen by the
version gate is exactly the branch someone is looking for after a migration, and it is
invisible to the console unless it says so itself. The beat **swallows its own failures**:
a report about a cycle may never become that cycle's outcome.

Two timestamps that are not the same fact: **`last_seen_at`** is "this branch reached
Postgres", written on every beat; **`last_sync_at`** is "a cycle got all the way through".
A failed beat leaves the previous value standing, which is what makes "online but stuck
since Tuesday" readable at all.

Only **two** of the four `connection_state` words can ever be written from here — `online`
and `version_blocked`. `offline` and `unauthorized` are diagnosed by the connection failing
to open, and a branch that cannot connect cannot file a report saying so. So the monitor's
primary signal is the **age of `last_seen_at`**; the state word is secondary. The console
side is `php console/artisan monitor`, which LEFT JOINs the roster onto the heartbeats so a
branch that has never reported prints `never`, and prints the credential state of any
branch that is not `active` — a credential the console disabled and a dead router produce
identically silent tills.

---

## 11. The boundary: grants, and the risk that is accepted

`console/app/Support/BranchGrants.php` is the branch role's privilege matrix as **pure
data**, so the tests can assert the live grants in Postgres equal it exactly. Its shape:

- **SELECT on everything** (minus Laravel's `migrations`) — the till mirrors the catalog,
  all users, settings and its own history, and reads `schema_meta` on every cycle;
- **INSERT** on what a branch originates (its history), the derived-state tables it
  upserts (`branch_status`, `stock_snapshots`), the online-authoritative tables (M6.5) and
  `inventory_update_queue` (M8);
- **UPDATE** on the derived and online-authoritative tables, plus **six** named columns of
  the queue (widened from four at schema 21 for `approved_quantity` and `resolved_by`, because
  a person rather than a cycle now answers these and can agree to part of what was asked);
- **DELETE on nothing, ever.**

Grants are **enumerated per table**, and `ALTER DEFAULT PRIVILEGES` is deliberately not
used: both would silently extend a branch's reach to any table a future migration adds. The
cost is that a migration adding a table leaves every branch role holding nothing on it —
which is why `schema:migrate` re-applies the matrix to every schema it **advances**, and
why `schema:reapply-grants` exists for when the matrix itself changes. Without that, the
symptom at the shop is "Sync stuck" (a 42501, classified as a conflict — §3).

**The accepted risk.** There is no row-level security between branches inside one business
schema, and none is planned. A leaked branch credential can read and append across the
whole business, rewrite that business's catalog, prices, staff and settings, and queue an
adjustment against any branch of the same business. It still cannot delete anything, reach
another business, or run DDL. The enumerated matrix is **the only boundary of its kind and
must not erode** — `glossary.md` §9 is normative on both the risk and the fact that it is
settled. M11's consolidated report is the first feature to lean on it *by design*: it reads
other branches' rows on app-layer authority alone.

---

## 12. Why the invariants are load-bearing here

The offline-first invariants from `CLAUDE.md` are not stylistic; each one is what makes a
piece of the above possible.

| Invariant | What it buys sync |
|---|---|
| Never delete business records | A watermark pull is sufficient — no tombstone table (§5). |
| Append-only ledger | Contributions add instead of contesting; no conflict resolution anywhere (§5). |
| UUID primary keys | The push is idempotent, so a cycle may fail anywhere (§4). |
| Immutable history | Outbox entries can be pointers rather than payloads (§4). |
| Single writer per table | `stock_adjustment` rows have exactly one (§6). |
| Recompute, never trust a stored total | One arithmetic serves two databases (§8). |

---

## See also

- [`glossary.md`](glossary.md) — **normative** on every term used here.
- [`developer-workflows.md`](developer-workflows.md) — how to run, verify and inspect all
  of this.
- [`../MULTIBRANCH-BRAINSTORM.md`](../MULTIBRANCH-BRAINSTORM.md) — the spec of record.
- [`../MULTIBRANCH-ROADMAP.md`](../MULTIBRANCH-ROADMAP.md) — phases M0–M13.5 and their "As
  built" notes.
- [`../console/README.md`](../console/README.md) — the operator runbook for the other side.
