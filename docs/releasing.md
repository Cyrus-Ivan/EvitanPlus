# Evitan Plus — releasing an update

> **Scope.** The whole of shipping a new version to branches already running Evitan
> Plus: deciding the version pair, running the gates, migrating every business
> database, building the artifact, carrying it to the tills, and proving the rollout
> finished. How the versions and the gate *work* is
> [`sync-architecture.md`](sync-architecture.md) §9–§10; what the words mean is
> [`glossary.md`](glossary.md) §6, which is normative. This is the order of operations.
>
> **Not in scope.** Standing up a *new* branch is README §"Developer onboarding";
> replacing dead hardware is [`developer-workflows.md`](developer-workflows.md)
> §"Rebuilding a device"; rotating a credential is `console/README.md` §4. Upgrading a
> real **Evitan POS v1.0.0** install is none of these — the two products have different
> application ids and data directories, so a new build does not adopt v1.0.0's data
> (`RELEASE-NOTES.md`, naming preamble). That is a provision-and-rebuild, not an update.

**There is no updater.** It is disabled in [`config/nativephp.php`](../config/nativephp.php)
on purpose: an offline-first app with no release feed would fire a failing update check
on every launch. So every update ends with a person at a till, replacing a binary. Plan
the visit before you plan the build.

---

## 1. Decide the version pair

Everything below follows from one question, so answer it first.

| Your change | `SCHEMA_VERSION` | `MIN_APP_VERSION` | Rollout |
|---|---|---|---|
| App only — no console migration | unchanged | unchanged | Build, then deliver. Any order, over any timespan. Nothing freezes. |
| **Additive** console migration | = the new migration count | unchanged | Migrate, then build, then deliver. Old tills keep syncing throughout; they simply do not write the new column. |
| **Breaking** console migration | = the new migration count | **rises** | Migrate **first**, deliberately freezing every old till, then deliver. |

Both numbers live in exactly one place —
[`console/app/Support/SchemaVersion.php`](../console/app/Support/SchemaVersion.php) — and
only `schema:migrate` ever moves them in a real schema (§4).

**One of the pair is checked; the other is your judgement.**

- `SCHEMA_VERSION` **is** the migration-file count, and
  [`SchemaVersionTest`](../console/tests/Unit/SchemaVersionTest.php) asserts it. Add a
  console migration and the console suite fails until you bump it. There is nothing to
  remember.
- **Whether a migration is breaking cannot be checked by anything.** It is a claim about
  what an *older build's code* does when it meets the new schema, and no test can decide
  it. The tripwire above exists largely to put this question in front of you at the moment
  it arises, because the same edit is where the call has to be made.

Raise the floor only for a breaking change. An additive migration that raises it needlessly
freezes the sync of every branch of every business until each device is physically visited.

> **The worked example — breaking.** Schema 21 (M11.7) is the only raise so far, and the
> reason was not the column rename it also carried. Before 21, a till answered its own
> pending stock adjustments automatically. A 1.0.0 build let loose against a 21 schema still
> runs that step: it auto-approves every adjustment and every CSV import somebody was meant
> to confirm, and accepts a transfer's sending leg without spawning the receiving leg — so
> stock leaves one branch and arrives nowhere, and from the schema's side it looks exactly
> like a branch doing its job. That is what "breaking" means here: not that the old build
> errors, but that it does the wrong thing silently.

> **The worked example — additive, and the one nobody wrote down.** Schema 22 (M11.8) added
> `issued_by_branch_id` to `inventory_update_queue`: one nullable column recording which
> branch a request was issued *from*. A 1.1.0 build against a 22 schema writes null into it
> and renders "Requested by" without a branch name — it tells no lie and misreads nothing, so
> the floor stayed at 1.1.0. Recorded here because until the audit that added this paragraph,
> that judgement existed only in `CLAUDE.md`'s status prose and one docblock — M11.8 had no
> roadmap section at the time, so there was no spec in which it had ever been decided. (It has
> one now; the same audit wrote it. The lesson stands: a version-pair call made in a commit
> message is a call nobody can find.)

> **The worked example — additive**, which is the call you will actually make most often.
> Schema 23 (M13) added `batch_id` to `inventory_update_queue` and `inventory_ledger`, so
> rows issued together can be answered as one card. A 1.1.0 build against a 23 schema writes
> null into it: its Pending Stocks cards do not group, and a transfer batch it approves does
> not carry the batch onto the receiving legs. **Degraded, not wrong** — nothing it writes is
> a lie and nothing it reads is misread — so the floor stays at 1.1.0 and nothing is frozen
> by it. That is the line: ask not "will an old build notice?" but "will an old build do
> something untrue?"

