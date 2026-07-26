# MULTIBRANCH-BRAINSTORM.md

# Evitan Plus — Multi-Branch, Provisioning Console & Sync

> This document is the functional spec for the **Evitan Plus** initiative: evolving the
> single-install **Evitan Plus** (`v1.0.0`) into a **multi-branch, cloud-connected** product
> backed by Supabase (Postgres), plus a **developer-only Provisioning Console** that
> oversees every business instance.
>
> It is the sibling of the original `BRAINSTORM.md` (the offline single-install MVP spec).
> Where the two disagree on multi-branch/sync matters, **this document wins**; where this
> document is silent, the original invariants in `BRAINSTORM.md` / `CLAUDE.md` still hold.
>
> **Scope of this first deliverable:** the Provisioning Console, the online business schema,
> the NativePHP instance's cloud connection, the developer-run setup wizard, and the
> **connection/sync engine** (bidirectional sync, status/heartbeat monitoring, and
> Owner-facing consolidated cross-branch reporting). The full column-by-column sync mapping
> of *every* auxiliary table is refined during the build; the core loop is in scope now.

---

## 1. Overview & goals

Evitan Plus today is a self-contained offline app for **one business installation**. Evitan
Plus keeps that offline resilience but makes it the client of a **per-business Postgres
schema** on Supabase, so a single business can run **multiple branches** that share one
authoritative dataset, while the developer operates a console that provisions and monitors
every instance.

**Primary goals**

1. **Never stop the business.** A branch keeps selling (POS, returns, defects, login/logout,
   local audit) even with no internet. This is the reason SQLite stays on every device.
2. **One authoritative dataset per business.** Postgres (one schema per business) is the
   source of truth for catalog, users, settings, inventory adjustments, and cross-branch
   reporting.
3. **Developer-operated onboarding.** The developer provisions the business, prepares the
   hardware/OS, installs the AppImage, and runs the setup wizard. The business receives a
   **turnkey device**.
4. **Least-privilege, per-branch isolation.** Each branch connects with its own Postgres
   role that can only touch its business's schema, and cannot delete immutable history.
5. **Central oversight.** The developer console can provision, migrate, rotate credentials,
   enable/disable, and monitor every branch's version and sync status.

**Explicit non-goals for this deliverable** (deferred, but the design must not preclude them):

- Multiple POS tills per branch (MVP is **one device per branch**).
- A live push/command channel from console to branch (Supabase Realtime) — status is
  polled from a per-schema status table for now.
- OS-level network-change listeners (we use a webview signal + probe + polling — see §11).

---

## 2. Product framing & who touches what

| Actor | Touches | Never touches |
|---|---|---|
| **Developer (you)** | Provisioning Console; hardware/OS prep; AppImage install; setup wizard; schema migrations; credential rotation; monitoring | — |
| **Business Owner** | In-app screens: login (pre-created Owner), POS, in-app admin (inventory, users, settings, reports incl. consolidated cross-branch) | Provisioning Console, setup wizard, DB credentials |
| **Admin / Cashier** | In-app POS + permitted in-app screens | Provisioning Console, setup, credentials |

**The onboarding chain is entirely developer-run:**

```
Provision business (console)  →  configure hardware/OS  →  place AppImage on device
     →  run setup wizard (import JSON, connect, hydrate)  →  hand over turnkey device
```

The business simply powers on a ready device and logs in as the Owner that was created
during provisioning.

---

## 3. Architecture at a glance

