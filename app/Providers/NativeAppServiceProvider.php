<?php

namespace App\Providers;

use App\Support\Boot\LaunchProvisioner;
use Native\Laravel\Contracts\ProvidesPhpIni;
use Native\Laravel\Facades\Window;
use Throwable;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        // Migrate + snapshot reconcile, before the window's first page load
        // (and before ApplicationBooted mints the boot id into the database
        // cache). A fresh install is not seeded — RequireInitialSetup routes
        // it to the setup wizard instead. The window must open even if this
        // fails — the error lands in the log instead.
        try {
            app(LaunchProvisioner::class)->run();
        } catch (Throwable $e) {
            report($e);
        }

        Window::open()
            ->title('Evitan Plus')
            ->width(1280)
            ->height(800)
            ->minWidth(1024)
            ->minHeight(700);
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        return [
        ];
    }
}
