<?php

namespace App\Http\Middleware;

use App\Models\Instance;
use App\Models\SyncStatus;
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
        // Read once and used twice below. Rescued because the settings table may
        // not exist yet on a fresh install, and a shared prop must never be the
        // thing that stops a page rendering.
        $settings = rescue(fn (): array => app(BusinessSettings::class)->all(), null, report: false);
        $instance = rescue(fn (): ?Instance => Instance::current(), null, report: false);

        return array_merge(parent::share($request), [
            'name' => config('app.name'),
            // Business currency symbol for every money render.
            'currency' => $settings['currency_symbol'] ?? '₱',
            // Which business and which branch this device is (M11.5). On EVERY
            // page including the login screen, which is why it is a shared prop
            // rather than a controller's: the branch code prefixes every receipt
            // serial this till prints, so "which branch is this?" is a question
            // the person standing at it must be able to answer without logging
            // in. Business name is a pulled setting, branch identity is written
            // once at setup — null on a device that has never been hydrated.
            'identity' => $instance === null ? null : [
                'business' => $settings['business_name'] ?? null,
                'branch' => $instance->branch_name,
                'code' => $instance->branch_code,
            ],
            // How far behind the business database this branch is, and why (M6).
            // Rescued for the same reason as the settings above: the sync tables
            // may not exist yet on a fresh install. Cheap — two indexed local
            // reads, no network: the connection state is what the LAST cycle
            // concluded, never a probe fired per request.
            'sync' => rescue(fn (): array => SyncStatus::snapshot(), null, report: false),
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
                // The path the setup wizard's native file picker returned, on
                // its way back into the form field (M5).
                'credentials_path' => $request->session()->get('picked_credentials_path'),
            ],
        ]);
    }
}
