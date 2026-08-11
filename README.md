# Evitan Plus

**Evitan Plus** is an offline-first, multi-branch retail Point of Sale desktop app. Many branches of one business share a single authoritative Postgres schema on Supabase; each branch runs Laravel + React packaged as a native desktop executable via NativePHP, sells against its own local SQLite database, and reconciles with the business database through a sync cycle.

**Offline-first means offline-capable, not offline-only.** A till that loses its network keeps selling, queues its work, and catches up when the connection returns. Only shared writes — catalog, staff and settings, which every branch must agree on — require being online. UUID primary keys throughout are what make that reconciliation work.

> **Naming.** **Evitan POS** is the single-install, offline-only product released as `v1.0.0`; **Evitan Plus** is the multi-branch, cloud-connected product it was evolved into by the `M0–M12` initiative. They are **different products**, with different application ids and data directories — not old and new spellings of one name. This README describes **Evitan Plus**; `RELEASE-NOTES.md` records v1.0.0 as it shipped, and `docs/glossary.md` §1 is normative on the split.

## Highlights

- **Point of sale** — product grid and search (name / SKU / barcode), barcode scanner support, cart with quantity editing, amount/percentage discounts, cash / cashless / others payment types, cash-received and automatic change, and an optional checkout comment. Out-of-stock products stay visible but disabled.
- **Ledger-based inventory** — categories, items, and variants with an **append-only** inventory ledger as the single source of stock truth. Current stock is ledger-sum reconciled against a snapshot; **negative inventory is never allowed**. Low-stock thresholds highlight rows that need restocking.
- **CSV import / export** — bulk catalog upsert by SKU with "replace stock count" and "add to current stock" modes, downloadable template, validation messages, and upload history. Every import funnels through the ledger.
- **Immutable sales history** — completed transactions and line items never change. Selling price and item cost are snapshotted at time of sale, so later price edits never rewrite past sales. The transaction number is a continuous, gapless, zero-padded running serial (`0000000001`, never resets — the BIR-compliant shape) that doubles as the receipt number.
- **Returns & defects** — partial and multiple returns (restore stock) and defect write-offs (do not restore stock), recorded in a dedicated `sale_returns` family that references the original sale without ever mutating it.
- **Receipt printing** — driverless ESC/POS straight to the device (USB / Bluetooth / network socket), 58mm and 80mm, auto-print, cash-drawer kick, and reprint. A printing failure **never** rolls back a completed sale.
- **Reports** — daily / weekly / monthly / yearly sales on one page with drill-down buckets, a stacked-by-item net-sales chart, and PDF export. Totals are always recomputed from sales minus returns minus defects; COGS and profit are Owner-only.
- **Roles & permissions** — Owner > Admin > Cashier, enforced at routes, actions, and UI. **Item cost is Owner-only** everywhere, including reports and API responses.
- **Admin & ops** — user management (create / archive / reset password / change role), login history (login, logout, crash, forced logout, power loss), an immutable audit log of every significant action (Admin+ view, cost redacted for non-Owners).
- **Multi-branch sync** — every local write is enqueued in an outbox and carried upward by a four-step sync cycle (version gate → push → pull → heartbeat), which also brings down catalog, staff and settings changes made at other branches. The header shows the unsynced count and a **Sync now** button on every screen; receipt serials are branch-prefixed (`MNL-0000000001`).
- **Branch scope on reports and login history** — `local` (this branch, works offline), `all`, or a single branch. Consolidated scopes are an online read and fall back to the branch-local view, saying so, rather than presenting one branch under an "All branches" heading. Reports are Owner-only beyond `local`; login history is Admin+.
- **Pending stock adjustments** — no stock moves because somebody typed a number. A recount, a stock batch, an adjustment aimed at another branch and both legs of a branch transfer all become *pending* until a person at the affected branch confirms it happened, from **Pending Stocks** in the sidebar. They can approve, approve part of it, or turn it down with a reason; **Approve all** clears a whole card at once. Answering is open to every role — whoever is in the shop is who knows whether the delivery arrived — while asking stays Admin+.
- **Opening stock from a spreadsheet** — the one exception to the rule above, and the only bulk path into stock. A branch that has never sold anything can set its whole shelf from a `sku, quantity` CSV on Inventory: a quantity is the total that should be there, so re-uploading a corrected file moves only the difference. Every SKU must already exist, and the option disappears at the branch's first sale — after that a shelf has a history, and only a person can speak for it.
- **Developer-run setup** — a fresh install is connected to its business by importing the credentials JSON the Provisioning Console emits. One atomic submit connects, hydrates the branch's catalog, staff, stock and history into local SQLite, and seals the credential under a per-install `APP_KEY`. Replacing dead hardware is the same flow.