### Does the grant matrix move?

A separate question from the version pair, and easy to answer wrongly in both directions:

- **A new table grants nothing to anybody.** Enumerate it in
  [`BranchGrants`](../console/app/Support/BranchGrants.php) or every branch role holds
  nothing on it — see §4, and the "Sync stuck" symptom it produces.
- **A new column on a table that already has table-level `SELECT`/`INSERT` needs nothing.**
  Those grants cover columns that did not exist when they were made. Schemas 22 and 23 both
  added columns and both changed the matrix not at all.
- **Unless a branch must UPDATE it.** `BranchGrants::UPDATE_COLUMNS` is the one part of the
  matrix enumerated per *column* — the six resolution columns of `inventory_update_queue` —
  and a seventh name in a SET list is a `permission denied` that reads like a missing grant
  and is in fact the boundary being exactly right. `batch_id` is written once, at insert, so
  it stays out.

Widening the matrix at all is a decision that has to state its reason in the phase note
([`glossary.md`](glossary.md) §9 is normative on why: the enumerated matrix is the only
boundary of its kind between branches, and it must not erode).

---

## 2. Make the change

1. **Bump the app version** — [`config/nativephp.php`](../config/nativephp.php), the
   `'version'` line.

   > `SchemaVersionTest` reads that line by **regex** rather than loading the config (§18
   > forbids a runtime reference between the app and the console in either direction).
   > Reformatting it — splitting the `env()` call across lines, switching to double quotes
   > — breaks the guard with "Could not read the app's version default". Change the string,
   > not the shape.

2. **Bump the pair in the console**, if §1 says either number moves. This goes in the
   **same commit** as the app bump: the floor may never exceed the version this repo can
   build, a test asserts it, and a floor above the newest possible build would freeze every
   branch with nothing to install.

3. **If a console migration touches a mirrored table, add the matching SQLite migration**
   in [`database/migrations/`](../database/migrations). The two dialects are hand-written
   separately on purpose;
   [`SchemaParityTest`](../console/tests/Feature/SchemaParityTest.php) is what catches a
   column added to one side and forgotten on the other.

4. **Add the `RELEASE-NOTES.md` entry.** Written now, while you remember what changed.

---

## 3. Run the gates

Three, because they need different things:

```bash
composer verify           # offline, credential-free — the one that must always be green
composer verify:console   # the console suite + schema parity — real Supabase
composer verify:hydrate   # provisions a throwaway business and hydrates a device — real Supabase
```

`composer verify` chains fail-fast, cheapest first: `composer audit` → `pint --test` →
`phpstan` → `php artisan test --exclude-group=supabase` → `npm run types` →
`npm run lint:check` → `npm run format:check` → `npm run e2e` (which builds the frontend
before starting Playwright). The audit step needs network access and ignores *abandoned*
packages — `nativephp/electron` and `nativephp/laravel` are deliberate pins.

**`verify:console` is not optional when a console migration is involved** — it is the only
thing that runs `SchemaParityTest` and the `SCHEMA_VERSION` tripwire.

The two M0 build gates run inside `php artisan test`:
[`BuildExclusionTest`](../tests/Feature/Build/BuildExclusionTest.php) (no console code or
admin credentials can reach a build) and
[`PhpBinaryTest`](../tests/Feature/Build/PhpBinaryTest.php) (the shipped PHP runtime carries
both `pdo_sqlite` and `pdo_pgsql`).

---

## 4. Migrate the business databases

**Before building, always** — so the order is never a decision you make under pressure.
For an additive change the order is merely tidy; for a breaking one it is the whole safety
mechanism, and it reads backwards:

> **Migrate-then-install.** Stamping a raised floor first sends every old till to
> `version_blocked` at step 0 of its cycle, so none of them reaches the code you are
> replacing during the rollout window. They keep selling offline throughout, and their
> backlog flushes when they are updated. The other order means new builds writing columns
> into a schema that has not got them.

```bash
php console/artisan schema:version --business=<uuid>   # what a schema is on now
php console/artisan schema:migrate --all --pretend     # what would run, nowhere
php console/artisan schema:migrate --all               # every biz_* schema in the project
```

Applies only what a schema has not seen, so re-running is safe and usually a no-op. A run
that completes does three things beyond the DDL:

