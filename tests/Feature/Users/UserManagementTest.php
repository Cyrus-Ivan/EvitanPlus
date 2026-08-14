<?php

namespace Tests\Feature\Users;

use App\Enums\LoginEventType;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function userPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Jane Cashier',
            'username' => 'jane',
            'role' => UserRole::Cashier->value,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides);
    }

    public function test_owner_can_create_an_admin_and_a_cashier()
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post(route('users.store'), $this->userPayload(['username' => 'newadmin', 'role' => UserRole::Admin->value]))
            ->assertRedirect(route('users'))
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->post(route('users.store'), $this->userPayload(['username' => 'newcashier', 'role' => UserRole::Cashier->value]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['username' => 'newadmin', 'role' => UserRole::Admin->value]);
        $this->assertDatabaseHas('users', ['username' => 'newcashier', 'role' => UserRole::Cashier->value]);
    }

    public function test_admin_can_only_create_a_cashier()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('users.store'), $this->userPayload(['username' => 'ok', 'role' => UserRole::Cashier->value]))
            ->assertSessionHasNoErrors();

        // Admin may not create another Admin...
        $this->actingAs($admin)
            ->post(route('users.store'), $this->userPayload(['username' => 'nope', 'role' => UserRole::Admin->value]))
            ->assertSessionHasErrors('role');

        // ...nor an Owner.
        $this->actingAs($admin)
            ->post(route('users.store'), $this->userPayload(['username' => 'nope2', 'role' => UserRole::Owner->value]))
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['username' => 'nope']);
        $this->assertDatabaseMissing('users', ['username' => 'nope2']);
    }

    public function test_nobody_can_create_an_owner()
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post(route('users.store'), $this->userPayload(['username' => 'secondowner', 'role' => UserRole::Owner->value]))
            ->assertSessionHasErrors('role');

        $this->assertSame(1, User::where('role', UserRole::Owner->value)->count());
    }

    public function test_admin_cannot_promote_a_cashier_to_admin_via_role_change()
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->cashier()->create();

        // The admin outranks the cashier (so `manage` passes), but `admin` is not
        // in the admin's assignable set — the escalation is rejected by validation.
        $this->actingAs($admin)
            ->patch(route('users.role', $cashier->id), ['role' => UserRole::Admin->value])
            ->assertSessionHasErrors('role');

        $this->assertSame(UserRole::Cashier, $cashier->fresh()->role);
    }

    public function test_nobody_can_promote_a_user_to_owner_via_role_change()
    {
        $owner = User::factory()->owner()->create();
        $admin = User::factory()->admin()->create();

        // Even the Owner may not mint a second Owner by editing an existing role.
        $this->actingAs($owner)
            ->patch(route('users.role', $admin->id), ['role' => UserRole::Owner->value])
            ->assertSessionHasErrors('role');

        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
        $this->assertSame(1, User::where('role', UserRole::Owner->value)->count());
    }

    public function test_an_actor_cannot_manage_themselves()
    {
        $admin = User::factory()->admin()->create();

        // Equal rank is not strictly outranked, so self-management is forbidden.
        $this->actingAs($admin)
            ->patch(route('users.role', $admin->id), ['role' => UserRole::Cashier->value])
            ->assertForbidden();

        $this->actingAs($admin)
            ->patch(route('users.archive', $admin->id))
            ->assertForbidden();

        $this->actingAs($admin)
            ->patch(route('users.reset-password', $admin->id), [
                'password' => 'brandnewpass1',
                'password_confirmation' => 'brandnewpass1',
            ])
            ->assertForbidden();

        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
        $this->assertNull($admin->fresh()->deleted_at);
    }

    /**
     * The password policy is a security control configured in one place
     * (AppServiceProvider::configurePasswordPolicy) and consumed through
     * `Password::defaults()` in all three password paths. Unconfigured, Laravel
     * falls back to `min(8)` and nothing else — silently, and with every test
     * still green. This is what notices.
     *
     * @return list<array{0: string}>
     */
    public static function weakPasswords(): array
    {
        return [
            'nine characters' => ['Pass12345'],
            'letters only' => ['passwordonly'],
            'digits only' => ['1234567890'],
        ];
    }

    #[DataProvider('weakPasswords')]
    public function test_a_weak_password_is_rejected_everywhere_one_can_be_set(string $password)
    {
        $owner = User::factory()->owner()->create();
        $cashier = User::factory()->cashier()->create();

        // Creating a user.
        $this->actingAs($owner)
            ->post(route('users.store'), $this->userPayload([
                'password' => $password,
                'password_confirmation' => $password,
            ]))
            ->assertSessionHasErrors('password');

        // An administrative reset.
        $this->actingAs($owner)
            ->patch(route('users.reset-password', $cashier->id), [
                'password' => $password,
                'password_confirmation' => $password,
            ])
            ->assertSessionHasErrors('password');

        // A user changing their own.
        $this->actingAs($cashier)
            ->from(route('password.edit'))
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => $password,
                'password_confirmation' => $password,
            ])
            ->assertSessionHasErrors('password');
    }

    public function test_duplicate_username_is_rejected()
    {
        User::factory()->cashier()->create(['username' => 'taken']);

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('users.store'), $this->userPayload(['username' => 'taken']))
            ->assertSessionHasErrors('username');
    }

    public function test_owner_can_change_an_admins_role()
    {
        $owner = User::factory()->owner()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($owner)
            ->patch(route('users.role', $admin->id), ['role' => UserRole::Cashier->value])
            ->assertSessionHasNoErrors();

        $this->assertSame(UserRole::Cashier, $admin->fresh()->role);
    }

    public function test_admin_cannot_manage_another_admin_or_the_owner()
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();

        $this->actingAs($admin)
            ->patch(route('users.role', $otherAdmin->id), ['role' => UserRole::Cashier->value])
            ->assertForbidden();

        $this->actingAs($admin)
            ->patch(route('users.archive', $owner->id))
            ->assertForbidden();

        $this->actingAs($admin)
            ->patch(route('users.archive', $otherAdmin->id))
            ->assertForbidden();
    }

    public function test_user_can_be_archived_and_restored()
    {
        $owner = User::factory()->owner()->create();
        $cashier = User::factory()->cashier()->create();

        $this->actingAs($owner)->patch(route('users.archive', $cashier->id))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($cashier);

        $this->actingAs($owner)->patch(route('users.restore', $cashier->id))->assertSessionHasNoErrors();
        $this->assertNull($cashier->fresh()->deleted_at);
    }

    public function test_owner_cannot_be_archived()
    {
        $owner = User::factory()->owner()->create();
        $secondOwnerAttempt = User::factory()->admin()->create();

        // No actor outranks the Owner, so even another Owner-level request is 403.
        $this->actingAs($secondOwnerAttempt)
            ->patch(route('users.archive', $owner->id))
            ->assertForbidden();

        $this->assertNull($owner->fresh()->deleted_at);
    }

    public function test_archived_users_remain_visible_under_the_archived_filter()
    {
        $owner = User::factory()->owner()->create(['name' => 'The Owner']);
        $archived = User::factory()->cashier()->create(['name' => 'Gone Cashier']);
        $archived->delete();

        $this->actingAs($owner)->get(route('users', ['status' => 'archived']))
            ->assertInertia(fn ($page) => $page->has('users.data', 1)->where('users.data.0.name', 'Gone Cashier'));

        // Default (active) view hides them — only the active Owner remains.
        $this->actingAs($owner)->get(route('users'))
            ->assertInertia(fn ($page) => $page->has('users.data', 1)->where('users.data.0.name', 'The Owner'));
    }

    public function test_reset_password_updates_the_hash_and_forces_logout()
    {
        $owner = User::factory()->owner()->create();
        $cashier = User::factory()->cashier()->create(['password' => Hash::make('oldpassword')]);

        // Seed a live DB session row for the target so we can assert it is cleared.
        DB::table('sessions')->insert([
            'id' => 'sess-target',
            'user_id' => $cashier->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'x',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($owner)
            ->patch(route('users.reset-password', $cashier->id), [
                'password' => 'brandnewpass1',
                'password_confirmation' => 'brandnewpass1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('brandnewpass1', $cashier->fresh()->password));
        $this->assertDatabaseHas('login_history', [
            'user_id' => $cashier->id,
            'event_type' => LoginEventType::ForcedLogout->value,
        ]);
        $this->assertDatabaseMissing('sessions', ['id' => 'sess-target']);
    }

    public function test_cashier_is_forbidden_from_every_user_management_route()
    {
        $cashier = User::factory()->cashier()->create();
        $target = User::factory()->cashier()->create();

        $this->actingAs($cashier)->get(route('users'))->assertForbidden();
        $this->actingAs($cashier)->post(route('users.store'), $this->userPayload())->assertForbidden();
        $this->actingAs($cashier)->patch(route('users.role', $target->id), ['role' => UserRole::Cashier->value])->assertForbidden();
        $this->actingAs($cashier)->patch(route('users.reset-password', $target->id), ['password' => 'password123', 'password_confirmation' => 'password123'])->assertForbidden();
        $this->actingAs($cashier)->patch(route('users.archive', $target->id))->assertForbidden();
    }

    public function test_index_renders_with_assignable_roles_for_the_actor()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('users'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('assignableRoles', [UserRole::Cashier->value]));

        $this->actingAs(User::factory()->owner()->create())
            ->get(route('users'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('assignableRoles', [UserRole::Admin->value, UserRole::Cashier->value]));
    }
}
