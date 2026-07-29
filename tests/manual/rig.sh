#!/usr/bin/env bash
# Two-branch manual rig — look at the app as a user, as two branches at once.
#
#   rig.sh ready    two hydrated tills, ready to sell
#   rig.sh wizard   two un-hydrated devices + the credential paths, you drive
#                   /setup yourself
#   rig.sh clean    destroy what a killed run left behind
#
# `ready` and `wizard` provision a REAL throwaway business with two branches on
# the live Supabase project, then stay in the foreground. Ctrl-C destroys all of
# it — both servers, both SQLite files, both credential JSONs, the Postgres
# schema and both branch roles. `clean` is only for a run that died before its
# trap could fire.
#
# This is a PERCEPTION tool. The tests own correctness (composer verify,
# verify:hydrate); this exists so the wizard and two-branch hydration can be
# looked at without rebuilding an AppImage for every look.
#
# ── Four things that look arbitrary and are not ────────────────────────────
#
# TWO LOOPBACK HOSTS, NOT TWO PORTS. The XSRF-TOKEN cookie name is hardcoded in
# the framework (VerifyCsrfToken.php:204) and cookies are scoped by host, not by
# port — two ports on 127.0.0.1 would overwrite each other's CSRF token and
# bootstrap/app.php:66-72 would turn every form submit into a silent "Your
# session expired" bounce. All of 127.0.0.0/8 is local on `lo`, so 127.0.0.2
# needs no /etc/hosts entry. SESSION_COOKIE differs too, for legible DevTools.
#
# APP_ENV=production, ALWAYS. Under `local`, DatabaseSeeder::seedDevInstance()
# writes the fake DEV instance row and calls DemoDataSeeder — the device would
# look hydrated before the wizard ever ran, and demo users would sit beside the
# hydrated ones. Same reason tests/e2e/serve.sh pins it for `fresh`.
#
# HYDRATION IS GATED ON A PRINTED MARKER. `artisan tinker --execute` exits 0
# even when the required file throws, so its exit code proves nothing. hydrate.php
# asserts its own work and prints a marker; only that marker is trusted.
#
# NO `inventory:reconcile`, EVER. It rebuilds stock snapshots from the local
# ledger, and a hydrated till has snapshots with no ledger behind them (M5 copies
# stock_snapshots, not inventory_ledger). Running it would zero every till.

set -euo pipefail

MODE="${1:-}"

case "$MODE" in
    ready|wizard|clean) ;;
    *) echo "usage: rig.sh <ready|wizard|clean>" >&2; exit 64 ;;
esac

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
RT="$ROOT/tests/manual/.runtime"
STATE="$RT/state.env"

HOST_A=127.0.0.1; PORT_A=8131; CODE_A=MNL; NAME_A=Manila
HOST_B=127.0.0.2; PORT_B=8132; CODE_B=CEB; NAME_B=Cebu
DB_A="$RT/branch-a.sqlite"; JSON_A="$RT/branch-a.json"
DB_B="$RT/branch-b.sqlite"; JSON_B="$RT/branch-b.json"

OWNER_USERNAME=rig-owner
OWNER_PASSWORD=manual-rig-pass

# ── State, written incrementally ───────────────────────────────────────────
# Each artifact is recorded the instant it exists, never in a batch at the end.
# A kill -9 at any point then leaves a state file that describes exactly what
# was created, which is the only thing `clean` has to go on.
remember() { printf '%s=%q\n' "$1" "$2" >>"$STATE"; }