1. **Stamps `schema_meta`** with the pair from `SchemaVersion` — only when it changed, so
   `applied_at` keeps meaning "when this schema reached this version", and **never
   backwards**. A schema recording a version above your checkout's was migrated by a newer
   console; stamping it down would un-freeze branches a breaking migration deliberately
   froze. Update the checkout instead.
2. **Re-applies the branch grant matrix to every schema that *advanced*** — that is, where
   at least one pending migration actually ran. This is load-bearing, not bookkeeping:
   grants are enumerated per table on purpose (no `ON ALL TABLES`, no
   `ALTER DEFAULT PRIVILEGES`, so a leaked credential's reach never auto-extends), which
   means a migration adding a table leaves every branch role holding **nothing** on it. The
   symptom at the shop is "Sync stuck" — a 42501 classified as a `conflict`, not anything
   that reads like a permissions error.
3. **Reports the transition**, and names the app builds a raised floor has just frozen.

`--all` targets every `biz_*` schema including half-built ones — deliberately, since
provisioned-ness *is* having the very tables a schema needing migration may lack. It
confirms first unless `--force`, does **not** stop at the first failure, and names what
failed in the summary. Re-running is safe.

**If the re-grant step fails**, the schema is migrated and its branch roles are not, which
is the "Sync stuck" state above. Recovery is exactly what the error prints:

```bash
php console/artisan schema:reapply-grants --schema=<biz_...>
```

No password changes, so no branch needs a new credentials JSON — roles pick it up on their
next connection.

---

## 5. Build and inspect the artifact

> **You must be on PHP 8.5 to cut a release.** NativePHP requests the vendored static PHP
> zip matching the *host* PHP minor, and only `php-8.5.zip` is committed
> ([`build/php/README.md`](../build/php/README.md)).

```bash
php artisan native:build linux
```

Artifacts land in `dist/` as `Evitan Plus-<version>.AppImage` and
`evitan-plus_<version>_amd64.deb`. **Rename the AppImage** to
`evitan-plus-<version>.AppImage` (no spaces) before distributing — there is no script for
this, and the check below assumes the renamed form. `dist/` is git-ignored and accumulates:
`native:build` writes alongside old artifacts rather than replacing them, so confirm you are
about to ship the version you think you are.

Inspect the real artifact before it leaves the machine. Confirm the bundled `.env` carries
no `APP_KEY` / `APP_ENV` / `APP_DEBUG` (stripped via `nativephp.cleanup_env_keys`), that no
`*.sqlite` files or repo internals are inside, that `public/icon.png` is the Evitan mark,
and that **the developer/admin boundary held**:

```bash
./evitan-plus-<version>.AppImage --appimage-extract >/dev/null
# Payload lives at resources/app.asar.unpacked/resources/app; the find keeps
# this robust if electron-builder's layout changes.
APP=$(find squashfs-root -type d -name app -path '*resources*' | head -1)

test ! -e "$APP/console" && echo "OK: no console/" || echo "FAIL: console/ shipped"
ls "$APP"/MULTIBRANCH-*.md >/dev/null 2>&1 \
    && echo "FAIL: design docs shipped" || echo "OK: no design docs"
grep -qE '^(CONSOLE_|BRANCH_DB_|SUPABASE_)' "$APP/.env" \
    && echo "FAIL: admin keys shipped" || echo "OK: no admin env keys"

# The bundled PHP runtime must carry BOTH drivers, or the app cannot read
# its own SQLite database / cannot reach its branch Postgres.
PHP_BIN=$(find squashfs-root -type f -path '*resources/php/php' | head -1)
"$PHP_BIN" -r 'echo implode(",", PDO::getAvailableDrivers()), PHP_EOL;'  # → pgsql,sqlite
rm -rf squashfs-root
```

`BuildExclusionTest` and `PhpBinaryTest` gate the same invariants in `composer verify`;
this is the belt-and-braces check against the thing you are actually shipping.

Finally, smoke-test the installed app with a scratch `HOME` — the setup wizard (import a
branch credentials JSON, optional printer), then a sale.

---

## 6. Deliver it to the tills

An update is: **copy the new AppImage to the device, replace the old one, relaunch.** That
is the entire mechanism.

**A plain update needs no credentials JSON.** The device's data directory
(`~/.config/evitan-plus/`) survives the swap and holds the SQLite database, the per-install
`APP_KEY`, and the branch credential sealed under it. A *new* JSON means a rotation or a
device rebuild, which are different procedures — README §"Branch credentials" and
[`developer-workflows.md`](developer-workflows.md) §"Rebuilding a device".

What the device does on first relaunch, in order:

1. [`LaunchProvisioner`](../app/Support/Boot/LaunchProvisioner.php) runs `migrate --force`
   and `inventory:reconcile` inside the boot request, before the window opens — so any new
   SQLite migration is applied before the first page load touches the database. This exists
   because NativePHP only migrates when the app version changes, which misses a device whose
   database file predates the build now running against it.
2. `RequireInitialSetup` sees `instance.hydrated_at` and does **not** open the wizard. An
   updated device is not a fresh one.
3. The first sync cycle runs `gate → push → pull → heartbeat`: the offline backlog flushes,
   anything the migration added comes down, and the heartbeat reports the new `app_version`
   — which is how you confirm the visit landed without asking anyone.

A till you do not visit keeps selling offline indefinitely. If the floor rose it is frozen
at `version_blocked` and its backlog grows; if it did not, the till syncs happily on the old
build and nothing tells you it is behind except §7.

---

## 7. Confirm the rollout finished

```bash
php console/artisan monitor              # every provisioned business
php console/artisan monitor --business=<uuid>
```

The table is `Code | Branch | App | Schema | Unsynced | Last sync | Last seen | State`.
**Done** means every branch shows the new version in **App**, a fresh **Last seen**, and
zero **Unsynced**. Not "the last AppImage was copied".

Two things make this screen lie if you read it casually:

- **The age of `Last seen` is the signal; the state word is secondary.** A branch can only
  ever self-report `online` or `version_blocked` — `offline` and `unauthorized` are
  diagnosed by a connection *failing to open*, and a branch that cannot connect cannot file
  a report saying so. A till whose router died last Tuesday still reads `online`. The
  monitor colours anything older than 15 minutes (three missed backstop cycles).
- **`schema:migrate` names frozen *builds*; only `monitor` names frozen *tills*.** The
  console has no idea which device is on which version until that device says so.

`Last seen` ("reached Postgres") and `Last sync` ("a cycle got all the way through") are not
the same fact — a failed cycle still beats, which is what makes "online but stuck since
Tuesday" readable at all. The monitor also prints the credential state of any branch that is
not `active`, because a credential the console disabled and a dead router produce identically
silent tills.

Device-side, [`developer-workflows.md`](developer-workflows.md) §5 is how to read a single
till's `sync_status`.

---

## 8. When it goes wrong

**Rollback is fix-forward.** There is no undo path, by design:

- The version stamp **never moves backwards**, so reverting the console checkout and
  re-running `schema:migrate` does not un-freeze branches. It refuses, and tells you to
  update the checkout.
- Shipping an older build to a till below the floor just puts that till in
  `version_blocked`. You cannot walk a raised floor back.

So a bad release is corrected by a *newer* release, with the version bumped again. Everything
below the floor keeps selling offline in the meantime, which is the property that makes this
survivable.

| Symptom | Cause | Fix |
|---|---|---|
| Till shows **"Update required"** | Build is below the schema's floor — expected during a breaking rollout | Deliver the new AppImage (§6) |
| Till shows **"Sync stuck"**, `last_error_kind = 'conflict'`, a 42501 | A migration added a table and the grant matrix was not re-applied | `schema:reapply-grants --schema=<biz_...>` |
| `schema:version` errors on an existing schema | Half-built — the migration set died before `000001` committed | `schema:migrate --schema=<biz_...>` |
| `schema:migrate --all` reports failures | Some schemas advanced, some did not | Read the summary, fix the named ones, re-run — it is idempotent |
| Console refuses to stamp: "this schema is AHEAD" | The schema was migrated by a newer console checkout | Update the checkout; do not force it down |

A missed till is not data loss. It is a visit.

---

## 9. Traps

Each of these has to be rediscovered otherwise.

- **Reformatting the `'version'` line in `config/nativephp.php`** breaks the console's
  version guard, which reads it by regex from the other application.
- **The host PHP minor decides which static PHP zip NativePHP asks for.** Build on 8.5.
- **The AppImage rename is manual**, and every downstream instruction assumes the renamed,
  space-free form.
- **`dist/` is git-ignored and never cleaned.** Old artifacts sit next to new ones.
- **Console commands must run in their own process.** The app's `.env` wins over
  `console/.env` in any subprocess, which stamps provisioning audit rows eight hours ahead
  ([`developer-workflows.md`](developer-workflows.md) §6).
- **`RELEASE-NOTES.md` is step 4 of §2 for a reason** — it is the step most easily skipped,
  and the repo has already shipped a version bump without it.
