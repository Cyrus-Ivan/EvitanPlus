<?php

namespace Tests\Feature\LoginHistory;

use App\Enums\LoginEventType;
use App\Models\Instance;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Login History calendar.
 *
 * Every test pins the clock, because the screen's window is the CURRENT month by
 * default and the factory's own `occurred_at` spans the last thirty days — which
 * straddles two months and would put a row outside the month under test on most
 * days of the year.
 *
 * `MONTH` is January 2026 throughout, so a day's index into `days` is its date
 * minus one (`days.9` is the 10th) and the month has 31 cells.
 */
class LoginHistoryDisplayTest extends TestCase
{
    use RefreshDatabase;

    /** A moment inside the month under test. */
    private const NOW = '2026-01-15 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** An event at a fixed moment, with `created_at` matching as the recorder writes it. */
    private function eventAt(User $user, string $at, ?LoginEventType $type = null): LoginHistory
    {
        $factory = LoginHistory::factory()->for($user);

        if ($type !== null) {
            $factory = $factory->event($type);
        }

        return $factory->create(['occurred_at' => $at, 'created_at' => $at]);
    }

    public function test_cashier_sees_only_their_own_events()
    {
        $cashier = User::factory()->cashier()->create();
        $other = User::factory()->cashier()->create();

        $this->eventAt($cashier, '2026-01-10 08:00:00');
        $this->eventAt($cashier, '2026-01-10 17:00:00');
        $this->eventAt($cashier, '2026-01-11 08:00:00');
        $this->eventAt($other, '2026-01-10 08:00:00');
        $this->eventAt($other, '2026-01-12 08:00:00');

        $this->actingAs($cashier)->get(route('login-history'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('total', 3)
                ->where('canViewAll', false)
                ->where('users', [])
                // Both of the 10th's, in one cell.
                ->has('days.9.entries', 2)
                ->has('days.10.entries', 1)
                // The other cashier's day is empty for this viewer.
                ->has('days.11.entries', 0)
            );
    }

    public function test_cashier_cannot_widen_scope_via_the_user_parameter()
    {
        $cashier = User::factory()->cashier()->create();
        $other = User::factory()->cashier()->create();

        $this->eventAt($cashier, '2026-01-10 08:00:00');
        $this->eventAt($other, '2026-01-10 09:00:00');
        $this->eventAt($other, '2026-01-10 10:00:00');

        // Even when passing another user's id, the cashier still only sees theirs.
        $this->actingAs($cashier)->get(route('login-history', ['user' => $other->id]))
            ->assertInertia(fn ($page) => $page->where('total', 1));
    }

    public function test_admin_sees_everyone()
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->cashier()->create();

        $this->eventAt($admin, '2026-01-10 08:00:00');
        $this->eventAt($admin, '2026-01-10 17:00:00');
        $this->eventAt($cashier, '2026-01-11 08:00:00');
        $this->eventAt($cashier, '2026-01-11 17:00:00');

        $this->actingAs($admin)->get(route('login-history'))
            ->assertInertia(fn ($page) => $page->where('total', 4)->where('canViewAll', true));
    }

    public function test_admin_can_filter_by_user()
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->cashier()->create();

        $this->eventAt($admin, '2026-01-10 08:00:00');
        $this->eventAt($admin, '2026-01-10 17:00:00');
        $this->eventAt($cashier, '2026-01-11 08:00:00');
        $this->eventAt($cashier, '2026-01-11 12:00:00');
        $this->eventAt($cashier, '2026-01-11 17:00:00');

