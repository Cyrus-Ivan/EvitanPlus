---
name: verify
description: Build, launch, and drive Evitan Plus headlessly to verify UI/backend changes end-to-end (php -S + chrome-headless-shell over raw CDP)
---

# Verifying Evitan Plus end-to-end

## Launch (isolated — never touches the real DB or the user's native:serve on :8100)

```bash
SCRATCH=<session scratchpad>/verify && mkdir -p "$SCRATCH"
cp database/nativephp.sqlite "$SCRATCH/verify.sqlite"

# Seed a throwaway login in the COPY (single-session enforcement kicks real users):
DB_DATABASE="$SCRATCH/verify.sqlite" php artisan tinker --execute='
App\Models\User::factory()->admin()->create(["username" => "verifybot", "name" => "Verify Bot"]);'

npm run build   # tests + php -S serve the built manifest, not Vite dev

# Serve. Do NOT use `php artisan serve` — its child process does not inherit
# DB_DATABASE/APP_DEBUG overrides. Run php -S directly from public/ with the
# framework router:
cd public && DB_DATABASE="$SCRATCH/verify.sqlite" APP_DEBUG=false \
  php -S 127.0.0.1:8123 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
```

## Drive (no Playwright npm package — raw CDP over Node 24 WebSocket)

```bash
~/.cache/ms-playwright/chromium_headless_shell-1228/chrome-headless-shell-linux64/chrome-headless-shell \
  --remote-debugging-port=9224 --no-sandbox --user-data-dir="$SCRATCH/profile" --window-size=1280,800 about:blank
```

Fetch `http://127.0.0.1:9224/json/list`, open the `webSocketDebuggerUrl`, then
`Page.navigate` / `Runtime.evaluate` / `Input.dispatchKeyEvent` /
`Page.captureScreenshot`. A full working driver lived at the session scratchpad
`verify/drive.mjs` (2026-07-17) — helpers for login, waitFor-poll, React
controlled-input setValue (native setter + `input` event), key dispatch, and
screenshots. Rewrite from that pattern.

## Flows worth driving

- Login: `#username` / `#password`, `button[type=submit]` → lands on `/pos`.
- Setup wizard: the copied dev DB has users, so the wizard never appears. To
  drive it, point `DB_DATABASE` at an **empty** file and run
  `php artisan migrate --force` only (no seed) — every route then redirects to
  `/setup` (4 client-side steps, one atomic POST, auto-login to `/pos`).
- Error pages: `/definitely-missing` (404) and role-gated routes (e.g. admin →
  `/business-settings` = 403) with `APP_DEBUG=false`; expect Inertia component
  `error`.
- POS: product tiles are buttons with `.line-clamp-2` labels; search input
  placeholder starts with "Search or scan"; `#cash-received`; F9 = checkout.
- Dialogs: `[role="dialog"][data-state="open"]`; toasts: `[data-sonner-toast]`.

## Gotchas

- **Never checkout against the real DB** — the transaction serial is gapless
  and permanent. The DB copy makes checkout safe.
- `pkill -f "<pattern>"` matches your own shell's command line and kills your
  compound command. Kill by port instead: `fuser -k 8123/tcp`.
- Feature tests render through the built Vite manifest — after adding a page,
  `npm run build` or tests fail with "Unable to locate file in Vite manifest".
- Inertia component assertions need `->component('name', false)` (page_paths
  config points at `js/Pages`, repo uses lowercase `js/pages`).
- Wait for selectors with a poll loop, not fixed sleeps.
