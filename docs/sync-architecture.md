# Evitan Plus — sync architecture, as built

> **Scope.** How a branch device and the business database actually behave in the code
> that exists today (M0–M11). `MULTIBRANCH-BRAINSTORM.md` is the spec of record and
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

`Hydrator` copies the catalog, **all** users business-wide, the settings, and this
branch's stock snapshot — every one of them online-authoritative, so copying them down
cannot lose a local edit. It deliberately does **not** copy history. The consequence is
worth stating: a device replacing an existing branch's hardware starts with empty
*branch-local* reports. Its serial does not restart, because `Hydrator::serialFloors()`
brings down the highest transaction and return numbers Postgres already holds for the
branch; the missing history is read through M11's consolidated report instead.

Hydration writes through the **query builder, never models** — models would fire the
single-Owner guard on users arriving from the authority, re-hash already-hashed passwords,
regenerate UUIDs the business owns, and hide the very `deleted_at` values being copied.

---

## 3. The sync cycle

`App\Support\Sync\SyncCycle` is the only thing in the app that runs one:

```
0. version gate  →  1. push  →  2. queue  →  3. pull  →  4. heartbeat
```

The order is load-bearing:

- **push before pull**, so the baseline the pull reads already reflects this branch's own
  movements and reconciliation has less to subtract;
- **queue between them**, so an adjustment issued elsewhere is validated against true
  reconciled stock *after* the real movements have landed, and the ledger entry it accepts
  rides the same cycle's pull back down;
- **heartbeat last**, so it describes the cycle that just finished rather than the one
  about to start.

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

## 6. Cross-branch stock: the inventory update queue

An Owner or Admin, online, may aim a stock adjustment at **another** branch. It cannot
simply be applied: the issuer cannot see the target's unsynced sales, and the two together
could drive stock negative. So the issuer writes a **pending** row into
`inventory_update_queue` (`app/Http/Controllers/Inventory/InventoryQueueController.php`,
surfaced at `/inventory/stock-updates`) and the *target branch* answers it itself, in step
2 of its own cycle (`QueueProcessor`):

```
available = upstream stock_snapshots.quantity + Σ(signed unsynced local ledger)
accept iff available + quantity >= 0
```

Those are M7's two terms, taken from the same method, so there is one definition of
"unsynced" and not two. Validating against reconciled stock is *stricter* than Postgres's
own `stock_snapshots_non_negative` — the unsynced term is where the negative movements are
— so neither side can go negative.

An accepted row is written as a ledger entry **upstream only**, and comes back down moments
later through the ordinary pull. That keeps one writer for `type = 'stock_adjustment'`,
which is what keeps last-write-wins out of the system; writing it locally too would also
enqueue it into the outbox and hand the business back its own data. The entry's id **is**
the queue row's id, so a cycle that dies mid-resolution cannot re-decide an adjustment it
already applied.

Two Postgres details that are easy to get wrong, both load-bearing:

- **The resolution must not touch `updated_at`.** The branch role holds column-level
  UPDATE on exactly four columns (`BranchGrants::UPDATE_COLUMNS`); a fifth in the SET list
  is `permission denied`, which reads as a missing grant and is in fact the matrix being
  exactly right.
- **Timestamps come from the server's clock** (`CURRENT_TIMESTAMP`), never PHP's. These
  are `timestamptz` and this app's convention is a naive local string; stamping one with
  the other is the eight-hour drift M6 spent a phase finding.

An adjustment aimed at *this* branch is unchanged and still direct.

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
renders the answer, keeps none of it. Used for `branches` and `inventory_update_queue`,
both Postgres-only by decision (§1). Same credential, same probe, same `BranchCycle`
owning the connection — **but no version gate**, and that is the one deliberate difference:
the gate exists so a build below the floor cannot *write* stale data. Refusing to render a
read-only screen would take away the display of the very problem.

`BranchRoster` is the one query behind both screens that name another branch, and it
includes **disabled** branches by default: a disabled credential stops a branch receiving
work, it does not unmake what that branch already did. The flag matters for the opposite
case — picking a branch to send an adjustment *to*.

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
  the session's UTC, eight hours out) *and* its **microseconds** (`endOfDay()` is
  `.999999`, `timestamptz` keeps them, `toIso8601String()` truncates). The filter stays on
  the raw column so the `(branch_id, created_at)` indexes still apply.
- **`numeric` SUMs** arrive as exact strings, not floats.

A report row is a time bucket **or a branch** depending on one GROUP BY, so the per-branch
table is the same seven aggregates and foots to the totals by construction. A failed read
**falls back to the branch-local report and says so** — presenting one branch under an "All
branches" heading is the one outcome worse than an error.

Cost, COGS and profit stay Owner-only *here too*: the `$withCost` flag does not hide them,
it means the cost queries are never run and the keys are absent from the payload.

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

---

## 10. Monitoring

`Heartbeat` upserts one row per branch into `branch_status` as step 4 — inside the cycle's
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
- **UPDATE** on the derived and online-authoritative tables, plus four named columns of
  the queue;
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
- [`../MULTIBRANCH-ROADMAP.md`](../MULTIBRANCH-ROADMAP.md) — phases M0–M12 and their "As
  built" notes.
- [`../console/README.md`](../console/README.md) — the operator runbook for the other side.
