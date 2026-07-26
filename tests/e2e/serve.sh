#!/usr/bin/env bash
# E2E web server launcher (invoked by playwright.config.ts webServer.command).
#
#   serve.sh <port> <db-file> <full|fresh>
#
# Playwright starts the webServer BEFORE globalSetup runs, so all database
# isolation lives here, strictly ordered ahead of the server.
#
#   full   migrate + seed (settings, demo catalog/sales, the `owner` dev login)
#          then the e2e fixtures — the database the main specs run against.
#   fresh  migrate only: settings defaults exist but NO user does, so
#          RequireInitialSetup funnels the app to the setup wizard. Used by
#          setup.spec.ts, which cannot share a database with a seeded server.
#
# The database is BUILT, never copied from database/nativephp.sqlite: the dev
# DB's contents are whatever the developer last did, and post-v1.0.0 it is not
# even guaranteed to hold an Owner (the setup wizard creates one, no seeded
# production account exists). Inheriting it is what silently broke this suite.
#
# Never `php artisan serve` — it drops the DB_DATABASE/APP_DEBUG overrides.
set -euo pipefail

PORT="${1:?usage: serve.sh <port> <db-file> <full|fresh>}"
DB_NAME="${2:?usage: serve.sh <port> <db-file> <full|fresh>}"
MODE="${3:?usage: serve.sh <port> <db-file> <full|fresh>}"

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
RUNTIME="$ROOT/tests/e2e/.runtime"
DB="$RUNTIME/$DB_NAME"

# The server renders through the built Vite manifest. `npm run e2e` builds once
# up front — building here would race the two servers against public/build.
if [[ ! -f "$ROOT/public/build/manifest.json" ]]; then
    echo "serve.sh: public/build/manifest.json is missing — run 'npm run build' first" >&2
    echo "          (or use 'npm run e2e', which builds before starting Playwright)" >&2
    exit 1
fi

mkdir -p "$RUNTIME"
rm -f "$DB" "$DB-shm" "$DB-wal"
touch "$DB"

# APP_ENV decides the seeded set, and is pinned rather than inherited so it
# never depends on the developer's .env: DatabaseSeeder calls DemoDataSeeder —
# which creates the `owner`/`password` login the specs use — only when local.
# In fresh mode that must NOT happen; a user-less database is the whole point.
SEED_ENV=$([[ "$MODE" == "full" ]] && echo local || echo production)

(cd "$ROOT" && APP_ENV="$SEED_ENV" DB_DATABASE="$DB" php artisan migrate --force --seed)

if [[ "$MODE" == "full" ]]; then
    # `artisan tinker --execute` EXITS 0 even when the required file throws, so
    # the exit code proves nothing and `set -e` cannot catch a failed seed. Gate
    # on the marker seed.php prints only after asserting its own work.
    SEED_OUTPUT="$(cd "$ROOT" && DB_DATABASE="$DB" php artisan tinker \
        --execute="require base_path('tests/e2e/seed.php');" 2>&1)"

    if ! grep -q 'e2e seed complete' <<<"$SEED_OUTPUT"; then
        echo "serve.sh: e2e seed FAILED — refusing to serve a half-seeded database" >&2
        echo "$SEED_OUTPUT" >&2
        exit 1
    fi
fi

cd "$ROOT/public"
exec env DB_DATABASE="$DB" APP_DEBUG=false \
    php -S "127.0.0.1:$PORT" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
