<?php

namespace App\Http\Middleware;

use App\Models\Instance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes a device that is not yet a branch to the setup wizard, and keeps the
 * wizard unreachable once it is one.
 *
 * The signal is `instance.hydrated_at` (MULTIBRANCH-BRAINSTORM.md §11.6). Until
 * M5 it was "no user exists", which was the right question for a single install
 * that CREATED its Owner locally — and is the wrong one now that users arrive
 * from Postgres: they appear part-way through setup, so their presence no longer
 * means the device is ready.
 *
 * `hydrated_at` can never be half-true. It is stamped in the same SQLite
 * transaction as the hydrated rows, so either the baseline and the stamp are
 * both there or neither is, and a device that lost its network mid-hydration
 * comes back to the wizard rather than to a half-empty catalog. A restored
 * backup carries the row and so never re-triggers the wizard.
 *
 * Checked live on every request (one primary-key lookup on local SQLite): no
 * persisted flag anywhere else, so the answer cannot drift from the database.
 *
 * Runs first in the web group — before the boot-session and password-change
 * guards, neither of which can apply pre-auth on an un-hydrated device.
 */
class RequireInitialSetup
{
    /** Route names that make up the setup wizard. */
    private const SETUP_ROUTES = ['setup', 'setup.store', 'setup.browse', 'setup.printers'];

    public function handle(Request $request, Closure $next): Response
    {
        $isSetupRoute = $request->routeIs(...self::SETUP_ROUTES);

        if (! Instance::isHydrated()) {
            return $isSetupRoute ? $next($request) : redirect()->route('setup');
        }

        return $isSetupRoute ? redirect()->route('login') : $next($request);
    }
}
