# Provisioning Console — operator runbook

The **Provisioning Console** is a developer-only Laravel CLI app. It holds the admin
Postgres credentials for the Supabase project and is the only thing in this monorepo
allowed to run DDL — `CREATE SCHEMA`, `CREATE ROLE`, `GRANT`. **It never ships in any
build**, which is enforced twice over: `console/` is pruned by
`nativephp.cleanup_exclude_files` and `CONSOLE_*` keys are stripped from any bundled
`.env` by `cleanup_env_keys`, both gated by
[`tests/Feature/Build/BuildExclusionTest.php`](../tests/Feature/Build/BuildExclusionTest.php).

This file is the **runbook**: how to operate it day to day and what to do when something
goes wrong. The project [`README.md`](../README.md) covers first-time setup, the
two-endpoint table and the command list; that is not repeated here. `MULTIBRANCH-BRAINSTORM.md`
is the spec of record and [`docs/glossary.md`](../docs/glossary.md) is normative for the
vocabulary — in particular **revoke ≠ delete ≠ disable ≠ archive**, four different acts
this app performs on four different things.

---

## Start here: the menu

```bash
php console/artisan          # no arguments, no flags
```

That opens `console:run`, the interactive menu, and it is the intended way to operate
this tool. Everything below still works and is what you reach for when scripting, but
you should not need to remember any of it to get work done.

Navigation is **object-first** — pick a business, then a branch, then an action — which
is the point rather than a style choice: the `--business` / `--schema` and `--branch` /
`--branch-code` targeting rules in §2 exist because mistyping an identifier is the
easiest mistake to make here, and you cannot mistype one you selected from a list. The
menu always targets by exact schema name and branch UUID.

```
Provisioning Console          →  the businesses in this project, plus
                                 Provision a new business / Setup & diagnostics / Exit
  └─ a business               →  its branches, with each one's credential state, plus
                                 Add a branch / Upgrade this schema / Back
       └─ a branch            →  only the actions its role's current state allows
```

Three things worth knowing about how it behaves:

- **It runs the same commands, with nothing suppressed.** Choosing *Rotate the
  credential* runs `branch:rotate`, which still prints its summary, still warns that the
  current credential stops working immediately, and still asks you to confirm. The menu
  never passes `--force`.
- **A branch is only offered what its role's state allows.** An active role can be
  rotated, disabled or revoked; a disabled one can be enabled; one that `branch:revoke`
  removed is offered only *Re-create the role*. That is the same `--recreate` rule from
  §3, applied before you choose rather than as a refusal afterwards.
- **Nothing is ever cleared from the screen.** A credentials JSON path is printed once
  and nowhere else, so navigating away from a report never takes the report with it.
- **One action carries a caveat its own command does not.** *Upgrade this schema* runs
  `schema:migrate`, which does **not** re-apply branch grants — see §3. The menu warns
  and asks before running it; that is the only place the warning exists.

Two read-only commands have no menu entry because they *became* the menu: `branch:list`
is the branch picker and `schema:version` is the business screen's header.

`console:run` needs a terminal. Under `--no-interaction`, or with no tty, it refuses and
points you at §2 rather than hanging on a prompt. It takes `--actor=` and forwards it to
every command it dispatches that records one, so the trail can name a person rather than
`<os-user>@<hostname>`.

---

## 1. First run on a new machine

```bash
(cd console && composer install)
cp console/.env.example console/.env
php console/artisan key:generate

# Paste the Supabase admin credentials into console/.env (CONSOLE_DB_*), then:
php console/artisan console:ping        # both endpoints + capabilities + a rolled-back DDL probe
php console/artisan console:migrate     # create evitan_console, the provisioning audit trail
```

`console:migrate` is once per Supabase project, not once per business. Provisioning
**refuses to run** without it.

Two traps worth knowing before you paste anything:

- **Quote `.env` values with single quotes, never double.** phpdotenv interpolates
  `${...}` inside double-quoted values, so a password containing those characters is
  silently rewritten into a different string. It surfaces as *"password authentication
  failed"*, not as a quoting bug, and you can lose an hour to it.
- **Pooler usernames are `postgres.<project-ref>`**, not plain `postgres`, and this
  connection must point at the **session** endpoint on `5432`. Port `6543` is the
  transaction pooler — that is what branch devices use, and it will not serve DDL or
  role management.