## Tech stack

- **Laravel 12** (backend) — pinned to 12 for NativePHP compatibility.
- **React 19 + TypeScript** over **Inertia**, from the Laravel React starter kit.
- **Tailwind CSS 4** + **shadcn/ui** components.
- **NativePHP (Electron)** to package the app as a native desktop executable.
- **SQLite** as the device database (`database/nativephp.sqlite`), with foreign-key enforcement on. Every screen reads it, which is what makes the app work offline.
- **Postgres on Supabase** as the business database — one schema per business, reached through `App\Support\Sync\*` on the sealed branch credential.

## Repository layout

| Path | What it is |
|---|---|
| `/` (root) | **Evitan Plus** — the shipped desktop app. Laravel + React + NativePHP over local SQLite. |
| `console/` | **Provisioning Console** — a developer-only Laravel CLI app with its own `composer.json`, `vendor/`, `.env` and autoload namespace (`Provisioning\`). It holds the admin Postgres credentials and owns all DDL. |

`console/` is **never bundled into any build**, and the POS app contains no
runtime reference to it. That boundary is enforced by
`nativephp.cleanup_exclude_files` / `cleanup_env_keys`, gated by
[`tests/Feature/Build/BuildExclusionTest.php`](tests/Feature/Build/BuildExclusionTest.php)
in `composer verify`, and re-checked by hand at release time (see "Building a
release", step 4).
Spec: [`MULTIBRANCH-BRAINSTORM.md`](MULTIBRANCH-BRAINSTORM.md) §18.

## Requirements

- PHP **8.2+** and Composer
- Node.js **24** and npm
- SQLite

To cut a desktop release you also need your dev machine's PHP minor to match the
vendored runtime in [`build/php/`](build/php/README.md) (currently **8.5**) —
NativePHP bundles the zip matching the host minor. That runtime, not
`nativephp/php-bin`, is what ships inside the app: it is the only one carrying
both `pdo_sqlite` and `pdo_pgsql`.

## Getting started

```bash
# 1. Install dependencies
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate

# 3. Database (creates/migrates database/nativephp.sqlite)
php artisan migrate
```

Run the app in development:

```bash
# Web dev — Laravel server + queue + logs + Vite, all at once
composer dev

# Desktop dev — NativePHP (Electron) shell + Vite
composer native:dev
```

Default development login: **`owner` / `password`** (created by the local demo
seed — `migrate:fresh --seed`, which also writes a fake `DEV` branch identity so
the app does not funnel to the setup wizard). A packaged fresh install has
neither: it opens the setup wizard, which imports a branch credential and
hydrates the business from Postgres.

### Provisioning console (developer-only)

Optional for POS development; required for anything in
[`MULTIBRANCH-ROADMAP.md`](MULTIBRANCH-ROADMAP.md). It is a self-contained
Laravel app with its own dependency tree.

> **Operating it day to day, and what to do when something goes wrong:**
> [`console/README.md`](console/README.md) — the runbook. Command reference,
> recipes, a symptom→cause→fix troubleshooting table, and how to read the
> provisioning audit trail.

Once it is set up, `php console/artisan` with no arguments opens `console:run`,
an interactive menu: pick a business, pick a branch, pick an action. That is the
intended way to drive it. The commands below are the scripting surface and what
the menu dispatches — each one still confirms and reports exactly as it does
here, because the menu never passes `--force`.

```bash
(cd console && composer install)
cp console/.env.example console/.env
php console/artisan key:generate

# Paste your Supabase admin credentials into console/.env (CONSOLE_DB_*), then
# verify both endpoints: the admin connection and its DDL capability, and the
# endpoint branches are issued (see the two-endpoint table below). It reports
# the branch endpoint and connects to it, so a misconfiguration surfaces here
# rather than at the end of a provision.
php console/artisan console:ping

# Create the console's OWN schema (evitan_console) — the provisioning audit
# trail. Once per Supabase project; provisioning refuses to run without it.
php console/artisan console:migrate

# Provision a business: schema + migration + Owner/settings/first branch +
# least-privilege branch role + credentials JSON, in one flow. Interactive by
# default; every prompt also has an option, so it scripts cleanly.
php console/artisan business:provision

# Build a business schema (biz_<id>) and report its version. Idempotent —
# re-running applies only what the schema has not seen, which is also how a
# schema is upgraded when new Postgres migrations land. business:provision calls
# schema:migrate itself; these are for inspecting or upgrading an existing one.
php console/artisan schema:migrate --business=<business-uuid>
php console/artisan schema:version --business=<business-uuid>

# Branch lifecycle. Every one of these targets a business with --business=<uuid>
# or --schema=<name>, and a branch with --branch=<uuid> or --branch-code=<code>.
php console/artisan branch:list    --business=<business-uuid>
php console/artisan branch:provision --business=<business-uuid>   # + role + JSON
php console/artisan branch:rotate  --business=<business-uuid> --branch-code=MNL
php console/artisan branch:disable --business=<business-uuid> --branch-code=MNL
php console/artisan branch:enable  --business=<business-uuid> --branch-code=MNL
php console/artisan branch:revoke  --business=<business-uuid> --branch-code=MNL

# The menu over all of the above. Also the default command, so a bare
# `php console/artisan` opens it. Needs a terminal; it refuses under
# --no-interaction rather than hanging on a prompt.
php console/artisan console:run

# Console test suite, in two halves. The Feature half needs the credentials
# above and exercises the real Supabase project; it skips itself cleanly without
# them, and the whole thing is deliberately NOT part of `composer verify`, which
# must stay runnable offline and without admin credentials. The Unit half needs
# nothing and runs on a fresh clone.
composer verify:console                # both halves, --fail-on-skipped (the gate)
(cd console && composer test)          # same, without the honesty flag
(cd console && ./vendor/bin/phpunit --testsuite=Unit)   # credential-free half
```

**Two endpoints, one Supabase project.** They are not interchangeable:

| | endpoint | why |
| --- | --- | --- |
| Console admin (`CONSOLE_DB_*`) | **session** pooler, `5432` | DDL, `CREATE`/`ALTER ROLE` and its own `search_path` pin all need session state to survive between statements. Transaction mode hands out a different backend per transaction and none of that holds. One operator, so pinning a backend costs nothing. |
| Branch devices (`CONSOLE_BRANCH_DB_*`) | **transaction** pooler, `6543` | Session mode pins one Postgres backend per client for the client's whole life, and the pool is 15 wide on this project. Hundreds of branches is not a tuning problem at that point, it is arithmetic. Transaction mode multiplexes backends, holding one only for the length of a transaction. |

Branches can use transaction mode only because their schema is pinned
server-side by `ALTER ROLE ... SET search_path` — a role default Postgres applies
at backend startup, so pooling cannot lose it. That is why every credentials JSON
carries `connection.search_path: null`, and why a till must connect, run one sync
cycle and disconnect rather than hold a connection open (`MULTIBRANCH-BRAINSTORM.md`
§11.5). `CONSOLE_BRANCH_DB_HOST`/`_PORT` default to the admin's host and `6543`,
so the common setup configures neither.

Pooler usernames are `postgres.<project-ref>`, not plain `postgres`.
**Never copy `CONSOLE_DB_*` values into the root `.env`.**

`business:provision` writes each branch's credentials JSON to
`console/storage/app/credentials/` at mode `0600` (git-ignored, and pruned from
every build). That file is the **only** copy of the branch's database password —
the console never prints it. Treat it as one-time: deliver it, import it in the
setup wizard, then remove it. Prefer the interactive password prompt over
`--owner-password`, which is visible in `ps` and shell history.

### Branch credentials

`branch:provision` and `branch:rotate` each emit a **new** JSON; the previous
one is dead the moment they succeed. Delivery is a **fresh AppImage plus the new
JSON**, re-imported through the setup wizard — never an in-place password swap.

Two commands look similar and are not:

- **`branch:disable`** revokes the role's `LOGIN`. Reversible in one statement
  with `branch:enable`, grants preserved, and it does **not** touch
  `branches.disabled_at` — a credential you suspect has leaked is not a branch
  that has closed. While disabled the till keeps selling offline and its outbox
  flushes when you re-enable it.
- **`branch:revoke`** drops the Postgres role. The branch row, its sales and its
  history stay; only the login goes. The way back is `branch:rotate --recreate`.

**A re-created role is unusable for about two minutes.** Supabase's pooler keys
warm connections by username, so dropping a role and creating one with the same
name leaves it serving `invalid role OID` until the cache expires. The credential
is valid throughout. `branch:rotate` waits (`--verify-wait`, default 180s) and,
if the wait runs out, **keeps everything and reports `Verified: not yet`**. That
is deliberate: by then the role already holds the new password and the JSON is
its only copy, so discarding it would strand the branch permanently. Deliver it,
or re-run to confirm. `branch:provision` still rolls back in full on any failure
— it created everything in that run, so undoing it destroys nothing.

## Common commands

```bash
php artisan test          # Pest / PHPUnit test suite
npm run e2e               # Playwright E2E suite (isolated DB copy)
npm run lint              # ESLint (auto-fix)
npm run format            # Prettier
./vendor/bin/pint         # Laravel Pint (PHP formatting)
npm run build             # Production frontend build
php artisan native:build  # Build the native desktop executable

php console/artisan console:ping        # Verify both Supabase endpoints (admin + branch)
php console/artisan console:migrate     # Create the console's provisioning audit schema
php console/artisan business:provision  # Provision a business + its first branch

composer rig          # Two tills of a throwaway business, 8131 + 8132 (Ctrl-C tears down)
composer rig:wizard   # As rig, but un-hydrated — drive the setup wizard yourself
composer rig:clean    # Destroy what a killed rig left behind
```

## Building a release

A release is three things, not one: an artifact, a migration of every business
database, and a physical visit to every till — there is no auto-update. The
headline commands are

```bash
composer verify                  # every offline gate, fail-fast
php console/artisan schema:migrate --all   # before building, always
php artisan native:build linux   # artifacts land in dist/
```

**[`docs/releasing.md`](docs/releasing.md) is the procedure of record** — the
version-pair decision that drives everything else, the migrate-then-install
ordering and why it reads backwards, the artifact inspection, delivering to a
till, confirming with `monitor`, and the recovery paths. Follow it rather than
the summary above.

The packaged app provisions itself on every launch (migrate → stock
reconcile) and generates a per-install `APP_KEY` on first boot; a device that
has not been hydrated into a branch opens the setup wizard before anything else.

## Developer onboarding: standing up a branch

> **Status:** the flow below is **shipped end to end** as of Phase M5 — a device
> given a credentials JSON and internet becomes a working branch. Everything
> *after* setup — pushing sales up, pulling changes down, cross-branch stock,
> monitoring, consolidated reports — has landed since. Confirm current phase
> status against [`MULTIBRANCH-ROADMAP.md`](MULTIBRANCH-ROADMAP.md) and `git log`
> rather than this line.

Every branch device is prepared by a developer, in this order:

1. **Provision** — in `console/`, run `business:provision`: it creates the
   business schema (`biz_<id>`), migrates it, seeds the Owner, business identity
   and first branch, creates the least-privilege `branch_<id>` Postgres role, and
   emits that branch's credentials JSON. It verifies the JSON by connecting as
   the new role before reporting success. Adding a further branch to an existing
   business, and rotating, disabling, enabling or revoking a branch's credentials,
   are the `branch:*` commands — drive all of it from `php console/artisan`, the
   interactive menu.
   *(shipped — Phases M2 and M3.)*
2. **Hardware** — prepare the till: OS install, operator account, receipt
   printer, cash drawer.
3. **AppImage** — build the release and copy it to the device (see
   [`docs/releasing.md`](docs/releasing.md)). Credential rotation is delivered
   the same way: a fresh AppImage plus a new JSON, never an in-place swap.
4. **Wizard** — launch the app on the device; with no branch identity it opens
   the setup wizard. Give it the path to the JSON (**Browse** opens the OS file
   picker in the desktop build), optionally configure the receipt printer, and
   submit. One atomic step connects as the branch role, checks the version gate
   against `schema_meta`, copies the baseline down — catalog, **all** users,
   business settings, this branch's stock snapshot — seals the credential under
   the per-install `APP_KEY`, and records who this device is. Nothing is written
   unless all of it succeeds, so a failure means "fix what the message says and
   submit again" and never leaves a half-set-up device.
   *(shipped — Phase M5.)*
5. **Stock it** — log in as the Owner and set the shelf, because hydration
   copies whatever the business database holds and a new branch has nothing.
   For a **new business** that is two files: the item CSV on `/items` (products,
   business-wide), then the opening-stock CSV on `/inventory` (`sku, quantity`).
   For a **new branch** it is only the second — the catalog came down with the
   hydrate. A quantity is the total that should be on the shelf, so a wrong
   number is fixed by uploading the file again. **This option disappears at the
   branch's first sale**; after that stock moves only through a request somebody
   at the branch confirms. Both files need a connection.
   *(shipped — Phase M13.5.)*

The business then simply powers on a ready device and logs in as the Owner
created during provisioning. The credentials JSON is one-time: the wizard offers
to delete it after a successful import (checked by default) and says so either
way — it holds a live database password in plaintext, and the device now has its
own encrypted copy. Admin/service credentials never leave `console/.env`. See
[`MULTIBRANCH-BRAINSTORM.md`](MULTIBRANCH-BRAINSTORM.md) §2, §9, §16.

```bash
# The setup wizard end to end against the real Supabase project: provisions a
# throwaway business, hydrates a device from its JSON, then drops the schema and
# role. Needs console/.env, so it is NOT part of `composer verify`.
composer verify:hydrate
```

## Two-branch manual rig

The same flow, rehearsed on your own machine. `composer rig` provisions a **real
throwaway business** with two branches on the live Supabase project, seeds a catalog
with different stock per branch, hydrates two local SQLite databases, and serves them
as two tills:

| Branch | URL | Local database |
|---|---|---|
| **MNL** (Manila) | `http://127.0.0.1:8131` | `tests/manual/.runtime/branch-a.sqlite` |
| **CEB** (Cebu) | `http://127.0.0.2:8132` | `tests/manual/.runtime/branch-b.sqlite` |

Sign in on either as **`owner` / `password`** — the same account, because it is one
business. It runs in the **foreground**, and **Ctrl-C destroys everything it made**:
both servers, both SQLite files, both credentials JSONs, the Postgres schema and both
branch roles.

This is a **perception tool, not a gate** — CI never runs it and it asserts nothing.
Correctness belongs to `composer verify`, `verify:hydrate` and `verify:console`; the rig
exists so two-branch behaviour — sync, cross-branch stock updates, the consolidated
report, branch-prefixed receipt serials — can be *looked at* without building an
AppImage for every look.

Three modes:

```bash
composer rig          # Both tills hydrated, ready to sell
composer rig:wizard   # Stops before hydrating: both devices open on /setup, and the
                      # banner prints the two credentials JSON paths for you to type in
composer rig:clean    # Destroy what a run killed before its Ctrl-C teardown left behind
```

`rig:wizard` is the only way to meet the setup wizard as a user does. The banner prints
the JSON paths **absolute** on purpose: the wizard takes a filesystem path, not an
upload, and the **Browse** button exists only in the desktop runtime. Leave "delete the
file after import" unticked and you can retry without re-provisioning.

**Before the first run** you need `npm run build` (the rig serves a production build),
`(cd console && composer install)`, a `console/.env` carrying `CONSOLE_DB_*`, the
`pdo_pgsql` extension, no cached config (`php artisan config:clear`), and ports
8131/8132 free. All of it is checked in **preflight, before the first Supabase call** —
provisioning costs ~15s and creates real objects, so nothing knowable locally should be
discovered after it. The cached-config check is the load-bearing one: a cached config
ignores `DB_DATABASE`, which would quietly point both tills at
`database/nativephp.sqlite` — your real dev database — and teardown would then delete
it.

The two tills sit on **two loopback hosts rather than two ports** because the
`XSRF-TOKEN` cookie name is hardcoded in the framework and cookies are scoped by host,
not by port: two ports on `127.0.0.1` would overwrite each other's CSRF token and turn
every form submit into a silent "Your session expired" bounce. All of `127.0.0.0/8` is
local, so `127.0.0.2` needs no `/etc/hosts` entry.

If a run dies before its teardown can fire, `composer rig:clean` finishes the job. It
reads `tests/manual/.runtime/state.env` — written incrementally as each artifact is
created, so it is accurate even after `kill -9` — and destroys exactly what that one run
made, nothing global. Should the Postgres drop fail it **keeps** the state file and both
credentials JSONs and prints the SQL to run by hand: a `biz_%` schema is
indistinguishable from a real customer's, so there is no reaper (`console/README.md` §5),
and deleting those is the only way to lose a schema name for good.

Two details of that make the difference between a leak you can clean and one you cannot,
and both were learned the hard way:

- **The schema name is recorded before the schema exists.** The rig mints the business
  UUID itself and passes `--business`, so `state.env` names the schema *before* the ~15s
  `business:provision` call rather than after. It used to be read out of the credentials
  JSON, which is not readable until that call returns — so a Ctrl-C during provisioning
  skipped the drop, deleted the JSON, and printed "nothing left in Postgres" over a live
  schema that nothing could then find. A signal kills the console at PHP's default
  disposition, so its own rollback never runs.
- **Branch roles are discovered, not just remembered.** `DROP SCHEMA … CASCADE` takes a
  role's grants but never the role, so teardown reads the schema's own `branches` rows
  and derives every `branch_<hex>` from them. That is exact rather than a guess: both
  provisioning paths insert the row before creating the role.

If `state.env` is gone entirely, `composer rig:clean` asks Postgres instead —
`php tests/manual/pg.php orphans` lists every `biz_` schema that never finished
provisioning (no completed audit row, no `branches` row, no `schema_meta`) with the drop
line for each. It **only prints**. Run it while a suite or another rig is going and you
will see healthy schemas mid-build under the same flag, which is exactly why it does not
act on its own.

Two things to know while using it:

- **Never run `php artisan inventory:reconcile` against a rig database.** It rebuilds
  stock from the local ledger, and a hydrated till has stock snapshots with no ledger
  behind them — it would zero every till.
- **Read results from the SQLite files directly** (`sync_outbox`, `sync_cursor`,
  `sync_status`). That is where a failed cycle explains itself.

The script's header comment is the authority on *why* it is built this way:
[`tests/manual/rig.sh`](tests/manual/rig.sh).

## Architecture & invariants

A few non-negotiable rules shape the entire codebase — violating them is a correctness bug, not a style choice:

- **Immutable business history** — historical transactions, transaction items, captured prices/costs, the inventory ledger, audit logs, and login history are append-only and never edited.
- **Never delete business records** — categories, items, and variants are **archived**; users are **soft-deleted**. Archived entities stay visible in all historical records.
- **Inventory is ledger-derived** — there is no stock-adjustment table; every movement (sales, returns, defects, manual adjustments, CSV imports) is a ledger entry. Stock never goes negative.
- **UUID primary keys everywhere** — human-readable transaction numbers are a separate value.
- **Permissions enforced at every layer** — exactly one Owner (never archivable/deletable); item cost is Owner-only.
- **Printing never rolls back a sale** — the sale commits, the user sees a clear error, and the receipt can be reprinted.

Deeper documentation lives in the repo:

**How it works, and how to work on it:**

- [`docs/sync-architecture.md`](docs/sync-architecture.md) — the multi-branch machinery **as built**: hydration, the four-step sync cycle, online writes and reads, pending stock adjustments, the version gate, monitoring, the grant matrix and the risk it accepts
- [`docs/developer-workflows.md`](docs/developer-workflows.md) — every command a developer runs, what each gate proves, how to inspect a device, and the traps that produce a wrong reading
- [`docs/glossary.md`](docs/glossary.md) — **normative** on terminology; outranks every other document on what a word means

**Specs and plans:**

- [`BRAINSTORM.md`](BRAINSTORM.md) — full functional specification
- [`ROADMAP.md`](ROADMAP.md) — phased build plan
- [`CLAUDE.md`](CLAUDE.md) — invariants and working guidance
- [`docs/`](docs/) — after-sales and receipt-printing designs of record, which outrank the specs where they disagree
- [`MULTIBRANCH-BRAINSTORM.md`](MULTIBRANCH-BRAINSTORM.md) — Evitan Plus (multi-branch, provisioning, sync) spec of record
- [`MULTIBRANCH-ROADMAP.md`](MULTIBRANCH-ROADMAP.md) — Evitan Plus phased build plan (M0–M12)
- [`console/README.md`](console/README.md) — the Provisioning Console operator runbook

## Project status

**Evitan POS shipped as `v1.0.0`** (see [`RELEASE-NOTES.md`](RELEASE-NOTES.md)) with all 19 [`ROADMAP.md`](ROADMAP.md) phases complete: the full commerce chain (Setup, DB schema, Auth, Authorization, Settings, Inventory, Ledger, CSV Import/Export, POS, Transactions, Returns/Defects, Printing, Reports), the full admin/ops track (User Management, Login History, Audit Logs), UI polish, the Phase 18 final review (DB-level immutability triggers) and release preparation.

**Evitan Plus** has since taken that through `M0–M13.5` of the [`MULTIBRANCH-ROADMAP.md`](MULTIBRANCH-ROADMAP.md) initiative: the Provisioning Console, per-business Postgres schemas and least-privilege branch roles, the connect-and-hydrate setup wizard, the four-step sync cycle (push, pull, heartbeat), atomic online writes for shared data, the schema version gate, branch monitoring, consolidated cross-branch reports and login history, the removal of Database Backup in favour of rebuilding a device from its credential, human-confirmed stock adjustments with branch-to-branch transfers, the Items/Inventory split with stock batches, and spreadsheet opening stock for a branch that has not traded yet.

The suite currently runs `composer verify` offline plus 7 Playwright E2E spec files, with `composer verify:console` and `composer verify:hydrate` covering the Supabase-dependent halves.

> **Upgrading to the schema-21 build.** `M11.7` raised `MIN_APP_VERSION` to `1.1.0` — the first raise in the project. **Migrate the schema before installing the build**, and run `schema:reapply-grants`: an older build still runs the sync step that auto-approved pending adjustments, and stamping the floor first freezes those tills before they can reach it. They keep selling offline throughout.

This summary is approximate — confirm current progress against `git log` and the code. [`docs/sync-architecture.md`](docs/sync-architecture.md) is the as-built account of the multi-branch machinery.

## License

This codebase is **private** and not licensed for redistribution. It is derived from the [Laravel + React starter kit](https://laravel.com/docs/starter-kits), which is open-source under the MIT license.
