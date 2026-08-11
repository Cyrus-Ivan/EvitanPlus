<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    /**
     * Show the user's password settings page.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/password');
    }

    /**
     * Update the user's password.
     */
    public function update(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
            // The password is theirs alone now — lift any forced-change gate.
            'must_change_password' => false,
        ]);

        // The password itself is never captured — only the fact of the change.
        $audit->record(
            action: 'user.password_changed',
            auditable: $request->user(),
            description: 'Changed their own password.',
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Password updated.',
        ]);
    }
}