`console:ping` prints exactly what it found, so run it first whenever anything looks
wrong. A healthy run reports `CREATEROLE: yes`, `CREATE on database: yes`, a passing DDL
probe, and a branch endpoint on `6543` that answers. `Superuser: no` is normal and
expected on Supabase.

---

## 2. Command reference

This is the scripting surface. Day to day, reach for the menu above; reach for these when
you are automating something, reproducing a failure exactly, or reading about what an
action does before you take it.

Every branch command targets a business with **either** `--business=<uuid>` **or**
`--schema=<name>` (never both), and a branch with **either** `--branch=<uuid>` **or**
`--branch-code=<code>`. `--actor=` is recorded as the operator in the audit trail;
leave it off and it defaults to `<os-user>@<hostname>`.

| Command | What it creates or changes | What a failure leaves behind |
| --- | --- | --- |
| `console:ping` | Nothing. The DDL probe is rolled back. | Nothing. |
| `console:migrate` | The `evitan_console` schema, `provisioning_audit`, `credential_issuance`; re-revokes PUBLIC on every run. | Nothing; safe to re-run. |
| `schema:migrate` | Creates `biz_<32hex>` if absent and applies pending migrations. Idempotent. | A partially-migrated schema. Re-run it. |
| `schema:version` | Nothing (read-only). | — |
| `business:provision` | Schema + 19 migrations + Owner/settings/first branch + `branch_<32hex>` role + credentials JSON. **Create-only.** | Nothing — it rolls back in full, including `DROP SCHEMA`, because it created everything in that run. |
| `branch:provision` | A `branches` row + a role + a JSON, in an existing schema. | Nothing — rolls back all three. **Never** the schema. |
| `branch:list` | Nothing (read-only). | — |
| `branch:rotate` | A new password on an existing role + a new JSON. With `--recreate`, re-creates a revoked role. | **The new password, and the JSON that is its only copy.** See §4. |
| `branch:disable` | Revokes `LOGIN` and terminates the role's backends. | Nothing. |
| `branch:enable` | Restores `LOGIN`. | Nothing. |
| `branch:revoke` | Drops the Postgres role. The branch row and all its history stay. | The role may survive; the command prints the SQL to finish. |
| `console:run` | Nothing itself — it dispatches the commands above. Also the default command, so a bare `php console/artisan` opens it. | Nothing. |

`business:provision`, `branch:provision`, `branch:rotate`, `branch:disable` and
`branch:revoke` confirm before acting; `--force` skips the prompt (and non-interactive
invocations skip it automatically). `--out=` overrides where a credentials JSON is
written. `--pretend` on `schema:migrate` / `console:migrate` prints the SQL instead of
running it, and changes nothing at all — against a schema that does not exist yet it
reports what would be created rather than creating it to find out.

Branch codes are uppercase letters, digits and dashes, starting alphanumeric, max 16
characters. They are not cosmetic: the code prefixes every receipt serial the branch
prints (`MNL-0000000001`), and that prefix is the only thing making receipt numbers
unique business-wide.

---

## 3. Routine operations

### Stand up a new business

```bash
php console/artisan business:provision
```

*In the menu:* **Provision a new business**, on the main screen.

Interactive by default; every prompt also has an option so it scripts cleanly. Prefer
the **password prompt** over `--owner-password`, which is visible in `ps` and in shell
history. Nothing is created until the whole input is collected and validated, and the
command verifies the JSON by connecting **as the new role** and reading the seeded rows
before it reports success — so a credentials file that cannot authenticate is impossible
to ship silently.

It prints the business UUID. Keep it: it is how you target everything below.

### Inspect what exists

```bash
php console/artisan branch:list    --business=<uuid>
php console/artisan schema:version --business=<uuid>
```

*In the menu:* select the business — this is its screen, not an action on it.

`branch:list` shows three credential states, and the distinction matters:
`active` / `disabled` (role exists, `LOGIN` revoked) / `no role` (revoked or never
issued). The Generation column reads `3` for a live credential, `3 (revoked)` when the
role is gone, `never issued` when it never had one. A freshly provisioned business reads
`active` / `1`: `business:provision` records the first branch's credential in the same
ledger the rotate path uses, so generation numbers count every secret a branch has held.

