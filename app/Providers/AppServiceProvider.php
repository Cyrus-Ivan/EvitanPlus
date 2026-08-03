<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Auth\SessionManager;
use App\Support\Backup\DirectoryPicker;
use App\Support\Backup\NativeDialogPicker;
use App\Support\Boot\EncryptionKeyBootstrapper;
use App\Support\Search\LikeQuery;
use App\Support\Settings\ApplyBusinessTimezone;
use App\Support\Sync\OnlineWrite;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Native\Laravel\Events\App\ApplicationBooted;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DirectoryPicker::class, NativeDialogPicker::class);

        // Singleton because OnlineWrite::mirror() records against the run
        // currently in progress. A controller and the importer it calls must
        // reach the same instance, or rows declared by one would be invisible
        // to the other and would silently never reach the business database —
        // which is the exact failure M6.5 exists to end.
        $this->app->singleton(OnlineWrite::class);

        // Packaged builds ship without an APP_KEY; generate a per-install one
        // on first use. Runs after the NativePHP provider's register phase, so
        // storage_path() already points at the per-install userData directory.
        (new EncryptionKeyBootstrapper)->ensure();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureNativeRuntime();

        // Apply the business timezone chosen during initial setup. Rescued
        // because the settings table may not exist yet (first migrate, test
        // bootstrap); the APP_TIMEZONE default stays in effect until it does.
        rescue(fn () => (new ApplyBusinessTimezone)(), report: false);

        $this->registerAuthorizationGates();

        // Wildcard-safe LIKE search macros (whereLike / orWhereLike / whereLikeAny).
        LikeQuery::registerMacros();

        // Mint a fresh boot id on every launch. A session stamped with an older
        // id (see EnforceBootSession) outlived the run that created it — i.e. the
        // previous run ended without a clean logout. ApplicationBooted fires only
        // under the native runtime; outside it the listener never runs (and the
        // guard fails open when no boot id has been minted).
        Event::listen(ApplicationBooted::class, function (): void {
            app(SessionManager::class)->handleBoot();
        });
    }

    /**
     * Overrides for the NativePHP runtime, applied after the vendor provider
     * has configured itself (app providers boot last, so these values stick).
     */
    private function configureNativeRuntime(): void
    {
        if (! config('nativephp-internal.running')) {
            return;
        }

        // NativePHP forces file sessions; re-pin the database driver so
        // SessionManager::forceLogout()'s row deletion actually evicts users
        // and sessions stay inspectable. The sessions table always exists
        // before the first request: the packaged runtime migrates on install
        // and LaunchProvisioner migrates on every launch.
        config(['session.driver' => 'database']);

        // The vendor builds the packaged "nativephp" connection without a
        // busy_timeout, so concurrent workers would fail SQLITE_BUSY instantly
        // instead of waiting (its boot-time PRAGMA only covers that one PDO).
        if (config()->has('database.connections.nativephp')) {
            config(['database.connections.nativephp.busy_timeout' => 5000]);
        }
    }

    /**
     * Named abilities backing the Owner > Admin > Cashier permission matrix.
     * These are the single source of truth for both server-side authorization
     * and the resolved permission map shared to the frontend (see
     * App\Support\Auth\Permissions).
     */
    private function registerAuthorizationGates(): void
    {
        // Admin-level (also cleared by the Owner).
        Gate::define('access-reports', fn (User $user): bool => $user->hasRoleAtLeast(UserRole::Admin));
        Gate::define('access-audit-logs', fn (User $user): bool => $user->hasRoleAtLeast(UserRole::Admin));
        Gate::define('manage-users', fn (User $user): bool => $user->hasRoleAtLeast(UserRole::Admin));
        // Backend-only second layer for inventory writes (not surfaced to the
        // frontend permission map). Backs the explicit authorize() calls that
        // no longer let inventory writes depend solely on route placement.
        Gate::define('manage-inventory', fn (User $user): bool => $user->hasRoleAtLeast(UserRole::Admin));

        // Owner-only.
        Gate::define('access-settings', fn (User $user): bool => $user->isOwner());
        Gate::define('access-backup', fn (User $user): bool => $user->isOwner());
        Gate::define('view-item-cost', fn (User $user): bool => $user->isOwner());
        // Cross-branch figures read live from the business database (M11). The
        // branch-local report on the same screen stays Admin+; only the scope
        // that reaches other branches is held back to the Owner.
        Gate::define('view-consolidated-reports', fn (User $user): bool => $user->isOwner());
    }
}