# ── Teardown ───────────────────────────────────────────────────────────────
# The Ctrl-C trap and `clean` both call THIS. Two implementations of a
# destructive path is how one of them rots.
teardown() {
    [[ -n "${TORN_DOWN:-}" ]] && return 0
    TORN_DOWN=1

    echo
    echo "rig: tearing down…"

    # PIDs are reused, so `clean` an hour later must not shoot a stranger.
    # Identify ours by DB_DATABASE in the process ENVIRONMENT, not by argv: the
    # server's argv is only `php -S <host>:<port> …/server.php`, and matching on
    # the port would trust the very thing PID reuse makes untrustworthy.
    local pid
    for pid in "${PID_A:-}" "${PID_B:-}"; do
        if [[ -n "$pid" ]] && grep -qsa -- "DB_DATABASE=$RT/" "/proc/$pid/environ"; then
            kill -TERM "$pid" 2>/dev/null || true

            # `wait` only works on children, and under `clean` these are
            # orphans of a shell that is already gone — so poll instead, or the
            # ports could still be bound when the next `rig` starts.
            # `waited=$(( … ))`, never `(( waited++ ))`: post-increment past
            # zero returns exit status 1, which under `set -e` would abort
            # teardown here — before the Postgres drop, silently leaking the
            # schema this function exists to remove.
            local waited=0
            while [[ -e "/proc/$pid" ]] && [[ "$waited" -lt 50 ]]; do
                sleep 0.1
                waited=$(( waited + 1 ))
            done
        fi
    done

    local dropped=0
    if [[ -n "${SCHEMA:-}" ]]; then
        php "$ROOT/tests/manual/pg.php" drop "$SCHEMA" "${ROLE_A:-}" "${ROLE_B:-}" || dropped=1
    fi

    rm -f "$RT"/branch-a.sqlite* "$RT"/branch-b.sqlite* "$JSON_A" "$JSON_B" 2>/dev/null || true

    if (( dropped == 0 )); then
        rm -f "$STATE"
        echo "rig: done — nothing left locally or in Postgres."
    else
        # Deleting the state file here is the one and only way this design can
        # lose a schema name for good. Keep it and say so.
        echo "rig: POSTGRES TEARDOWN FAILED — state kept at $STATE" >&2
        echo "rig: re-run 'composer rig:clean' once the connection is back," >&2
        echo "rig: or drop it by hand:" >&2
        echo "rig:   drop schema if exists \"${SCHEMA:-?}\" cascade;" >&2
        echo "rig:   drop role if exists \"${ROLE_A:-?}\";" >&2
        echo "rig:   drop role if exists \"${ROLE_B:-?}\";" >&2
    fi
}

# ── clean ──────────────────────────────────────────────────────────────────
if [[ "$MODE" == clean ]]; then
    if [[ ! -f "$STATE" ]]; then
        echo "rig: nothing to clean."
        exit 0
    fi

    set -a; . "$STATE"; set +a
    teardown
    exit 0
fi

# ── Preflight ──────────────────────────────────────────────────────────────
# Everything knowable locally is checked BEFORE the first console call:
# provisioning costs ~15s of Supabase round trips and creates real objects, so
# nothing that could have been known here should be discovered after it.
fail() { echo "rig: $1" >&2; exit 1; }

port_busy() { (exec 3<>"/dev/tcp/$1/$2") 2>/dev/null && exec 3>&- && return 0 || return 1; }

if [[ ! -f "$ROOT/public/build/manifest.json" ]]; then
    fail "public/build/manifest.json is missing — run 'npm run build' first"
fi

# Not in serve.sh, and load-bearing here: a cached config ignores DB_DATABASE,
# so both "branches" would quietly run against database/nativephp.sqlite — the
# real dev database — and teardown would delete it.
if [[ -f "$ROOT/bootstrap/cache/config.php" ]]; then
    fail "a cached config would ignore DB_DATABASE and point both tills at your real database — run 'php artisan config:clear'"
fi

[[ -d "$ROOT/console/vendor" ]] || fail "the console has no dependencies — run '(cd console && composer install)'"
[[ -f "$ROOT/console/.env" ]]   || fail "console/.env does not exist — copy console/.env.example and fill in CONSOLE_DB_*"

grep -q '^CONSOLE_DB_PASSWORD=..*' "$ROOT/console/.env" \
    || fail "console/.env has no CONSOLE_DB_PASSWORD — provisioning needs the admin credentials"

php -m | grep -qi '^pdo_pgsql$' || fail "the pdo_pgsql extension is not loaded — tests/manual/pg.php needs it"