        $this->actingAs($admin)->get(route('login-history', ['user' => $cashier->id]))
            ->assertInertia(fn ($page) => $page->where('total', 3)->has('days.10.entries', 3));
    }

    public function test_can_filter_by_event_type()
    {
        $owner = User::factory()->owner()->create();

        $this->eventAt($owner, '2026-01-10 08:00:00', LoginEventType::Login);
        $this->eventAt($owner, '2026-01-11 08:00:00', LoginEventType::Login);
        $this->eventAt($owner, '2026-01-12 08:00:00', LoginEventType::ApplicationCrash);

        $this->actingAs($owner)->get(route('login-history', ['event' => LoginEventType::ApplicationCrash->value]))
            ->assertInertia(fn ($page) => $page->where('total', 1)
                ->where('days.11.entries.0.event_type', LoginEventType::ApplicationCrash->value));
    }

    /**
     * A day reads FORWARD, unlike every other index in the app. A paginated list
     * is newest-first because its far end is unbounded; a day is bounded, and a
     * cell that truncates should hide the end of the day rather than its start.
     */
    public function test_a_days_events_are_oldest_first()
    {
        $owner = User::factory()->owner()->create();

        $this->eventAt($owner, '2026-01-10 17:30:00', LoginEventType::Logout);
        $this->eventAt($owner, '2026-01-10 08:00:00', LoginEventType::Login);

        $this->actingAs($owner)->get(route('login-history'))
            ->assertInertia(fn ($page) => $page
                ->where('days.9.entries.0.event_type', LoginEventType::Login->value)
                ->where('days.9.entries.1.event_type', LoginEventType::Logout->value)
            );
    }

    /**
     * The month is the window, so its own edges are what include or exclude a
     * row — there is no free date range any more.
     */
    public function test_the_month_includes_its_last_second_and_excludes_the_neighbouring_months()
    {
        $owner = User::factory()->owner()->create();

        $this->eventAt($owner, '2025-12-31 23:59:59');
        $this->eventAt($owner, '2026-01-01 00:00:00');
        $this->eventAt($owner, '2026-01-31 23:59:59');
        $this->eventAt($owner, '2026-02-01 00:00:00');

        $this->actingAs($owner)->get(route('login-history', ['month' => 1, 'year' => 2026]))
            ->assertInertia(fn ($page) => $page
                ->where('total', 2)
                ->has('days.0.entries', 1)
                ->has('days.30.entries', 1)
            );
    }

    public function test_a_month_can_be_selected_and_is_echoed_back()
    {
        $owner = User::factory()->owner()->create();

        $this->eventAt($owner, '2025-11-12 08:00:00');

        $this->actingAs($owner)->get(route('login-history', ['month' => 11, 'year' => 2025]))
            ->assertInertia(fn ($page) => $page
                ->where('total', 1)
                ->where('filters.month', 11)
                ->where('filters.year', 2025)
                ->where('filters.label', 'November 2025')
                // The ‹ › steppers' destinations, resolved server-side so a year
                // rollover is decided in one place.
                ->where('filters.prev_month', 10)
                ->where('filters.prev_year', 2025)
                ->where('filters.next_month', 12)
                ->where('filters.next_year', 2025)
                // November has 30 days, and every one of them is a cell.
                ->has('days', 30)
            );
    }

    /** A December selection is where a stepper's year rollover actually bites. */
    public function test_december_steps_forward_into_the_next_year()
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get(route('login-history', ['month' => 12, 'year' => 2025]))
            ->assertInertia(fn ($page) => $page
                ->where('filters.next_month', 1)
                ->where('filters.next_year', 2026)
                ->where('filters.prev_month', 11)
                ->where('filters.prev_year', 2025)
            );
    }

    /**
     * A hand-typed or bookmarked month falls back to this one rather than 500ing
     * or 422ing — there is nothing a person can do about a bad month in a URL,
     * and an error page is a worse answer than the current month.
     */
    public function test_an_impossible_month_falls_back_to_the_current_one()
    {
        $owner = User::factory()->owner()->create();

        $this->eventAt($owner, '2026-01-10 08:00:00');

        $this->actingAs($owner)->get(route('login-history', ['month' => 13, 'year' => 'garbage']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('total', 1)
                ->where('filters.month', 1)
                ->where('filters.year', 2026)
                ->where('filters.label', 'January 2026')
            );
    }

    /**
     * `occurred_at` is nullable by design — an unclean exit is reconstructed on
     * the next launch and has no accurate time of its own — so `created_at`
     * carries it. That decides both whether the row is in the month AND which
     * cell it lands in, which are the same COALESCE the row displays.
     */
    public function test_null_occurred_at_is_bucketed_via_created_at()
    {
        $owner = User::factory()->owner()->create();

        LoginHistory::factory()->for($owner)->event(LoginEventType::ApplicationCrash)
            ->create(['occurred_at' => null, 'created_at' => '2026-01-11 08:00:00']);
        LoginHistory::factory()->for($owner)->event(LoginEventType::ApplicationCrash)
            ->create(['occurred_at' => null, 'created_at' => '2025-12-20 08:00:00']);

        $this->actingAs($owner)->get(route('login-history'))
            ->assertInertia(fn ($page) => $page
                ->where('total', 1)
                ->has('days.10.entries', 1)
                ->where('days.10.entries.0.occurred_at', null)
            );
    }

    /**
     * Zero-filled on purpose: a day missing from the grid reads as "no data", an
     * empty day reads as "nobody signed in", and only the second is a fact
     * anybody can act on. It is also what keeps the grid rectangular — the
     * frontend pads the leading week from the first day's ISO weekday.
     */
    public function test_a_month_with_no_events_is_still_a_full_grid()
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get(route('login-history'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('total', 0)
                ->has('days', 31)
                ->where('days.0.date', '2026-01-01')
                // ISO weekday, Monday = 1. 1 January 2026 is a Thursday.
                ->where('days.0.weekday', 4)
                ->where('days.0.day', 1)
                ->has('days.0.entries', 0)
            );
    }

    public function test_cashier_scoping_still_holds_within_a_month()
    {
        $cashier = User::factory()->cashier()->create();
        $other = User::factory()->cashier()->create();

        $this->eventAt($cashier, '2026-01-11 08:00:00');
        $this->eventAt($other, '2026-01-11 08:00:00');

        $this->actingAs($cashier)->get(route('login-history', ['month' => 1, 'year' => 2026]))
            ->assertInertia(fn ($page) => $page->where('total', 1));
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

        $this->eventAt($owner, '2026-01-10 08:00:00');
        $this->eventAt($owner, '2026-01-10 17:00:00');

        $this->actingAs($owner)->get(route('login-history'))
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'local')
                // Empty because no test device holds a credential, not because
                // the scope is local: the picker's roster is read on every
                // render now, and this is what a failed read leaves.
                ->where('branches', [])
                // Null, not false: nothing cross-branch was asked for, so there
                // is nothing to report as unavailable.
                ->where('consolidatedOnline', null)
                ->where('days.9.entries.0.branch', 'TST')
                ->where('days.9.entries.1.branch', 'TST')
            );
    }

    /**
     * This branch's own id names the same sessions `local` does, and `local`
     * names them better — it works offline and includes what this device has not
     * pushed yet. So the picker offers it only as `local`, and a hand-typed or
     * bookmarked link asking for it lands on the value the control can show.
     */
    public function test_this_branchs_own_id_is_the_local_scope()
    {
        $owner = User::factory()->owner()->create();

        $this->eventAt($owner, '2026-01-10 08:00:00');

        $this->actingAs($owner)
            ->get(route('login-history', ['scope' => Instance::current()->branch_id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'local')
                // Not false: no cross-branch read was attempted, because there
                // was nothing cross-branch about the request.
                ->where('consolidatedOnline', null)
                ->where('days.9.entries.0.branch', 'TST')
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

        $this->eventAt($cashier, '2026-01-10 08:00:00');

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

        $this->eventAt($admin, '2026-01-10 08:00:00');
        $this->eventAt($admin, '2026-01-10 17:00:00');

        $this->actingAs($admin)->get(route('login-history', ['scope' => 'all']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'local')
                ->where('consolidatedOnline', false)
                ->where('total', 2)
                ->where('days.9.entries.0.branch', 'TST')
            );
    }
}
