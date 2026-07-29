<?php

namespace App\Http\Middleware;

use App\Support\Auth\Permissions;
use App\Support\Settings\BusinessSettings;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'name' => config('app.name'),
            // Business currency symbol for every money render. Rescued because
            // the settings table may not exist yet on a fresh install.
            'currency' => rescue(fn (): string => app(BusinessSettings::class)->all()['currency_symbol'], '₱', report: false),
            'auth' => [
                'user' => $request->user(),
                // Role hierarchy resolved server-side so the client never
                // re-implements it. Empty for guests.
                'permissions' => Permissions::forUser($request->user()),
            ],
            'flash' => [
                // Bridged to Sonner toasts on the client (see use-flash-toast.ts).
                // Flash with: ->with('toast', ['type' => 'success', 'message' => '...']).
                'toast' => $request->session()->get('toast'),
                // Set after a database restore: the backup page shows a
                // persistent banner with a Restart button until relaunch.
                'restart_required' => (bool) $request->session()->get('restart_required'),
                // The path the setup wizard's native file picker returned, on
                // its way back into the form field (M5).
                'credentials_path' => $request->session()->get('picked_credentials_path'),
            ],
        ]);
    }
}