if [[ -e "$STATE" ]]; then
    fail "a previous run left $STATE behind. If no rig is running, 'composer rig:clean' first"
fi

for hostport in "$HOST_A $PORT_A" "$HOST_B $PORT_B"; do
    # shellcheck disable=SC2086
    set -- $hostport
    port_busy "$1" "$2" && fail "$1:$2 is already in use (Playwright uses 8123/8124, native:serve uses 8100, another rig uses 8131/8132)"
done

# ── Provision ──────────────────────────────────────────────────────────────
mkdir -p "$RT"
: >"$STATE"

# Armed BEFORE the first provision call, so a business whose second branch fails
# still gets dropped. One trap does the work; INT/TERM only set the status, so
# it can never double-fire.
trap 'teardown' EXIT
# INT/TERM only set the exit status; the single EXIT trap does all the work, so
# teardown runs exactly once whether you Ctrl-C or a step fails under `set -e`.
# Status 0 rather than the conventional 130 because Ctrl-C is this tool's normal
# way to finish — at 130, `composer rig` would end every successful session by
# reporting a script error. Real failures still exit non-zero via `set -e`.
trap 'exit 0' INT TERM

# Console success output goes through twoColumnDetail, which pads and truncates
# to terminal width — never parse it. Read the JSON from the known --out path,
# the way tests/Integration/HydrationTest.php does.
jread() { php -r 'echo json_decode(file_get_contents($argv[1]), true)[$argv[2]][$argv[3]];' -- "$@"; }

echo "rig: provisioning a throwaway business on Supabase (~15s)…"

php "$ROOT/console/artisan" business:provision \
    --owner-name='Rig Owner' \
    --username="$OWNER_USERNAME" \
    --owner-password="$OWNER_PASSWORD" \
    --business-name='Manual Rig Co' \
    --business-address='1 Rig Street, Makati' \
    --tin=000-000-000-000 \
    --receipt-footer='Salamat po!' \
    --timezone=Asia/Manila \
    --currency-symbol=₱ \
    --branch-name="$NAME_A" \
    --branch-code="$CODE_A" \
    --branch-address="1 Rig Street, $NAME_A" \
    --out="$JSON_A" \
    --actor=manual-rig \
    --force --no-interaction

# The moment the JSON is readable, the schema and role exist and must be
# recoverable — record them before anything else can fail.
SCHEMA="$(jread "$JSON_A" business schema)"; remember SCHEMA "$SCHEMA"
ROLE_A="$(jread "$JSON_A" role name)";       remember ROLE_A "$ROLE_A"
BUSINESS_ID="$(jread "$JSON_A" business id)"; remember BUSINESS_ID "$BUSINESS_ID"

echo "rig: adding the second branch…"

php "$ROOT/console/artisan" branch:provision \
    --business="$BUSINESS_ID" \
    --branch-name="$NAME_B" \
    --branch-code="$CODE_B" \
    --branch-address="2 Rig Street, $NAME_B" \
    --out="$JSON_B" \
    --actor=manual-rig \
    --force --no-interaction

ROLE_B="$(jread "$JSON_B" role name)"; remember ROLE_B "$ROLE_B"

# A provisioned business holds only its branches row, the Owner and settings —
# no catalog. Without this both tills would hydrate with nothing to sell.
echo "rig: seeding a catalog and per-branch stock…"
php "$ROOT/tests/manual/pg.php" seed "$JSON_A" "$JSON_B"

# ── Local devices ──────────────────────────────────────────────────────────
migrate_device() {
    local db="$1"
    rm -f "$db" "$db-shm" "$db-wal"
    touch "$db"
    (cd "$ROOT" && APP_ENV=production DB_DATABASE="$db" php artisan migrate --force --seed >/dev/null)
}

