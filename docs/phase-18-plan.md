# Phase 18 — Final Review Plan


> **Superseded in part (M11.6).** The **Database Backup** feature this plan
> scopes was removed from Evitan Plus, so its backup/restore review items — the
> F-08 pre-copy note, the old-backup serial scenario, "Backup/restore edges",
> the backup E2E surface and the matching checklist boxes — cover code that no
> longer exists. Left as written because this is a dated record of how the phase
> was run, not a description of the current app; a branch device is now rebuilt
> through the setup wizard (`docs/sync-architecture.md` §2).

Phase 18 is the **final review gate before production**, not just a testing pass. It has five
jobs: expand test coverage over the invariants, **challenge the spec's assumptions**, review
**security**, review **accuracy** (money math, report correctness), and review **code quality**
(duplication, structure, dead code — how the code is written, not just what it does). Exit
means the MVP is declared feature-complete and Phase 19 (Release Preparation) receives a
written handoff list.

This plan is the working document for the whole phase. Sessions update the
[status tracker](#5-status-tracker) at the bottom; findings go to `docs/phase-18-findings.md`.

---

## 1. Operating rules

**Session protocol (Claude Pro–constrained).** Work happens in discrete sessions, each sized to
fit one Pro usage window and each independently committable, so a usage cutoff never strands
half-done work.

Every session:

1. **Starts** by reading this doc's status tracker, then confirming a green baseline:
   `php artisan test` (and, once S6 lands, `npx playwright test`).
2. **Works** only its own scope (below). Keep the session cheap: no subagents, run targeted
   test filters (`php artisan test --filter=...`) mid-session, full suite only at the end.
3. **Ends** with: full suite green → tracker checkboxes ticked in this doc → one commit that
   includes the tracker update. If the usage limit hits mid-session, commit whatever sub-items
   are complete and tick only those; the next session resumes from the tracker.

**Fix policy (decided up front).**

- **S1–S4 are review-only.** They write findings into the log — never product code. (S4 alone
  may add *analysis tooling* — dev-dependencies and config — since those are its instruments.)
  Resist the urge to fix "just this one small thing"; log it.
- **S5–S7 write tests and tooling that pass against current code.** A test that *should* exist
  but would fail today is not written yet — it becomes a finding, and the test lands together
  with its fix in S8.
- **S8 consumes the findings log** in severity order. **S9 closes the phase.**

**Invariants under review** are the ones in `CLAUDE.md` / `BRAINSTORM.md`: immutable business
history, append-only ledger and audit logs, never-delete business records, ledger-derived
stock, no negative inventory, gapless BIR-shaped transaction serial, single Owner, Owner-only
cost, print-failure-never-cancels-sale, reports recompute from source.

---

## 2. Findings log

`docs/phase-18-findings.md`, created at the start of S1 by copying in the
[seed findings](#3-seed-findings) below. One table, one row per finding:

| Field | Meaning |
| --- | --- |
| ID | `F-01`, `F-02`, … never reused |
| Area | Invariants / Security / Accuracy / Code-quality / Tooling / Frontend |
| Severity | **Blocker** (must fix before release) / **High** / **Medium** / **Low** / **Accepted-risk** |
| Description | What is wrong or unproven, one or two sentences |
| Evidence | `file:line` references |
| Proposed fix | Sketch, enough for S8 to act without re-research |
| Status | Open / Fixed@`<commit>` / Accepted (with one-line rationale) |

An **Accepted-risk** entry is a legitimate outcome — the point of challenging assumptions is a
decision, not necessarily a change. Accepted entries must say *why* (e.g. "Owner-only, local
desktop context").

---

## 3. Seed findings

Copy these into the findings log at S1 start (severity to be confirmed during S1–S4 review).
They came out of the pre-planning exploration — do **not** spend session time re-deriving them.

| ID | Area | Finding | Evidence |
| --- | --- | --- | --- |
| F-01 | Invariants | `Transaction`, `TransactionItem`, `InventoryLedger`, `SaleReturn`, `SaleReturnItem`, `LoginHistory` have **no model-level immutability guard** — unlike `AuditLog`, which throws on update/delete. Immutability is by convention only, and untested. | `app/Models/AuditLog.php:28-37` (the pattern); the six models lacking it |
| F-02 | Invariants | Transaction serial generator is count-based with a UNIQUE-index retry loop; only 2 tests exist — none proving no gap after a failed/rolled-back checkout, none exercising the collision-retry path. | `app/Support/Sales/TransactionNumberGenerator.php:34`, `app/Http/Controllers/PosController.php:130-141` |
| F-03 | Security | Audit-log payload **cost redaction** for non-Owners has no test. | `app/Http/Controllers/AuditLogController.php:80-97` |
| F-04 | Security | Printer `device_path` and `network_host` are Owner-set unconstrained free text → arbitrary-local-file-write / SSRF-shaped edge. Decide: constrain (e.g. `/dev/` prefix allowlist) or Accepted-risk (Owner-only, desktop). | `app/Support/Printing/RawDeviceConnector.php`, `NetworkConnector.php`, `PrinterSettingsUpdateRequest.php` |
| F-05 | Security | Working `.env` ships `APP_DEBUG=true`; friendly error pages only render when `!app.debug`, so a packaged build with debug on leaks stack traces. Phase 19 handoff item: the build must pin `APP_DEBUG=false`, `APP_ENV=production`. | `bootstrap/app.php:54`, `.env:4` |
| F-06 | Tooling | No `tsc --noEmit` gate, no JS test runner, and `npm run lint` runs eslint with `--fix` (mutating, not a check). | `package.json` scripts |
| F-07 | Accuracy | Money columns are `decimal(12,2)` — on SQLite that is NUMERIC affinity (REAL floats under the hood). Cent-exactness of discount apportionment, refund shares, and report sums needs an explicit audit. | `database/migrations/*_create_transactions_table.php`, `*_create_variants_table.php` |
| F-08 | Security | Cleared surfaces (for the record, so S2 doesn't re-sweep): no shell interpolation (`SystemPrinterConnector` uses argv arrays), all raw SQL parameter-bound, no `$guarded = []`, all routes middleware-covered, CSV import validation solid (mimes/size/UTF-8/cell caps), backup restore checks SQLite magic + blocks path traversal. | exploration pass, 2026-07-17 |

---

## 4. Sessions

### Part A — Review (findings only, no product code)

#### S1 — Invariant & integrity review

Create `docs/phase-18-findings.md` from the seed table, then audit:

- **Immutability enforcement** for every append-only table (`transactions`,
  `transaction_items`, `inventory_ledger`, `sale_returns`, `sale_return_items`,
  `login_history`, `audit_logs`) against the `AuditLog::booted()` guard pattern. Decide the
  enforcement shape for S8 (model guard vs. also SQLite triggers).
- **Serial integrity**: walk `TransactionNumberGenerator` + the `PosController` retry loop for
  gap scenarios — rollback, crash between reserve and commit, two terminals (future), restore
  of an older backup over a newer DB (does the serial regress?).
- **Ledger ↔ snapshot drift**: enumerate every code path that touches `stock_snapshots` or
  `inventory_ledger`; confirm all funnel through `StockLedger::record()`; check what happens if
  snapshot and ledger-sum disagree (who wins, is there a reconcile?).
- **Bypass search**: grep for direct writes (`update`, `save`, `DB::table`) to stock, ledger,
  transaction, or audit tables outside their single write paths.

**Exit:** findings log exists, every item above has a finding or an explicit "clean" note.

#### S2 — Security review

- Run the built-in **`/security-review`** skill over the codebase; triage its output into the
  findings log (dedupe against F-01…F-08).
- **Authz matrix**: build the full route × role table from `routes/web.php` (+ `auth.php`,
  `settings.php`) and verify each cell against the spec (Cashier: POS/Sales/view-Inventory/own
  Login History; Admin: all but cost + Cashier-only user mgmt; Owner: everything). Note any
  route protected only in the controller/FormRequest, not middleware.
- **Cost-visibility sweep**: list every response surface that can carry cost (inventory pages,
  POS payload, sales history/detail, returns detail, reports, CSV export, audit payloads,
  Inertia shared props) and confirm each has a hiding mechanism *and* a test; missing tests →
  findings for S5/S8.
- **CSV export formula injection**: check whether item/category/variant names beginning with
  `=`, `+`, `-`, `@` are escaped on export (spreadsheet apps execute them).
- **Backup/restore edges**: restore of a valid-but-foreign SQLite file (another app's DB),
  restore of an old backup (serial regression ties into S1), backup file permissions.
- **Packaged-build config review**: `APP_DEBUG` / `APP_ENV` (F-05), session driver under
  NativePHP, cookie flags, `APP_KEY` generation on install → write the Phase 19 handoff list.
- **Decide F-04** (printer paths): constrain or accept, with rationale.

**Exit:** matrix documented (in the findings doc or appendix), all decisions logged.

#### S3 — Accuracy & assumptions review

- **Money-path audit (F-07)**: trace every arithmetic path — line totals, transaction discount
  (amount + percentage), refund apportionment in `ProcessSaleReturn`, report aggregations
  (`selectRaw` sums), change computation — and determine where floats can drift. Hand-compute
  3–4 adversarial fixtures (e.g. 3-way split of a ₱100 discount over unequal lines; 0.1+0.2
  shapes; % discount with sub-cent result) for S5 tests.
- **Report cross-check**: verify net = gross − returns − defects attribution rules (event-date
  vs sale-date), day/week/month boundary handling and timezone source, Owner-only
  profit/uncosted split.
- **Assumption challenge** — each line gets a finding or an Accepted-risk entry:
  - Crash detection fails **open** (no boot id → session allowed) — right default?
  - Single-session enforcement: what happens on simultaneous logins mid-race?
  - Count-based serial (`count()+1`) vs `MAX(number)+1` — behavior if a row is ever
    hard-deleted by hand/restore.
  - Snapshot as cache: can UI ever show stale stock and oversell within the lock window?
  - SQLite durability: journal mode, `synchronous`, power-loss mid-checkout — what state
    results? (Feeds Phase 19's "SQLite Optimization".)
  - Float money end-to-end (PHP casts return strings; where does arithmetic actually happen?).
  - Unnamed-variant fallback: any surface that renders blank instead of the item name?

**Exit:** every assumption above has a logged disposition; S5 fixture values written down.

#### S4 — Code-quality review (how the code is written)

Review-only like S1–S3, with one exception: this session may add **analysis tooling**
(dev-dependencies + config files only — never product code) as its instruments.

- **Duplication scan**: run `npx jscpd` over `app/` and `resources/js/` (tune `--min-tokens`
  until the report is signal, not noise). Triage clusters into findings. Likely candidates:
  controller index boilerplate (search/filter/paginate/sort), Form Request patterns, React
  table/pagination/dialog/form scaffolding repeated across pages.
- **Static analysis**: wire **Larastan** (`composer require --dev larastan/larastan`,
  `phpstan.neon` at a pragmatic level — start ~5 and baseline the rest). Real defects it finds
  → findings under their proper Area; pure noise → baseline file.
- **Structure pass** (manual, both sides of the stack):
  - PHP: fat-controller check against the `app/Support/*` service layer — logic that belongs
    in a service; repeated query fragments vs existing helpers (`app/Support/Search/LikeQuery`);
    consistency of Form Request usage, Gate vs Policy vs middleware idioms.
  - React/TS: are model types (Item, Variant, Transaction…) declared once and shared, or
    redeclared per page? Repeated fetch/form/dialog wiring that wants a shared hook or
    component; oversized page components; prop-drilling vs context where it hurts.
- **Dead code**: `npx knip` (or manual sweep) for unused exports/components/files on the TS
  side; unused PHP classes, routes, and leftover starter-kit code.
- Duplication findings must name the **proposed extraction** (the helper/component/service to
  create) and are only worth Medium+ severity where copies can *diverge dangerously* (e.g. two
  places computing money or checking permissions) — cosmetic duplication is Low.

**Exit:** duplication report triaged, Larastan wired with a committed baseline, structure and
dead-code findings logged with proposed extractions.

### Part B — Test & tooling expansion (code = tests/config only)

#### S5 — Backend test expansion

Write tests that should pass **today** (failures → convert to findings, park the test for S8):

- Audit-payload cost redaction for Admin/Cashier (F-03).
- Serial gaplessness: failed checkout (oversell, validation error) leaves no gap; next sale
  gets the next number; collision-retry path (unique-violation → retry succeeds).
- Money fixtures from S3 asserted cent-exact end-to-end (checkout → return → report).
- Archived-records visibility sweep: archived item/variant/user still render in sales history,
  returns, audit logs, reports.
- Ledger/snapshot reconciliation: snapshot always equals ledger sum after each flow (sale,
  return, defect, adjustment, import).
- CSV import edges from S2/S3 not already covered (formula-injection names round-tripping,
  weird-but-valid UTF-8).
- Remove/replace the starter stub `tests/Unit/ExampleTest.php`.

**Exit:** suite green, count meaningfully up from 364, no invariant from §1 without either a
test or an open finding.

#### S6 — E2E bootstrap (Playwright)

- Add `@playwright/test` as a devDependency (Chromium only). Config lifted from the proven
  `verify` skill recipe (`.claude/skills/verify/SKILL.md`):
  - **Never the real DB**: global setup copies `database/nativephp.sqlite` to a temp path and
    seeds per-role users (owner/admin/cashier) — the gapless serial makes real-DB checkouts
    permanent.
  - `webServer`: `php -S 127.0.0.1:8123` from `public/` via `server.php` with env
    `DB_DATABASE=<copy>`, `APP_DEBUG=false` (not `artisan serve` — it drops env overrides).
  - `npm run build` before the run (server uses the built manifest).
- Wire quality scripts in `package.json`: `types` → `tsc --noEmit`, `lint:check` → eslint
  without `--fix` (F-06). Type errors found → findings, not fixes.
- Smoke specs: login → lands on POS; product grid renders; a checkout completes and shows the
  success state; logout.

**Exit:** `npx playwright test` green locally and documented in this doc's tracker;
`npm run types` runs (its failures logged).

#### S7 — E2E workflows

- **Commerce chain**: sell (with discount) → partial return → defect → open Reports and assert
  the recomputed numbers match the fixture arithmetic.
- **Role gating in the DOM**: as Cashier and Admin, assert cost never appears anywhere
  (inventory, POS, sales detail, reports); nav hides forbidden pages; direct URL → 403 page.
- **Error pages**: 404 (`/definitely-missing`) and 403 render the styled Inertia error page.
- **Ops surfaces**: backup page creates a backup; reprint from sales detail shows the flow
  (print failure path is backend-tested; E2E asserts the UI error toast, not the device).
- Reuse the verify-skill gotchas: poll for selectors (never fixed sleeps), toasts are
  `[data-sonner-toast]`, dialogs `[role="dialog"][data-state="open"]`, kill the server by port.

**Exit:** E2E suite covers the top user journeys per role; tracker updated.

### Part C — Fix & close

#### S8 — Fix sessions (S8a, S8b, … repeat until the log is clear)

Work the findings log **in severity order**. Each fix lands with its test in the same commit —
this is where the parked tests from S5 and the immutability guards (F-01) land. Code-quality
findings from S4 are fixed here too (extractions land with the call-sites migrated and Larastan
still green). Before each sub-session's commit, run the diff-based **`/code-review`** skill on
the working tree as a self-check — fixes must not introduce the very patterns S4 flagged.
Every sub-session is independently committable; update finding statuses (`Fixed@<commit>`) as
you go. Anything downgraded to Accepted-risk gets its rationale written in the log.

**Exit:** no Open findings above Low; remaining Lows either fixed or explicitly accepted.

#### S9 — Final sweep & exit

- Full gates, all green: `php artisan test` · `npx playwright test` · `npm run types` ·
  `npm run lint:check` · `./vendor/bin/pint --test` · `./vendor/bin/phpstan analyse`.
- Re-run `/security-review` to confirm nothing regressed during S8.
- Write the **Phase 19 handoff list** at the top of the findings doc: build must set
  `APP_DEBUG=false`/`APP_ENV=production`, printer accepted-risks, SQLite optimization notes
  from S3 (journal mode / synchronous), default Owner credential handling, anything else S1–S8
  deferred to packaging.
- Mark Phase 18 ✅ in `ROADMAP.md` and update the status paragraph in `CLAUDE.md`.

**Exit:** milestone "Feature complete" declared; Phase 19 can start from the handoff list alone.

---

## 5. Status tracker

Tick items **before** committing; a session cut short commits only its ticked items.

- [x] **S1 — Invariant & integrity review** *(2026-07-18 — findings F-09…F-14 added; enforcement shape decided in F-01: model guards + SQLite triggers)*
  - [x] Findings log created with seed findings F-01…F-08
  - [x] Immutability enforcement audited for all 7 append-only tables
  - [x] Serial gap scenarios walked (rollback / crash / restore)
  - [x] Ledger↔snapshot drift + single-write-path bypass search done
- [x] **S2 — Security review** *(2026-07-18 — F-03 closed as stale, F-04 accepted, F-15/F-16/F-17 added)*
  - [x] `/security-review` run and triaged *(skill is diff-based with no product diff pending — equivalent manual pass done; S9 runs the skill over the S5–S8 diff)*
  - [x] Route × role authz matrix documented
  - [x] Cost-visibility surface list complete (each: mechanism + test?)
  - [x] CSV formula-injection checked; backup/restore edges checked
  - [x] F-04 printer decision made; Phase 19 config handoff drafted
- [x] **S3 — Accuracy & assumptions review** *(2026-07-18 — F-07 confirmed sound (tests only), F-14 raised to High incl. receipts, F-18/F-19 added; fixtures A–D written)*
  - [x] Money paths traced; adversarial fixtures written down
  - [x] Report attribution + boundary rules verified
  - [x] All assumption-challenge items dispositioned
- [x] **S4 — Code-quality review** *(2026-07-18 — F-20…F-25 added; Larastan wired at level 5 with 36-entry baseline, zero runtime defects)*
  - [x] jscpd duplication scan run and triaged into findings
  - [x] Larastan wired (config + baseline committed); real issues logged
  - [x] Structure pass done (PHP services + React types/components)
  - [x] Dead-code sweep done; extraction proposals named in findings
- [x] **S5 — Backend test expansion** *(2026-07-18 — 364 → 378 tests; F-26 archived-user visibility found and parked for S8)*
  - [x] Redaction, serial, money-fixture, archived-visibility, reconcile, CSV-edge tests green *(redaction already existed — F-03)*
  - [x] Failing-today tests converted to findings and parked for S8 *(archived-user rows → F-26; collision-retry → with F-09; formula-injection round-trip → with F-16)*
- [x] **S6 — E2E bootstrap** *(2026-07-18 — 3 smoke specs green; note: Playwright starts webServer before globalSetup, so all isolation setup lives in `tests/e2e/serve.sh`)*
  - [x] Playwright installed; isolated-DB webServer config working
  - [x] `types` / `lint:check` scripts wired (failures logged) *(both pass clean — F-06 closed)*
  - [x] Smoke specs green
- [x] **S7 — E2E workflows** *(2026-07-18 — 9 E2E specs green; commerce chain asserts report deltas so accumulated E2E state never skews the arithmetic)*
  - [x] Commerce-chain spec green (numbers match fixtures)
  - [x] Role-gating, error-page, ops-surface specs green
- [x] **S8 — Fix sessions** *(2026-07-18 — five sub-commits 4c8a2d6 / c28b7ee / b626506 / 097fdec / 9933bd5; suite 364 → 396)*
  - [x] All Blocker/High findings closed *(F-01, F-14, F-26 fixed; F-05 is the Phase 19 release gate by design)*
  - [x] All Medium findings closed or accepted *(F-02, F-09, F-13, F-15, F-17, F-20 fixed)*
  - [x] Low findings dispositioned *(F-07/F-11/F-12/F-16/F-18/F-19/F-21/F-23/F-24 fixed; F-10/F-22/F-25 accepted with rationale)*
- [x] **S9 — Final sweep** *(2026-07-18 — phase closed: 396 backend + 9 E2E tests, all gates green)*
  - [x] All six gates green; `/security-review` re-run clean *(no new findings over the full phase diff)*
  - [x] Phase 19 handoff list written *(top of `docs/phase-18-findings.md`)*
  - [x] `ROADMAP.md` / `CLAUDE.md` marked Phase 18 ✅
