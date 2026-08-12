<?php

use App\Console\Commands\SendScheduledSalesReport;
use App\Exceptions\OfflineWriteException;
use App\Http\Middleware\EnforceBootSession;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireInitialSetup;
use App\Http\Middleware\RequirePasswordChange;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    /*
     * The app's only scheduled work. NativePHP's runtime already ticks
     * `artisan schedule:run` once a minute while the window is open, so this
     * needs no other wiring — but it stops when the window closes, which is why
     * the command decides for itself whether a period is due rather than being
     * fired at an instant it could simply miss. See SendScheduledSalesReport.
     *
     * Every fifteen minutes, not daily: the command is idempotent and its
     * nothing-to-do path is two indexed reads, and a fixed instant is exactly
     * the thing a device-bound scheduler gets wrong. withoutOverlapping because
     * a send blocks for up to the SMTP timeout while the next minute fires.
     *
     * Keep this closure free of database reads — it is evaluated on EVERY
     * artisan invocation, including `migrate` on a device whose tables do not
     * exist yet.
     */
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command(SendScheduledSalesReport::class)
            ->everyFifteenMinutes()
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            // First: a device that has not been hydrated into a branch is routed
            // to the setup wizard before any auth-flavoured guard runs — none of
            // them can apply on a device with no users yet.
            RequireInitialSetup::class,
            // Runs after StartSession and before Inertia so a stale-boot session
            // is signed out (and short-circuited to login) before any page props
            // are shared. Self-guards on an authenticated user.
            EnforceBootSession::class,
            // After the boot guard (a stale session signs out first, not
            // bounce to the password page) and before Inertia shares props.
            RequirePasswordChange::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);

        // The middleware sorter pulls priority-listed entries (the auth
        // middleware, via its AuthenticatesRequests contract) ahead of
        // everything appended to the web group. The setup gate must answer
        // before auth — an un-hydrated device has no one to authenticate — so
        // slot it into the priority list just before the auth entry.
        $middleware->prependToPriorityList(
            AuthenticatesRequests::class,
            RequireInitialSetup::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // A change to shared business data attempted while offline (M6.5). Not
        // an error and never a 500: products, prices, staff and settings belong
        // to the whole business, so changing one needs the business database.
        // Handled here rather than caught in each of the five screens, so every
        // one of them refuses in the same words — and so a screen added later
        // inherits the behaviour instead of having to remember it.
        $exceptions->render(function (OfflineWriteException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 409);
            }

            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        });

        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            // Raw JSON endpoints (e.g. pos.lookup) keep their JSON error shape.
            // Inertia visits request text/html, so they fall through to the
            // mappings below.
            if ($request->expectsJson()) {
                return $response;
            }

            // A stale CSRF token must never dead-end the desktop app: bounce
            // back with a toast; auth middleware handles the logged-out case.
            if ($status === 419) {
                return back()->with('toast', [
                    'type' => 'error',
                    'message' => 'Your session expired. Please try again.',
                ]);
            }

            if (! config('app.debug') && in_array($status, [403, 404, 500, 503], true)) {
                return Inertia::render('error', ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })->create();