```
┌──────────────────────────────┐        ┌───────────────────────────────────────────┐
│  Provisioning Console         │        │  Supabase (Postgres)                        │
│  (developer-only, own app,    │ admin  │  ┌─────────────────────────────────────┐   │
│   NOT shipped in any build)   │───────▶│  │ schema biz_<id>  (one per business) │   │
│                               │  DDL   │  │  users, categories, items, variants,│   │
│  - provision business/branch  │        │  │  prices, settings, branches,        │   │
│  - migrate schemas            │        │  │  transactions, transaction_items,   │   │
│  - rotate / disable / delete  │        │  │  inventory_ledger, stock_snapshots, │   │
│    branch DB roles            │        │  │  returns, defects, login_history,   │   │
│  - monitor versions & sync    │        │  │  audit_logs, inventory_update_queue,│   │
└──────────────────────────────┘        │  │  branch_status                      │   │
                                         │  └─────────────────────────────────────┘   │
                                         │   Postgres ROLE per branch (least-priv)     │
                                         └───────────────────────────────────────────┘
                                                        ▲  bidirectional sync
                                                        │  (push insert-only history,
                                                        │   pull catalog/users/stock,
                                                        │   process inventory queue)
                                         ┌──────────────┴───────────────┐
                                         │  Evitan Plus app instance     │  (one per branch)
                                         │  custom NativePHP binary      │
                                         │  ┌──────────────┐             │
                                         │  │ SQLite (local│  offline    │
                                         │  │ branch-scoped│  writes +   │
                                         │  │ mirror + out-│  read cache │
                                         │  │ box + queue) │             │
                                         │  └──────────────┘             │
                                         └───────────────────────────────┘
```

- **Custom NativePHP binary**: ships with both **SQLite** (offline) and **pgsql** (online)
  drivers. The `pgsql` connection already exists in `config/database.php`; the binary is
  supplied by the developer and dropped in when needed.
- **One Postgres schema per business.** The schema holds **all branches** and all business
  data. A business never spans more than one schema.
- **One Postgres role per branch**, least-privilege, scoped to that business's schema only.

---

## 4. Non-negotiable invariants (this initiative)

These extend the original invariants in `CLAUDE.md`/`BRAINSTORM.md`, which all still apply
(immutable history, append-only ledger, ledger-derived inventory, never delete business
records, UUID PKs, one Owner, Item Cost Owner-only, negative inventory never allowed, etc.).

**Offline-first, never stop selling**
- Offline-capable writes (POS sales, returns, defects, login/logout, local audit) always
  commit to **SQLite first** and are synced afterward — the network is **never** in the
  checkout hot path, online or offline.
- A sync failure, an authorization failure (disabled role), or a version mismatch **never**
  stops the till from selling. Work queues locally and flushes later.

**Single-writer per table (no edit-edit conflicts)**
- Every mirrored table has exactly **one writer**. Online-authoritative tables
  (catalog, users, settings, inventory adjustments) are written **only** online and pulled
  down read-only. Local-write tables (sales, transaction_items, returns, defects,
  login_history, local audit) are written **only** on the till, are append-only/immutable,
  and are pushed **insert-only** keyed by UUID (idempotent re-push).
- There is therefore **no last-write-wins** anywhere. See §10 for the one shared quantity
  (stock), which reconciles **additively**, not by conflict resolution.

