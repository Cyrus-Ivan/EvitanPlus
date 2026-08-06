# Evitan Plus — developer workflows

> **Scope.** Every command a developer actually runs on this project, what each one
> proves, and the traps that make one of them lie. Setup and release live in
> [`../README.md`](../README.md); how the system *works* is
> [`sync-architecture.md`](sync-architecture.md); what the words mean is
> [`glossary.md`](glossary.md), which is normative.

---

## 1. Running the app

```bash
composer dev          # Laravel server + queue + logs + Vite, all at once (web dev)
composer native:dev   # NativePHP (Electron) shell + Vite (desktop dev)
```

Dev login is **`owner` / `password`**, created by the local demo seed
(`php artisan migrate:fresh --seed`), which also writes a fake `DEV` branch identity so
the app is not funnelled to the setup wizard. A packaged fresh install has neither: it
opens the wizard, imports a branch credential and hydrates from Postgres.

Two ways to look at the *product* rather than the code:

- **[`composer rig`](../README.md#two-branch-manual-rig)** — two branches of a real
  throwaway business, in two browsers. The only way to see cross-branch behaviour without
  building an AppImage. Full documentation in the README.
- **the `verify` skill** (`.claude/skills/verify/`) — drives **one** device headlessly
  over raw CDP against a *copy* of the dev SQLite. Cheaper than the rig and safe for
  checkout (the transaction serial is gapless and permanent — never check out against the
  real database).

---

## 2. The three gates

They are separate because they need different things, and collapsing them would make the
cheapest one unrunnable.

| Gate | Needs | Covers |
|---|---|---|
| `composer verify` | nothing but network for `composer audit` | The whole app, offline and credential-free |
| `composer verify:console` | `console/.env` + live Supabase admin credentials | The provisioning console's whole suite, plus schema parity |
| `composer verify:hydrate` | the same credentials | The real round trip: provision a throwaway business, hydrate a device from it, push, pull, queue, report |

### `composer verify`

Chains fail-fast, cheapest first:

```
composer audit → pint --test → phpstan → php artisan test --exclude-group=supabase
  → npm run types → npm run lint:check → npm run format:check → npm run e2e
```

The audit ignores *abandoned* packages — `nativephp/electron` and `nativephp/laravel` are
deliberate pins, not oversights. `npm run e2e` is `vite build && playwright test`, so it
builds the frontend itself. The M0 build gates (`BuildExclusionTest` — no console code or
admin credentials in a build; `PhpBinaryTest` — the shipped runtime carries both
`pdo_sqlite` and `pdo_pgsql`) run inside `php artisan test`.

**This gate must stay runnable offline and without admin credentials.** That constraint is
why the other two exist.

### `composer verify:console`

```
console/vendor/bin/phpunit -c console/phpunit.xml --fail-on-skipped
```

`--fail-on-skipped` is an **honesty guard**, not a preference: `Tests\TestCase` skips
silently without credentials, so without the flag a misconfigured `console/.env` exits 0
having tested nothing. That is exactly how `SchemaParityTest` sat unrun from M1 to M4.

Needs `(cd console && composer install)` first.

### `composer verify:hydrate`

```
php artisan test --group=supabase --fail-on-skipped
```

The `tests/Integration/` suite: hydration, push, pull, the inventory queue, online writes
and the consolidated report — each against a business it provisions on the live project and
drops afterwards. Some arithmetic is **only** provable here: the consolidated report's
Postgres dialect handling cannot run against the SQLite stand-in at all.

---

## 3. The test suites, and what each cannot see

| Suite | Command | Blind to |
|---|---|---|
| Feature / Unit | `php artisan test` | Anything requiring real Postgres |
| Integration | `composer verify:hydrate` | The browser |
| E2E (Playwright) | `npm run e2e` | Postgres — the device is un-hydrated or seeded |
| Console | `composer verify:console` | The app |

**E2E database isolation lives in `tests/e2e/serve.sh`**, not in globalSetup — Playwright
starts the web server *before* globalSetup runs. Two servers, two databases:

- **8123, `full`** — migrate + seed + e2e fixtures. What the main specs run against.
- **8124, `fresh`** — migrate only, so there is no `instance` row and `RequireInitialSetup`
  funnels the app to `/setup`. Used by `setup.spec.ts`, which cannot share a database with
  a seeded server.

The e2e database is **built, never copied** from `database/nativephp.sqlite`: the dev DB's
contents are whatever you last did, and it is not even guaranteed to hold an Owner —
users arrive by hydration, and there is no seeded production account. Inheriting it is
what silently broke that suite once.

---

## 4. The provisioning console

Developer-only, never shipped. Drive it from the interactive menu — it is also the default
command:

```bash
php console/artisan                      # the menu over everything below
php console/artisan console:ping         # verify both Supabase endpoints (admin + branch)
php console/artisan console:migrate      # create evitan_console, the provisioning audit schema
php console/artisan business:provision   # schema + migrations + Owner + first branch + role + JSON
php console/artisan branch:provision     # add a branch to an existing business
php console/artisan branch:list          # read-only, unaudited
php console/artisan branch:rotate        # …disable / enable / revoke — the credential lifecycle
php console/artisan schema:migrate       # advance a business schema; --all for every biz_*
php console/artisan schema:reapply-grants
php console/artisan monitor              # every branch's heartbeat; no target = every business
```

Every `branch:*` command targets a business with `--business=<uuid>` or `--schema=<name>`
and a branch with `--branch=<uuid>` or `--branch-code=<code>`.

**[`../console/README.md`](../console/README.md) is the operator runbook** — routine
operations, the rotation warning, troubleshooting, and the audit trail. Read §4 before
rotating anything.

Two things worth knowing before you touch a real business:

- **A raised floor freezes branches.** `schema:migrate` re-applies the grant matrix to
  every schema it *advances*, and says which app builds a raised floor has just frozen —
  but which *tills* are on them needs `monitor`. See
  [`sync-architecture.md`](sync-architecture.md) §9–§11.
- **There is no reaper.** A `biz_%` schema is indistinguishable from a real customer's, so
  nothing sweeps by name pattern. Leaked test schemas are cleaned by hand
  (`console/README.md` §5).

---

## 5. Inspecting a device

Sync explains itself in three local tables. Query the SQLite file directly:

```bash
sqlite3 database/nativephp.sqlite \
  "select connection_state, last_error_kind, last_error, blocked_since,
          last_probe_at, last_sync_at from sync_status;"
```

| Table | Answers |
|---|---|
| `sync_status` | why this device is or is not syncing, and since when |
| `sync_outbox` | what it still owes Postgres (`where synced_at is null` **is** the unsynced count) |
| `sync_cursor` | how far down each pulled table it has read |
| `instance` | which branch this is, and whether it is hydrated (`hydrated_at`) |

Reading `connection_state`:

| Value | Means | Remedy |
|---|---|---|
| `online` | Reached Postgres and finished — **unless** `last_error_kind = 'conflict'`, which means online and **stuck** | For a conflict: look at the error. A 42501 is a missing grant → `schema:reapply-grants` |
| `offline` | Never got there | Wait |
| `unauthorized` | The console disabled, rotated or revoked this credential | Call the developer |
| `version_blocked` | This build is below the schema's floor | Install a newer build |

For the Postgres side, parse `console/.env` as plain text and open a raw PDO — exactly as
`tests/manual/pg.php` does. **Never reference console code from the app** (§18).

### Rebuilding a device

There is no in-app repair or re-hydrate: once `instance.hydrated_at` is set,
`RequireInitialSetup` sends `/setup` to login unconditionally. To rebuild a till — dead
hardware, or a local database that has gone bad — **delete the SQLite file and relaunch**:

```bash
rm database/nativephp.sqlite      # packaged: the app's data directory
```

`LaunchProvisioner` re-runs the migrations at launch, finds no `instance` row, and the setup
wizard runs exactly as it does on a fresh install. You need that branch's **credentials
JSON** again — `branch:provision --force` re-issues it (which rotates the password; see the
pooler caching note in §6).

Two things do not come back, and neither is a bug:

- the **printer configuration** — device-local, no upstream row, re-collected in wizard step 2;
- any **unpushed outbox backlog** — see `docs/sync-architecture.md` §2.

Everything else does, including the branch's transactions, returns, ledger, login history,
audit logs and Upload History (M11.6).

---

## 6. Traps that produce a wrong reading

Each of these has cost real time on this project.

- **A cached config ignores `DB_DATABASE`.** Anything pointing a throwaway database at the
  app will quietly run against `database/nativephp.sqlite` instead. `composer rig`
  preflights for it; nothing else does. `php artisan config:clear`.
- **`php artisan serve` drops env overrides** — its child process does not inherit
  `DB_DATABASE` / `APP_DEBUG`. Run `php -S` from `public/` with the framework's
  `server.php`, as `serve.sh`, the rig and the verify skill all do.
- **`php artisan inventory:reconcile` is safe on a hydrated till as of M11.6, and was
  not before.** It rebuilds snapshots from the *local* ledger; hydration used to bring
  snapshots down with no ledger behind them, so a rebuild zeroed every variant — and
  `LaunchProvisioner` runs it on every launch. Hydration now copies the branch's ledger
  too, and upstream a snapshot is *defined* as its ledger sum
  (`RemoteStock::recompute()`), so the rebuild reproduces what it found.
- **Feature tests render through the built Vite manifest.** After adding a page, run
  `npm run build` or tests fail with "Unable to locate file in Vite manifest".
- **`pkill -f <pattern>` matches your own shell's command line** and kills the compound
  command running it. Kill by port: `fuser -k 8123/tcp`.
- **A green console suite may have tested nothing** — see `--fail-on-skipped` above.
- **The console's `.env` loses to the app's** in any subprocess. `console/config/app.php`
  reads `env('APP_TIMEZONE', 'UTC')` and the app's `.env` sets `Asia/Manila`, so a console
  command launched from inside an app process inherits the app's value and stamps its
  audit rows eight hours ahead. Run console commands from their own process.

---

## 7. Where to look next

- [`../README.md`](../README.md) — setup, the rig, building a release, standing up a branch.
- [`sync-architecture.md`](sync-architecture.md) — how the sync machinery works, as built.
- [`glossary.md`](glossary.md) — **normative** on terminology.
- [`../console/README.md`](../console/README.md) — the console operator runbook.
- [`phase-18-findings.md`](phase-18-findings.md) — the v1.0.0 final-review audit.
- [`receipt-printing-design.md`](receipt-printing-design.md),
  [`returns-refunds-voids-design.md`](returns-refunds-voids-design.md) — designs of record
  for their areas; they outrank the specs where they disagree.