hydrate_device() {
    local db="$1" json="$2" output
    output="$(cd "$ROOT" && APP_ENV=production DB_DATABASE="$db" RIG_CREDENTIALS="$json" \
        php artisan tinker --execute="require base_path('tests/manual/hydrate.php');" 2>&1)" || true

    # tinker exits 0 through a thrown exception, so the marker is the only
    # trustworthy signal — and hydrate.php prints it only after asserting that
    # instance.hydrated_at, the Owner, the catalog and the stock all arrived.
    if ! grep -q 'manual rig hydrated' <<<"$output"; then
        echo "rig: hydration FAILED for $(basename "$db")" >&2
        echo "$output" >&2
        exit 1
    fi
}

echo "rig: preparing both local databases…"
migrate_device "$DB_A"
migrate_device "$DB_B"

if [[ "$MODE" == ready ]]; then
    echo "rig: hydrating both devices…"
    hydrate_device "$DB_A" "$JSON_A"
    hydrate_device "$DB_B" "$JSON_B"
fi

# ── Serve ──────────────────────────────────────────────────────────────────
# Never `php artisan serve`: it drops the env overrides. `env -u` keeps a
# PHP_CLI_SERVER_WORKERS exported by the developer's profile from forking
# workers this script would then have to reap (the .env line is inert here —
# php -S reads the process environment, not Laravel's .env).
serve_device() {
    local host="$1" port="$2" db="$3" cookie="$4"
    (
        cd "$ROOT/public"
        exec env -u PHP_CLI_SERVER_WORKERS \
            DB_DATABASE="$db" APP_ENV=production APP_DEBUG=false SESSION_COOKIE="$cookie" \
            php -S "$host:$port" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php \
            >/dev/null 2>&1
    ) &
}

serve_device "$HOST_A" "$PORT_A" "$DB_A" rig_a_session; PID_A=$!; remember PID_A "$PID_A"
serve_device "$HOST_B" "$PORT_B" "$DB_B" rig_b_session; PID_B=$!; remember PID_B "$PID_B"

# ── Banner ─────────────────────────────────────────────────────────────────
rule() { printf '%s\n' "────────────────────────────────────────────────────────────────────"; }

echo
rule
echo "  Manual Rig Co — two branches, one business"
echo
echo "  Branch $CODE_A ($NAME_A)   http://$HOST_A:$PORT_A"
echo "  Branch $CODE_B ($NAME_B)     http://$HOST_B:$PORT_B"
echo

if [[ "$MODE" == ready ]]; then
    echo "  Sign in on either, same account:  $OWNER_USERNAME / $OWNER_PASSWORD"
    echo
    echo "  Same catalog on both, hydrated from Postgres. Stock differs per"
    echo "  branch — $CODE_B is short of a few things, and out of Party Size"
    echo "  chips entirely, so the low-stock and out-of-stock states are"
    echo "  already visible."
else
    echo "  Both devices are un-hydrated; each opens on /setup."
    echo
    echo "  Paste this path into the wizard at http://$HOST_A:$PORT_A"
    echo "    $JSON_A"
    echo
    echo "  …and this one at http://$HOST_B:$PORT_B"
    echo "    $JSON_B"
    echo
    echo "  The paths must be absolute — the wizard takes a filesystem path,"
    echo "  not an upload, and resolves it from the server's working directory."
    echo "  There is no Browse button outside the desktop runtime. Leave"
    echo "  \"delete the file after import\" unticked to retry without"
    echo "  re-provisioning."
    echo
    echo "  Owner account, once hydrated:  $OWNER_USERNAME / $OWNER_PASSWORD"
fi

echo
echo "  Sales do NOT cross between the two windows: each till mirrors only its"
echo "  own branch. They DO both reach Postgres — sync now on the header, or"
echo "  automatically after each checkout — so watch the unsynced count fall."
echo "  Receipts are branch-prefixed, so each till counts from its own 1."
echo
echo "  business  $BUSINESS_ID"
echo "  schema    $SCHEMA"
echo
echo "  Ctrl-C tears all of this down, including the Postgres schema and both"
echo "  branch roles."
rule
echo

# Interruptible, unlike a sleep loop — Ctrl-C lands immediately. `-n` so a
# server that dies on its own ends the run, instead of leaving one working
# window and no explanation.
wait -n || true