### Add a branch

```bash
php console/artisan branch:provision --business=<uuid> \
    --branch-name="Makati" --branch-code=MKT --branch-address="..."
```

*In the menu:* **Add a branch**, on the business screen.

No new schema, no re-migration — a `branches` row, a role and a JSON.

### Rotate a credential you suspect has leaked

```bash
php console/artisan branch:rotate --business=<uuid> --branch-code=MNL
```

*In the menu:* select the branch, then **Rotate the credential**.

This also terminates the role's warm pooled backends, so the old credential stops
working immediately rather than continuing to be served from Supavisor's pool. Read §4
before you run it — the failure mode is not the one you would guess.

Rotating a **disabled** branch is allowed and cannot be verified: nothing on this path
restores `LOGIN`, so the console skips verification instead of waiting out the pooler
budget for a connection that can never succeed, and reports `verified => false` with that
as the stated reason. Run `branch:enable`, then re-run the rotation, to prove it.

### Disable, then re-enable

```bash
php console/artisan branch:disable --business=<uuid> --branch-code=MNL
php console/artisan branch:enable  --business=<uuid> --branch-code=MNL
```

*In the menu:* select the branch, then **Disable syncing**. Only one of disable and
enable is ever offered — whichever the role's current state allows.

Disable is **`LOGIN`-only**. It does not touch `branches.disabled_at` — a credential you
suspect has leaked is not a branch that has closed, and conflating them would make
re-enabling a credential silently reopen a branch. While disabled the till keeps selling
offline; its outbox flushes when you re-enable it.

### Revoke, and come back from it

```bash
php console/artisan branch:revoke --business=<uuid> --branch-code=MNL
php console/artisan branch:rotate --business=<uuid> --branch-code=MNL --recreate
```

*In the menu:* **Revoke the role** on the branch screen; afterwards that same screen
offers **Re-create the role** and nothing else.

`branch:revoke` drops a **role**. The branch, its sales and its ledger are untouched and
could not be deleted from here. The `--recreate` flag is required in **both** directions
— rotating a missing role is refused without it, re-creating a live one is refused with
it — so a rotation can never silently become a re-creation.

### Upgrade an existing schema

```bash
php console/artisan schema:migrate --business=<uuid>
```

Applies only what the schema has not seen. **Note for M9 onward:** grants are enumerated
per table on purpose, so a migration that adds a table leaves every branch role without
privileges on it until `BranchRole::reapplyGrants()` runs. `schema:migrate` does not do
that today.

### Re-apply the privilege matrix

```bash
php console/artisan schema:reapply-grants --business=<uuid>
```

Needed whenever `BranchGrants` changes, which is not rare: **M6.5** widened it so the
in-app admin could write shared data at all, and **M8** added `inventory_update_queue` to
`INSERT` so a branch device could issue a stock adjustment against another branch. A
business provisioned before either change has roles that predate it, and the symptom is a
till reporting **"Sync stuck"** — `online` + `conflict` — because `permission denied` is
SQLSTATE 42501, an access-rule violation rather than a network fault. `GRANT` is
idempotent, so running this on a current schema is a no-op that says so.

### Delivering a credential

The JSON lands in `console/storage/app/credentials/` at mode `0600` (git-ignored, pruned
from every build). **It is the only copy of the branch's database password** — the
console never prints it. Delivery is a **fresh AppImage plus the JSON**, imported through
the setup wizard, never an in-place password swap. After a successful import, remove it.

---

## 4. The one thing to read before rotating

**A failed verification on a re-issue does not roll back, and that is deliberate.**

By the time verification runs, the role already holds a new secret whose only copy is the
credentials file this run just wrote. Discarding that file would leave a branch whose
password had genuinely changed with no way to ever learn the new one — permanently
stranded. So a failed re-issue **keeps everything** and reports:

```
Verified   not yet — see below
```

That is not a failure to act on by deleting things. Deliver the JSON, or re-run the
rotation to issue a fresh one. Re-running is always safe.

**A re-created role is unusable for about two minutes.** Supabase's Supavisor keys warm
connections by username, so dropping a role and creating one with the same name leaves
the pooler serving sessions bound to the old role's OID. Measured at **~126 seconds** of
`invalid role OID` on a live project. The credential is valid the whole time.
`branch:rotate` waits it out (`--verify-wait`, default 180 seconds).

