<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 19 — forced password change. A user whose password someone else knows
 * (anyone after an administrative reset) is confined to the password page
 * until they set their own.
 */
class RequirePasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private function flaggedUser(): User
    {
        return User::factory()->cashier()->create(['must_change_password' => true]);
    }

    public function test_a_flagged_user_is_confined_to_the_password_page(): void
    {
        $user = $this->flaggedUser();

        $this->actingAs($user)->get(route('pos'))->assertRedirect(route('password.edit'));
        $this->actingAs($user)->get(route('inventory'))->assertRedirect(route('password.edit'));
    }

    public function test_a_flagged_user_can_reach_the_password_page_and_log_out(): void
    {
        $user = $this->flaggedUser();

        $this->actingAs($user)->get(route('password.edit'))->assertOk();
        $this->actingAs($user)->post(route('logout'))->assertRedirect('/');
    }

    public function test_an_unflagged_user_is_unaffected(): void
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('pos'))
            ->assertOk();
    }

    public function test_login_shortcuts_a_flagged_user_straight_to_the_password_page(): void
    {
        $user = $this->flaggedUser();

        $this->post(route('login'), [
            'username' => $user->username,
            'password' => 'password',
        ])->assertRedirect(route('password.edit'));
    }

    public function test_setting_a_new_password_clears_the_flag_and_is_audited(): void
    {
        $user = $this->flaggedUser();

        $this->actingAs($user)
            ->from(route('password.edit'))
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'a-password-of-my-own-1',
                'password_confirmation' => 'a-password-of-my-own-1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($user->refresh()->must_change_password);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.password_changed',
            'user_id' => $user->id,
            'auditable_id' => $user->id,
        ]);

        // The gate lifts immediately.
        $this->actingAs($user)->get(route('pos'))->assertOk();
    }

    public function test_an_administrative_reset_flags_the_target(): void
    {
        $owner = User::factory()->owner()->create();
        $cashier = User::factory()->cashier()->create();

        $this->actingAs($owner)->patch(route('users.reset-password', $cashier->id), [
            'password' => 'temp-password-123',
            'password_confirmation' => 'temp-password-123',
        ]);

        $this->assertTrue($cashier->refresh()->must_change_password);
    }

    public function test_a_normal_password_change_still_writes_the_audit_entry(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'a-brand-new-password-1',
                'password_confirmation' => 'a-brand-new-password-1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, AuditLog::query()->where('action', 'user.password_changed')->count());
    }
}
