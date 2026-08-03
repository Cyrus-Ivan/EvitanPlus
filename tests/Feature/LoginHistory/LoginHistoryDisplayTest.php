<?php

namespace Tests\Feature\LoginHistory;

use App\Enums\LoginEventType;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginHistoryDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_sees_only_their_own_events()
    {
        $cashier = User::factory()->cashier()->create();
        $other = User::factory()->cashier()->create();

        LoginHistory::factory()->count(3)->for($cashier)->create();
        LoginHistory::factory()->count(2)->for($other)->create();

        $this->actingAs($cashier)->get(route('login-history'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 3)
                ->where('canViewAll', false)
                ->where('users', [])
            );
    }

    public function test_cashier_cannot_widen_scope_via_the_user_parameter()
    {
        $cashier = User::factory()->cashier()->create();
        $other = User::factory()->cashier()->create();

        LoginHistory::factory()->count(3)->for($cashier)->create();
        LoginHistory::factory()->count(2)->for($other)->create();

        // Even when passing another user's id, the cashier still only sees theirs.
        $this->actingAs($cashier)->get(route('login-history', ['user' => $other->id]))
            ->assertInertia(fn ($page) => $page->has('entries.data', 3));
    }

    public function test_admin_sees_everyone()
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->cashier()->create();

        LoginHistory::factory()->count(2)->for($admin)->create();
        LoginHistory::factory()->count(2)->for($cashier)->create();

        $this->actingAs($admin)->get(route('login-history'))
            ->assertInertia(fn ($page) => $page->has('entries.data', 4)->where('canViewAll', true));
    }

    public function test_admin_can_filter_by_user()
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->cashier()->create();

        LoginHistory::factory()->count(2)->for($admin)->create();
        LoginHistory::factory()->count(3)->for($cashier)->create();

        $this->actingAs($admin)->get(route('login-history', ['user' => $cashier->id]))
            ->assertInertia(fn ($page) => $page->has('entries.data', 3));
    }

    public function test_can_filter_by_event_type()
    {
        $owner = User::factory()->owner()->create();

        LoginHistory::factory()->for($owner)->event(LoginEventType::Login)->count(2)->create();
        LoginHistory::factory()->for($owner)->event(LoginEventType::ApplicationCrash)->count(1)->create();

        $this->actingAs($owner)->get(route('login-history', ['event' => LoginEventType::ApplicationCrash->value]))
            ->assertInertia(fn ($page) => $page->has('entries.data', 1)
                ->where('entries.data.0.event_type', LoginEventType::ApplicationCrash->value));
    }

    public function test_entries_are_ordered_newest_first()
    {
        $owner = User::factory()->owner()->create();

        LoginHistory::factory()->for($owner)->event(LoginEventType::Logout)->create(['occurred_at' => now()->subDays(2)]);
        LoginHistory::factory()->for($owner)->event(LoginEventType::Login)->create(['occurred_at' => now()]);

        $this->actingAs($owner)->get(route('login-history'))
            ->assertInertia(fn ($page) => $page->where('entries.data.0.event_type', LoginEventType::Login->value));
    }

    public function test_date_from_is_inclusive_and_excludes_earlier_events()
    {
        $owner = User::factory()->owner()->create();

        LoginHistory::factory()->for($owner)->create(['occurred_at' => '2026-01-09 23:00:00', 'created_at' => '2026-01-09 23:00:00']);
        LoginHistory::factory()->for($owner)->create(['occurred_at' => '2026-01-10 00:30:00', 'created_at' => '2026-01-10 00:30:00']);
        LoginHistory::factory()->for($owner)->create(['occurred_at' => '2026-01-12 08:00:00', 'created_at' => '2026-01-12 08:00:00']);

        $this->actingAs($owner)->get(route('login-history', ['date_from' => '2026-01-10']))
            ->assertInertia(fn ($page) => $page->has('entries.data', 2)->where('filters.date_from', '2026-01-10'));
    }

    public function test_date_to_is_inclusive_of_the_whole_day()
    {
        $owner = User::factory()->owner()->create();

        LoginHistory::factory()->for($owner)->create(['occurred_at' => '2026-01-10 08:00:00', 'created_at' => '2026-01-10 08:00:00']);
        // Late on the To day must still be included (endOfDay boundary).
        LoginHistory::factory()->for($owner)->create(['occurred_at' => '2026-01-12 23:59:00', 'created_at' => '2026-01-12 23:59:00']);
        LoginHistory::factory()->for($owner)->create(['occurred_at' => '2026-01-13 00:30:00', 'created_at' => '2026-01-13 00:30:00']);

        $this->actingAs($owner)->get(route('login-history', ['date_to' => '2026-01-12']))
            ->assertInertia(fn ($page) => $page->has('entries.data', 2)->where('filters.date_to', '2026-01-12'));
    }

    public function test_combined_date_range_returns_only_events_inside_it()
    {
        $owner = User::factory()->owner()->create();

        LoginHistory::factory()->for($owner)->create(['occurred_at' => '2026-01-05 08:00:00', 'created_at' => '2026-01-05 08:00:00']);
        LoginHistory::factory()->for($owner)->create(['occurred_at' => '2026-01-11 08:00:00', 'created_at' => '2026-01-11 08:00:00']);
        LoginHistory::factory()->for($owner)->create(['occurred_at' => '2026-01-20 08:00:00', 'created_at' => '2026-01-20 08:00:00']);

        $this->actingAs($owner)->get(route('login-history', ['date_from' => '2026-01-10', 'date_to' => '2026-01-15']))
            ->assertInertia(fn ($page) => $page->has('entries.data', 1)
                ->where('entries.data.0.occurred_at', fn ($v) => str_starts_with($v, '2026-01-11')));
    }

    public function test_null_occurred_at_is_matched_via_created_at()
    {
        $owner = User::factory()->owner()->create();

        // occurred_at can be null (an unclean exit detected on the next boot);
        // the filter falls back to created_at.
        LoginHistory::factory()->for($owner)->event(LoginEventType::ApplicationCrash)
            ->create(['occurred_at' => null, 'created_at' => '2026-01-11 08:00:00']);
        LoginHistory::factory()->for($owner)->event(LoginEventType::ApplicationCrash)
            ->create(['occurred_at' => null, 'created_at' => '2026-01-20 08:00:00']);

        $this->actingAs($owner)->get(route('login-history', ['date_from' => '2026-01-10', 'date_to' => '2026-01-15']))
            ->assertInertia(fn ($page) => $page->has('entries.data', 1));
    }

    public function test_cashier_scoping_still_holds_with_a_date_range()
    {
        $cashier = User::factory()->cashier()->create();
        $other = User::factory()->cashier()->create();

        LoginHistory::factory()->for($cashier)->create(['occurred_at' => '2026-01-11 08:00:00', 'created_at' => '2026-01-11 08:00:00']);
        LoginHistory::factory()->for($other)->create(['occurred_at' => '2026-01-11 08:00:00', 'created_at' => '2026-01-11 08:00:00']);

        $this->actingAs($cashier)->get(route('login-history', ['date_from' => '2026-01-10', 'date_to' => '2026-01-15']))
            ->assertInertia(fn ($page) => $page->has('entries.data', 1));
    }

    public function test_invalid_date_input_is_ignored()
    {
        $owner = User::factory()->owner()->create();

        LoginHistory::factory()->count(3)->for($owner)->create();

        $this->actingAs($owner)->get(route('login-history', ['date_from' => 'garbage', 'date_to' => '']))
            ->assertInertia(fn ($page) => $page->has('entries.data', 3)
                ->where('filters.date_from', null)
                ->where('filters.date_to', null));
    }

    /**
     * M11.5. The local table has no branch_id and never will — every row in it
     * is this device's by construction, and the push stamps a branch on the way
     * up. So the column is filled from `instance`, and a device that has one
     * fills it for every row.
     */
    public function test_local_rows_carry_this_devices_branch()
    {
        $owner = User::factory()->owner()->create();

        LoginHistory::factory()->count(2)->for($owner)->create();

        $this->actingAs($owner)->get(route('login-history'))
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'local')
                ->where('branches', [])
                // Null, not false: nothing cross-branch was asked for, so there
                // is nothing to report as unavailable.
                ->where('consolidatedOnline', null)
                ->where('entries.data.0.branch', 'TST')
                ->where('entries.data.1.branch', 'TST')
            );
    }

    /**
     * A Cashier is snapped back to their own branch rather than refused — the
     * same route still owes them the local history, exactly as a crafted `user`
     * parameter leaves them looking at their own events.
     */
    public function test_a_cashier_cannot_widen_the_scope_to_other_branches()
    {
        $cashier = User::factory()->cashier()->create();

        LoginHistory::factory()->for($cashier)->create();

        $this->actingAs($cashier)->get(route('login-history', ['scope' => 'all']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'local')
                ->where('consolidatedOnline', null)
            );
    }

    /**
     * The fallback, and the only cross-branch path a credential-free test device
     * can reach: presenting one branch's sessions under an "All branches"
     * heading is worse than saying the read failed, so the scope comes back
     * `local` with the flag false and the screen says so.
     */
    public function test_an_offline_admin_falls_back_to_this_branch_and_says_so()
    {
        $admin = User::factory()->admin()->create();

        LoginHistory::factory()->count(2)->for($admin)->create();

        $this->actingAs($admin)->get(route('login-history', ['scope' => 'all']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'local')
                ->where('consolidatedOnline', false)
                ->has('entries.data', 2)
                ->where('entries.data.0.branch', 'TST')
            );
    }
}