`branch:provision` and `business:provision` are the opposite case and **do** roll back in
full: they created the branch row, the role and the file in the same run, so undoing all
three destroys nothing that existed before.

---

## 5. Troubleshooting

### Connection and setup

| Symptom | Cause | Fix |
| --- | --- | --- |
| `The pdo_pgsql extension is not loaded — install php-pgsql.` | The host PHP running `console/artisan` has no Postgres driver. | Install `php-pgsql`. (This is the *host* PHP; the shipped app's bundled runtime is a separate binary under `build/php/`, gated by `PhpBinaryTest`.) |
| `Connection failed: ...` | Wrong endpoint, wrong username shape, or IPv6-only host. | `console:ping` prints the checklist: `5432` not `6543`, `postgres.<project-ref>` not `postgres`, and `db.<ref>.supabase.co` is IPv6-only without the IPv4 add-on — use the session pooler host. |
| Password looks right, auth still fails | `.env` value double-quoted and containing `${...}`, which phpdotenv interpolated. | Re-quote with **single** quotes. |
| `CREATEROLE: no` in `console:ping` | Not the service role. | Use the project's admin credentials; branch roles cannot be created without it. |
| `Branches would be issued the admin SESSION endpoint` (warning) | `CONSOLE_BRANCH_DB_PORT` was set to the admin's port. | Unset it, or set `6543`. It works either way — it just does not survive more than a handful of branches. |

### Targeting

| Symptom | Cause | Fix |
| --- | --- | --- |
| `Target a schema with --business=<uuid> or --schema=<name>.` | Neither given. | Supply one. |
| `Pass either --business or --schema, not both.` | Both given; a mismatch would be undetectable. | Supply one. |
| `[x] is not a UUID; a business schema is derived from the business UUID.` | A branch code or name passed to `--business`. | `--business` takes the **business UUID**; branch codes go to `--branch-code`. |
| `Schema [biz_…] does not exist. Check the business UUID, or create it with: php console/artisan business:provision` | Wrong UUID, or the business was never provisioned. | — |
| `The console schema [evitan_console] is not a business schema; migrate it with console:migrate.` | `--schema=evitan_console`. | The business migration set would build 19 wrong tables inside the audit store. Use `console:migrate`. |

### Provisioning

| Symptom | Cause | Fix |
| --- | --- | --- |
| `The provisioning audit store is missing — run: php console/artisan console:migrate` | `evitan_console` absent. | Run it. An unauditable provision is not one this tool performs. |
| `The credential issuance record is missing — run: php console/artisan console:migrate` | Same, for the newer table. | Run it; it is idempotent. |
| `Schema [biz_…] already exists. Provisioning a business is create-only…` | The business exists, or an interrupted run left an orphan. | Adding a branch is `branch:provision`. For a genuine orphan, the message prints the exact cleanup SQL. |
| `Postgres role [branch_…] already exists.` | An orphan role from an interrupted run. | Drop it, or re-run with a fresh business UUID. |
| `The target schema is not empty; refusing to seed over it.` | Seeding found rows it did not create. | Investigate before doing anything — this is a guard against writing over live business data. |
| `[biz_…] has no schema_meta row; the migration did not complete.` / `…the schema is not fully migrated.` | Half-built schema. | Re-run `schema:migrate --schema=biz_…`, then `schema:version` to confirm. |
| `Branch code [X] is already in use in this business.` | Codes are unique per business. | Pick another. Codes are cluster-unique only within a business, which is why roles are named from the branch **UUID**, not the code. |
| `[…/x.json] already exists; choose another --out path.` | Credential files are created with `fopen('x')` and are **never** overwritten — the file it would replace may be the only copy of a delivered credential. | Use `--out=`, or move the old file after confirming it is dead. |
| `Could not create the credentials directory […]` | Permissions. The directory is created `0700`, the file `0600`. | Fix ownership on `console/storage/app/`. |
| `The branch role cannot read the seeded branch row.` | Grants did not apply, or the pooler is serving a stale role. | Retry. If it persists, compare live grants against `BranchGrants` — `BranchRoleGrantsTest` does exactly that. |

### Pooler-lag family — the credential is valid, wait

All four of these mean Supavisor's username-keyed role cache has not caught up. See
[`app/Support/PoolerLag.php`](app/Support/PoolerLag.php), which carries the measured
timing table.

| Symptom | When | Fix |
| --- | --- | --- |
| `invalid role OID: <n>` | After `branch:rotate --recreate`. ~126s measured. | Wait, or raise `--verify-wait`. |
| `(EAUTHQUERY) user not found in the database` | Supavisor's auth query has not seen the new role. | Same. |
| `password authentication failed` immediately after a rotate | The role had warm connections. This is **not** a wrong password. | Same. |
| A disabled branch still connecting | `NOLOGIN` alone is not enforced — measured still connecting after 90s. | `BranchRole::setLogin()` already terminates the role's backends, making cut-off immediate. If you disabled it by hand in SQL instead, terminate them yourself. |

### Failures that changed something

| Symptom | Meaning | Fix |
| --- | --- | --- |
| `Issuing the branch credential failed: …` followed by `IMPORTANT: the role's password HAS been changed…` | The rotation got past the `ALTER ROLE`. The old password is gone; the console never stored it. | Re-run the rotation. It is safe, and the till keeps selling offline meanwhile. |
| `Cleanup did not fully succeed, so some objects remain:` | A compensating undo failed. | The message prints the exact SQL. **Check it before pasting**: for a branch command it must be the three `revoke`/`drop role` statements, never a `drop schema` — that schema holds other branches' sales history. |
| `Could not drop the role: …` from `branch:revoke` | Dependent privileges, or a transient error. | The command prints `revoke all privileges … ; drop role …`. Run those three by hand. |
| `outcome = cleanup_failed` in `provisioning_audit` | Something survived a failed command — either objects left behind, or a password that changed. | Query the row's `detail` for `cleanup_failures` and `password_changed`. |
| `The action succeeded but its audit entry failed: …` | The role change is real; only the trail write failed. | Check `evitan_console` is reachable. The action itself does not need repeating. |

### Test-suite failures

| Symptom | Cause | Fix |
| --- | --- | --- |
| `EMAXCONNSESSION` part-way through a run | The admin session pooler is **15 clients** wide and Supavisor frees them lazily. A cancelled run, an open `tinker`, or a psql session holds slots. | Close them and wait a minute. `Tests\TestCase::tearDownAfterClass` purges four connections per class precisely to avoid this. |
| Leftover `biz_test_*` schemas | A run cancelled before `tearDownAfterClass`. There is no reaper, by choice. | `select schema_name from information_schema.schemata where schema_name like 'biz_test_%';` then drop them. |
| Leftover `biz_<uuid>` schemas and `branch_*` roles | **A second, less obvious leak.** The provisioning tests do not use the `biz_test_` prefix — `ProvisionsBusiness` runs the real `business:provision`, so its schema is named from a freshly minted UUID and is indistinguishable by name from a real business. A run cancelled mid-provision therefore leaks a schema the `biz_test_%` sweep will never find. | See the sweep in §7 — identify them by their fixture branch codes (`FIX`, `LIFE`) in the audit trail, not by their names. |
| The same leak from a run that **completed** | **Fixed, and worth knowing it existed.** `ProvisionsBusiness` is not self-cleaning: its docblock requires every using class to call `tearDownProvisionedBusiness()` from its own `tearDownAfterClass`. `SchemaReapplyGrantsTest` never did, so a fully green suite still left one real `biz_*` schema and one branch role in Supabase **on every run** — eight had accumulated before anyone counted. Nothing fails when this happens, which is the whole problem. | Fixed by adding the teardown. If a new class uses that trait, give it a `tearDownAfterClass` or it will do this again silently. |
| A green run that tested nothing | The bare suite skips silently without credentials. | Always use `composer verify:console`, which passes `--fail-on-skipped`. This exact shape is why the parity guard sat unrun from M1 to M4. |

---

## 6. Reading the trail

The console's self-audit lives in its **own** schema, `evitan_console` — never in a
`biz_*` schema. It is not business history, and the parity guard enumerates what a
business schema may contain. PUBLIC is revoked on it on every `console:migrate`, so no
branch role can reach it. Both tables are append-only by trigger, so the trail binds the
console itself.

```sql
-- Everything that has happened to one business, newest first.
select created_at, action, outcome, branch_code, actor, detail
from evitan_console.provisioning_audit
where business_id = '<business-uuid>'
order by created_at desc;

-- Anything that did not cleanly succeed.
select created_at, action, outcome, schema_name, branch_code, detail
from evitan_console.provisioning_audit
where outcome <> 'succeeded'
order by created_at desc;

-- The live credential per branch, and its history. (No branch_code here — this
-- table is keyed by branch_id; get the code from branch:list or the audit trail.)
select role_name, generation, reason, actor, issued_at, superseded_at
from evitan_console.credential_issuance
where branch_id = '<branch-uuid>'
order by generation desc;
```

`outcome` is one of `succeeded`, `rolled_back`, `cleanup_failed`. Actions are
`branch.provision`, `branch.rotate`, `branch.recreate`, `branch.delete` (the audit action
for `branch:revoke`), plus the business provision. `credential_issuance` carries a
password **fingerprint**, not the password — and it exists because that fingerprint is
the one thing that could not be reconstructed from `provisioning_audit` later: the secret
is gone when the command exits. A partial unique index enforces at most one live
credential per branch.

---

## 7. Testing and housekeeping

```bash
composer verify:console                                  # the gate: both halves, --fail-on-skipped
(cd console && ./vendor/bin/phpunit --testsuite=Unit)    # credential-free half, runs on a fresh clone
(cd console && ./vendor/bin/phpunit --testsuite=Feature) # live Supabase half
```

The suite is split by **whether a test needs a database**. `Unit` boots the app and opens
no connection. `Feature` needs the live Supabase project and creates throwaway
`biz_test_*` schemas and branch roles, dropping them after each class.

Three things about this arrangement that are deliberate and easy to "fix" by mistake:

- **There is no CI**, and no second Postgres. `composer verify` and `composer verify:console`
  on a developer machine are the whole gate, and the local chain is a strict superset of
  what a workflow was running.
- **`--fail-on-skipped` is the honesty guard.** Without it, a `console/.env` missing its
  password produces a green run that executed nothing.
- **`verify:console` is not part of `composer verify`**, so the root chain stays runnable
  offline and without admin credentials.

Housekeeping, all read-only until you decide otherwise:

```sql
-- What exists right now.
select schema_name from information_schema.schemata where schema_name like 'biz\_%';
select rolname, rolcanlogin from pg_roles where rolname like 'branch\_%';
```

```bash
ls -la console/storage/app/credentials/   # stranded 0600 JSONs from failed or undelivered runs
```

**Telling a leaked test schema from a real business.** There is no business registry —
deliberately — so a surviving `biz_<uuid>` schema cannot be classified by name. Use the
trail instead: the fixtures always provision branch code `FIX` ("Fixture Branch") and the
lifecycle test uses `LIFE`, and neither belongs to any real business.

```sql
-- Surviving schemas, annotated with what the trail says was done to them.
select s.schema_name,
       string_agg(distinct a.branch_code, ',') as branch_codes,
       max(a.created_at)                       as last_touched,
       string_agg(distinct a.actor, ',')       as actors
from information_schema.schemata s
left join evitan_console.provisioning_audit a on a.schema_name = s.schema_name
where s.schema_name like 'biz\_%'
group by s.schema_name
order by last_touched desc;
```

A schema whose only branch codes are `FIX`/`LIFE` and which holds zero transactions is
test litter. Drop it and its roles — but **read `branch:list` first**, and never drop a
schema you cannot account for:

```sql
select count(*) from "biz_<hex>".transactions;   -- confirm it is empty before dropping
```

---

## 8. Hard rules

- **Never copy `CONSOLE_DB_*` into the root `.env`.** Admin credentials never leave
  `console/.env`.
- **Never point the admin connection at `6543`.** DDL, role management and the
  `search_path` pin all need session state that transaction mode discards.
- **Prefer the interactive password prompt** over `--owner-password`, which is visible in
  `ps` and shell history. The Owner's password is never written to the JSON or the audit
  trail.
- **The credentials JSON is the only copy of a branch password.** Do not delete one
  because a command reported `verified => false`.
- **Read the cleanup SQL before pasting it.** A branch-level cleanup never contains
  `drop schema`.
