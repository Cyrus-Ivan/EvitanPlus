<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\Setup\CompleteSetupRequest;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\SessionManager;
use App\Support\Printing\PrinterSettings;
use App\Support\Settings\BusinessSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The initial setup wizard for a fresh install. Replaces any seeded default
 * account: the Owner is created here, pre-login, together with the business
 * details and (optionally) the printer configuration — all in one atomic
 * submit. RequireInitialSetup routes every request here while no user exists
 * and locks the wizard away forever once one does.
 */
class SetupController extends Controller
{
    public function create(BusinessSettings $settings, PrinterSettings $printerSettings): Response
    {
        return Inertia::render('setup', [
            'defaults' => $settings->all(),
            'printerDefaults' => $printerSettings->all(),
        ]);
    }

    public function store(
        CompleteSetupRequest $request,
        BusinessSettings $settings,
        PrinterSettings $printerSettings,
        AuditRecorder $audit,
        SessionManager $sessions,
    ): RedirectResponse {
        $owner = DB::transaction(function () use ($request, $settings, $printerSettings, $audit): User {
            // Double-submit / race guard; the single-Owner model guard backs
            // this up at the persistence layer.
            abort_if(User::withTrashed()->exists(), 403);

            $owner = User::create([
                'name' => $request->validated('name'),
                'username' => $request->validated('username'),
                'password' => Hash::make($request->validated('password')),
                'role' => UserRole::Owner,
                'must_change_password' => false,
            ]);

            $settings->update($request->businessPayload());

            if ($request->boolean('configure_printer')) {
                $printerSettings->update($request->printerPayload());
            }

            $audit->record(
                action: 'system.setup_completed',
                auditable: $owner,
                description: 'Initial setup completed: Owner account created and business settings configured.',
                actor: $owner,
            );

            return $owner;
        });

        Auth::login($owner);

        $request->session()->regenerate();

        // Record the login event.
        $sessions->handleLogin($owner);

        // Stamp this run's boot id onto the session so the boot-session guard
        // recognises it as belonging to the current run (mirrors the login
        // controller — without it the fresh Owner would be signed out on the
        // very next request under the native runtime).
        $request->session()->put('boot_id', $sessions->currentBootId());

        return redirect()
            ->route('pos')
            ->with('toast', ['type' => 'success', 'message' => 'Welcome to Evitan Plus — setup complete.']);
    }
}