**Negative inventory never crosses the online/offline boundary**
- Real, immutable local movements (sales/returns/defects that already happened) always win.
- Online, branch-targeted inventory **adjustments** are mediated through an
  **inventory update queue** and only applied when they keep reconciled stock `≥ 0`.
  An infeasible subtractive adjustment is **rejected and flagged in the in-app admin**
  (the business's own screens — *not* the Provisioning Console). See §10.

**Per-branch continuous serial**
- The transaction number is a **per-branch** continuous, gapless, zero-padded running serial
  with a branch prefix (e.g. `MNL-0000000001`). It never resets and is generated locally so
  it works fully offline. Business-wide uniqueness comes from the branch prefix. See §12.

**Isolation & least privilege**
- A branch role can only reach **its own business's schema**. It has **SELECT + INSERT**
  where it reads/appends and **UPDATE** only on the few mutable rows (its `branch_status`
  row, `stock_snapshots`, and `inventory_update_queue` status). It has **no DELETE** — DB
  grants enforce immutability, not just app logic and triggers.

**The Provisioning Console never ships**
- Console code and its powerful admin credentials must never appear in any AppImage/deb/build.
  This is enforced **structurally** (separate app, not referenced by the build) plus
  defense-in-depth env cleanup. See §18.

**Auditability everywhere, including the console**
- All in-app significant actions still audit (as today). The **Provisioning Console also
  audits its own actions** (provision, migrate, rotate, disable, delete) to a developer-side
  provisioning audit log.

---

## 5. Data model — who writes what

Tables fall into three classes. The class determines sync direction and grants.

### 5.1 Online-authoritative (pulled down, read-only on the till)
Edited **only** online (Provisioning Console or in-app admin while online); the till mirrors
them for offline reads and **never** edits them locally.

- `categories`, `items`, `variants`, `prices` — the shared **catalog** (mirrored in full).
- `users` — mirrored **business-wide** (all users' credential hashes on every device) so
  **any user can log in offline at any branch** (see §6). Still one Owner; Item Cost stays
  Owner-only at the app layer.
- `settings`, business identity, `branches` (branch identity/codes).
- `inventory_ledger` rows of type **restock / manual adjustment / import** (stock-ins and
  online adjustments) — see §10 for how adjustments are mediated.

### 5.2 Local-write (pushed up, insert-only)
Written **only** on the till, append-only/immutable, pushed as brand-new UUID-keyed rows.

- `transactions`, `transaction_items`
- `inventory_ledger` rows of type **sale / return / defect** (stock-outs and returns)
- `returns`, `defects`
- `login_history` (local sessions), local `audit_logs`

### 5.3 Derived / coordination
- `stock_snapshots` — per-branch current stock, ledger-derived; reconciled additively (§10).
- `inventory_update_queue` — pending online adjustments awaiting branch confirmation (§10).
- `branch_status` — per-branch heartbeat: app version, schema version, last sync, unsynced
  count, last seen (§15).

> **Mirror scope:** the local SQLite mirror is **branch-scoped** for everything (this
> branch's stock, this branch's transactions/history) **except `users`**, which is mirrored
> business-wide to satisfy any-user/any-branch offline login. The full multi-branch dataset
> lives only in Postgres.

---

## 6. Users, roles & offline login

- Users are managed **online** (in-app admin, when connected) and written to the schema's
  `users` table. There is exactly **one Owner** per business; the Owner spans all branches.
- **Any user can log in at any branch.** Every device therefore caches **all** business
  users (including password hashes) so login works fully offline.
- Permissions (Owner > Admin > Cashier) are enforced at the **app layer** as today —
  including **Item Cost is Owner-only** everywhere (reports, API, consolidated reports).
  The shared per-branch DB role is least-privilege but not user-aware; per-user visibility
  is never a DB-grant concern.
- `login_history` is written locally and pushed insert-only; the Owner/admin can see login
  activity across branches online.

---

## 7. Multi-tenancy: schema & branch roles

- **Schema per business:** provisioning creates `biz_<id>` (id derived from the business
  UUID) containing every table in §5 and all branches' data.
- **Role per branch:** provisioning creates a Postgres login role for each branch, e.g.
  `branch_<id>`, with:
  - `USAGE` on the business schema (and nothing on other schemas);
  - `SELECT`, `INSERT` on mirrored/append tables;
  - `UPDATE` only on `branch_status`, `stock_snapshots`, `inventory_update_queue.status`;
  - **no `DELETE`**, no `TRUNCATE`, no DDL;
  - `search_path` pinned to the business schema.
- The console (admin/superuser connection) owns all DDL, migrations, and role management.

---

## 8. The Provisioning Console (developer-only)

A separate **Laravel application** living in this monorepo but **excluded from every build**
(§18), using **Laravel Prompts** for its interactive TUI and a **direct admin Postgres
connection** for DDL. It reuses Laravel's migration engine to apply the Postgres schema
migrations.

### 8.1 Provision a **business** (creates a new schema)
Collects, in one flow, before anything is created:
- **Owner** details (name, username, password → hashed);
- **Business** identity (name, currency, timezone, etc.);
- **First branch** details (name, **branch code** used as the serial prefix, address).

Only when complete does it:
1. `CREATE SCHEMA biz_<id>`;
2. run the Postgres schema **migration** into it;
3. populate `users` (Owner), business `settings`/identity, and the first `branches` row;
4. `CREATE ROLE branch_<id>` with least-privilege grants (§7);
5. emit a **credentials JSON** (§9) for that branch.

### 8.2 Provision a **branch** (under an existing business)
1. Insert a `branches` row (name, unique **branch code**, address) in the existing schema;
2. `CREATE ROLE branch_<id>` with least-privilege grants;
3. emit a **credentials JSON** for that branch.
(No new schema, no re-migration; the catalog/users/settings are already there and will be
pulled by the new branch at hydrate.)

### 8.3 Credential lifecycle
- **Rotate** a branch's password. Per the delivery model (§9), a rotation is delivered as a
  **fresh AppImage + new JSON** to that branch, which re-imports and re-syncs as that branch.
- **Disable / enable** a branch role (revoke/grant `LOGIN`). While disabled, the branch's
  sync fails "unauthorized" and it keeps selling offline behind a clear banner; enabling
  restores sync and flushes the outbox.
- **Delete** a branch role (and **re-create** later when needed — re-create emits a new JSON
  and follows the rotation delivery path).

### 8.4 Migrate schemas on updates
- The console holds the Postgres migration set and can apply pending migrations to **one or
  all** business schemas. Migrations carry a **schema version** and a **minimum-compatible
  app version** used by the sync version gate (§14).

### 8.5 Monitor
- Reads `branch_status` across all schemas to show, per branch: app version, schema version,
  last sync time, unsynced count, last seen, connection state. See §15.

### 8.6 Console self-audit
- Every provision/migrate/rotate/disable/delete writes a developer-side provisioning audit
  entry (who/what/when/target).

---

## 9. Credential JSON & on-device credential storage

**The JSON** (emitted per branch at provisioning) contains everything the setup wizard needs:
- business identity + business id/schema name;
- branch identity + **branch code** (serial prefix) + branch id;
- Supabase connection (host, port, database, SSL);
- the branch Postgres **role username + password**;
- the schema's current **schema version** and **min-compatible app version**.

**Delivery & storage:**
- The developer runs the setup wizard on the prepared device and **imports the JSON**.
- The wizard stores the DB credentials **encrypted at rest** using the device's per-install
  `APP_KEY` (already generated per install by `EncryptionKeyBootstrapper`; `APP_KEY` is in
  `cleanup_env_keys`, so it is never shipped).
- The JSON is treated as **one-time**: after a successful import the wizard prompts to
  delete/move it, so the powerful credential does not linger in plaintext on disk.
- The `pgsql` connection decrypts credentials **only at runtime**.
- **Rotation = fresh AppImage + new JSON + re-sync** (not an in-place swap).

---

## 10. Stock model & the inventory update queue

Stock is **ledger-derived** and is the only quantity both sides influence — but additively,
never as a conflicting edit.

```
current stock = Σ(online ledger: restocks, adjustments, imports)
              + Σ(local ledger: sales, returns, defects)
```

**Local reconciliation rule (never blind-overwrite):**
```
effective local stock = pulled Postgres baseline − local unsynced out-movements
```
When the till pulls the stock baseline, that baseline reflects online entries only and does
**not** yet include the till's unsynced sales; those are subtracted locally. Once the local
sales push and Postgres recomputes, the next pulled baseline already includes them and the
unsynced set is empty again. It self-heals.

**Why staleness is safe:**
- A price change / archive done online while offline: the sale **snapshots** its price and
  commits (immutable history). HQ just sees a now-old price; next pull refreshes the catalog.
- A restock done online while offline only raises stock; a stale-low baseline can at worst
  wrongly *block* a sale — it can never oversell. One-device-per-branch removes intra-branch
  oversell entirely.

**The inventory update queue (subtractive online adjustments):**
Online admins can also *subtract* stock from a branch. A naive apply could drive reconciled
stock negative once unsynced offline sales arrive. So branch-targeted adjustments are
**mediated**:

1. An online adjustment for a branch is written to `inventory_update_queue` as **pending**
   (it is **not** yet an authoritative ledger entry).
2. On the branch's next sync, the till first pushes its real immutable movements
   (sales/returns/defects — these already happened and win), then walks the queue **in order**
   and validates each pending adjustment against true reconciled stock:
   - keeps stock `≥ 0` → **accept**: create the ledger entry, mark the queue row accepted;
   - a subtractive adjustment would go `< 0` → **reject**: mark the queue row rejected and
     **flag it in the in-app admin** for the Owner/Admin to review and re-issue if still needed.
3. Because the adjustment only becomes an authoritative ledger entry on acceptance, **neither
   Postgres nor the local store ever goes negative.**

This holds for both currently-offline and currently-online branches (an online branch just
processes the queue seconds later on the next opportunistic/polled cycle).

---

## 11. The sync engine

### 11.1 Write path
**Local-first, always.** Every offline-capable write commits to SQLite and is queued in an
**outbox**. Immediately after each checkout, if online, an **opportunistic sync** fires for
that transaction so Postgres trails by *seconds*, not a full cycle — without putting the
network in the commit path.

### 11.2 Sync cycle (bidirectional, in scope now)
On a successful connectivity **probe**, one cycle does, in order:
1. **Push** the outbox (insert-only, UUID-keyed, idempotent): sales, transaction_items,
   returns, defects, sale/return/defect ledger rows, login_history, local audit.
2. **Process** the `inventory_update_queue` (§10).
3. **Pull** deltas of online-authoritative tables (catalog, users, settings, branch identity)
   and the recomputed **stock baseline**; apply the local reconciliation rule.
4. **Heartbeat**: upsert this branch's `branch_status` row (§15).

Push precedes pull so that stock reconciliation sees the branch's own movements reflected.
Deltas are detected via `updated_at`/change markers on the authoritative tables.

### 11.3 Connectivity detection (near-instant, no OS-specific code)
- **Instant trigger:** the React/Inertia UI runs in the NativePHP webview, so it listens to
  the browser `online`/`offline` events (`navigator.onLine`) and pings the backend the moment
  the link returns.
- **Confirming probe:** because `navigator.onLine`/OS "up" is optimistic (captive portals,
  Supabase down), a lightweight probe (`SELECT 1` / health ping) **always confirms** before a
  real sync runs.
- **Adaptive polling (backend safety net):** probe **fast while offline** (~10–20s) and
  **slow while stably online**, so worst-case reconnect is seconds without any native plumbing.
- **Backstop timer:** a configurable interval (default 15 min) as a long backstop.
- **Manual "sync now"** button always available.
- *Deferred:* OS network-change listeners and a Supabase **Realtime** channel (the latter
  would also give the console a live monitor/command channel) — both reuse the same
  probe→sync path and can drop in later.

### 11.4 Idempotency & atomicity
- All pushes are inserts keyed by UUID → re-push is a harmless upsert-by-UUID.
- A cycle is transactional per store; a mid-cycle failure leaves the outbox intact for retry.

---

## 12. Transaction serial (per-branch)

- Each branch owns its own **continuous, gapless, zero-padded** running serial that never
  resets, generated **locally** (offline-safe).
- The customer-facing receipt number **is** this serial, prefixed by the **branch code**
  (e.g. `MNL-0000000001`, `CEB-0000000001`). Business-wide uniqueness comes from the prefix.
- The branch code is set at branch provisioning and carried in the credentials JSON.

---

## 13. Version gate & schema/migration management

**Two independently-versioned things:** the **app build** on each device and the **Postgres
schema version** the console migrates to.

- Each sync request carries the app's schema version. The schema declares a
  **minimum-compatible app version**. If the app is below it (a breaking migration ran ahead
  of the branch's build), sync is **refused in both directions** (a stale push would corrupt
  Postgres; a new-format pull would corrupt the old SQLite) — but the till **keeps selling
  offline**; the console shows the branch as version-behind. Updating the app realigns
  versions and flushes the queued work.
- Additive/backward-compatible migrations do **not** raise the min-compatible version, so
  routine updates don't needlessly freeze every branch.

**Migrations are separated:**
- **Postgres schema migrations** — owned by the Provisioning Console, applied to business
  schemas. Their natural home is the console app.
- **App/SQLite migrations** — owned by the shipped app, applied locally.

**Preventing drift on the mirrored tables:** the two migration sets are hand-written (each
dialect expresses its own triggers/constraints naturally), guarded by an **automated parity
test** that introspects both schemas and asserts the **mirrored** tables agree on column
names and types through a known type map (`uuid↔text`, `timestamptz↔text`, `numeric↔real`,
`boolean↔integer`, …). Forget one side → CI fails. (The databases are deliberately *not*
identical: Postgres has extra tables the till never mirrors.)

---

## 14. Monitoring & status

- Each instance upserts its `branch_status` row **inside its own business schema** on every
  sync (fits least privilege — the branch role writes only its own schema).
- Fields: `branch_id`, `app_version`, `schema_version`, `last_sync_at`, `unsynced_count`,
  `last_seen_at`, `connection_state`.
- The console aggregates `branch_status` across all schemas for its monitor view.
- **Unsynced count** = outbox depth; **last sync status** = last successful cycle + any last
  error (auth/version/network) surfaced both in-app and to the console.

---

## 15. Consolidated cross-branch reporting (in scope now)

- **Offline:** in-app reports are **branch-local**, recomputed from that branch's
  sales − returns − defects (unchanged reporting invariant).
- **Online:** the Owner can view **consolidated cross-branch** reports. The branch role has
  schema-wide `SELECT`, so the app queries Postgres directly across all branches and
  **recomputes** totals (never trusts a stored total). Visibility is app-enforced
  (Owner-facing; **Item Cost stays Owner-only** even in consolidated views).

---

## 16. The setup wizard (developer-run, connect-and-hydrate)

The wizard replaces the old "create the Owner locally" flow — the Owner already exists from
provisioning. It requires internet on first run and:

1. **Imports** the credentials JSON.
2. **Stores** the DB credentials encrypted at rest (§9) and prompts to remove the JSON.
3. **Connects** to Postgres as the branch role and verifies version compatibility.
4. **Runs** the local SQLite migrations.
5. **Hydrates** the branch-scoped baseline: full catalog, **all** users (business-wide),
   settings/identity, and this branch's stock snapshot.
6. Leaves a ready device; the business logs in as the pre-created Owner.

---

## 17. Offline behavior summary

| Capability | Offline? | Notes |
|---|---|---|
| POS sale, returns, defects | ✅ | Local-first; queued; per-branch serial generated locally |
| Login / logout, local audit | ✅ | All users cached; login_history queued |
| Branch-local reports | ✅ | Recomputed from local sales−returns−defects |
| Read catalog / prices | ✅ | From last-pulled mirror (may be slightly stale) |
| Restock / inventory adjust | ❌ | Online-only; adjustments mediated via the queue |
| User CRUD, item/catalog CRUD | ❌ | Online-only (authoritative in Postgres) |
| Consolidated cross-branch reports | ❌ | Online-only (queries Postgres across branches) |
| Sync / monitoring | ❌ | Requires connectivity; till keeps selling regardless |

---

## 18. Build & environment separation (security)

- **Structural exclusion:** the Provisioning Console is a **separate Laravel app** in its own
  monorepo directory (`console/`), with its own composer autoload and **its own `.env`**
  holding the admin Postgres credentials. The NativePHP build targets **only** the POS app
  and must be verified to **not** bundle `console/`. No runtime reference from the POS app to
  console code exists.
- **Env separation:** the shipped app's `.env` never contains admin credentials; branch DB
  credentials arrive at runtime via the encrypted store (from the JSON), not baked into the
  build. Console-only and secret keys remain covered by `cleanup_env_keys`.
- **Two migration sets, two owners** (§13).
- **Least-privilege branch roles** (§7): a leaked branch credential can read/append within
  one schema but cannot delete history, touch other businesses, or run DDL.

---

## 19. Open items / explicitly deferred

- **Multiple tills per branch** — needs shared-stock coordination/allocation; out of MVP.
- **Supabase Realtime channel** — instant reconnect *and* a live console↔branch
  monitor/command channel; drop-in later over the same probe→sync path.
- **OS network-change listeners** — near-instant reconnect on top of the webview signal.
- **Auto credential rotation** over a bootstrap channel (vs. today's fresh-AppImage delivery).
- **Cross-timezone branches** — business timezone is business-level for now (Asia/Manila
  default); revisit if branches span zones.
- **Full column-by-column sync mapping** of every auxiliary table beyond the core loop.
