<?php

namespace Tests\Unit\Reports;

use App\Enums\LoginEventType;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\Reports\AttendanceFigures;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Shift pairing. A shift is a `login` paired with whatever ended it, anchored to
 * the day it STARTED — which is what makes a night shift one entry rather than
 * two half-days either side of midnight.
 *
 * Only `logout` and `forced_logout` carry a time worth believing.
 * `application_crash` is written on the NEXT LAUNCH, so it ends a shift without
 * saying when, and the shift stays open with no hours.
 */
class AttendanceFiguresTest extends TestCase
{
    use RefreshDatabase;

    private function event(User $user, LoginEventType $type, string $at): void
    {
        LoginHistory::create([
            'user_id' => $user->id,
            'event_type' => $type->value,
            'occurred_at' => Carbon::parse($at),
        ]);
    }

    /**
     * @return list<array{date: string, start: Carbon, end: ?Carbon, hours: ?float, open: bool}>
     */
    private function shifts(User $user, string $month = '2026-08'): array
    {
        $from = Carbon::parse($month.'-01')->startOfMonth();

        return (new AttendanceFigures(DB::connection()))
            ->shifts($user->id, $from, $from->copy()->endOfMonth());
    }

    public function test_a_signed_out_shift_carries_its_hours()
    {
        $user = User::factory()->cashier()->create();
        $this->event($user, LoginEventType::Login, '2026-08-03 08:00');
        $this->event($user, LoginEventType::Logout, '2026-08-03 16:30');

        $shifts = $this->shifts($user);

        $this->assertCount(1, $shifts);
        $this->assertSame('2026-08-03', $shifts[0]['date']);
        $this->assertSame(8.5, $shifts[0]['hours']);
        $this->assertFalse($shifts[0]['open']);
    }

    public function test_a_night_shift_is_one_shift_on_the_day_it_started()
    {
        $user = User::factory()->cashier()->create();
        $this->event($user, LoginEventType::Login, '2026-08-03 20:00');
        $this->event($user, LoginEventType::Logout, '2026-08-04 04:00');

        $shifts = $this->shifts($user);

        $this->assertCount(1, $shifts);
        // Anchored to the day it began, not split across midnight.
        $this->assertSame('2026-08-03', $shifts[0]['date']);
        $this->assertSame('2026-08-04', $shifts[0]['end']->toDateString());
        $this->assertSame(8.0, $shifts[0]['hours']);
    }

    public function test_a_forced_logout_closes_a_shift_with_real_hours()
    {
        $user = User::factory()->cashier()->create();
        $this->event($user, LoginEventType::Login, '2026-08-05 09:00');
        $this->event($user, LoginEventType::ForcedLogout, '2026-08-05 11:00');

        $shifts = $this->shifts($user);

        $this->assertSame(2.0, $shifts[0]['hours']);
        $this->assertFalse($shifts[0]['open']);
    }

    public function test_an_unclean_exit_leaves_the_shift_open_with_no_hours()
    {
        $user = User::factory()->cashier()->create();
        $this->event($user, LoginEventType::Login, '2026-08-06 09:00');
        // Written on the next launch — three days later. Believing it would book
        // the whole weekend as worked.
        $this->event($user, LoginEventType::ApplicationCrash, '2026-08-09 08:00');

        $shifts = $this->shifts($user);

        $this->assertCount(1, $shifts);
        $this->assertSame('2026-08-06', $shifts[0]['date']);
        $this->assertNull($shifts[0]['end']);
        $this->assertNull($shifts[0]['hours']);
        $this->assertTrue($shifts[0]['open']);
    }

    public function test_a_second_sign_in_closes_a_dangling_shift()
    {
        $user = User::factory()->cashier()->create();
        $this->event($user, LoginEventType::Login, '2026-08-10 09:00');
        // No sign-out at all before the next day's sign-in.
        $this->event($user, LoginEventType::Login, '2026-08-11 09:00');
        $this->event($user, LoginEventType::Logout, '2026-08-11 17:00');

        $shifts = $this->shifts($user);

        $this->assertCount(2, $shifts);
        $this->assertTrue($shifts[0]['open']);
        $this->assertNull($shifts[0]['hours']);
        $this->assertSame(8.0, $shifts[1]['hours']);
    }

    public function test_a_shift_still_running_is_open()
    {
        $user = User::factory()->cashier()->create();
        $this->event($user, LoginEventType::Login, '2026-08-12 09:00');

        $shifts = $this->shifts($user);

        $this->assertCount(1, $shifts);
        $this->assertTrue($shifts[0]['open']);
    }

    public function test_a_shift_starting_on_the_last_night_is_closed_by_the_next_month()
    {
        $user = User::factory()->cashier()->create();
        $this->event($user, LoginEventType::Login, '2026-08-31 22:00');
        $this->event($user, LoginEventType::Logout, '2026-09-01 06:00');

        $shifts = $this->shifts($user);

        // Read past the window only to CLOSE it; the shift is still August's.
        $this->assertCount(1, $shifts);
        $this->assertSame('2026-08-31', $shifts[0]['date']);
        $this->assertSame(8.0, $shifts[0]['hours']);
    }

    public function test_a_shift_starting_in_the_previous_month_is_not_this_months()
    {
        $user = User::factory()->cashier()->create();
        $this->event($user, LoginEventType::Login, '2026-07-31 22:00');
        $this->event($user, LoginEventType::Logout, '2026-08-01 06:00');

        // July's shift, reported by July. August sees the stray sign-out and
        // discards it rather than inventing a shift with no beginning.
        $this->assertSame([], $this->shifts($user));
        $this->assertCount(1, $this->shifts($user, '2026-07'));
    }

    public function test_another_employees_events_are_not_this_employees_shifts()
    {
        $user = User::factory()->cashier()->create();
        $other = User::factory()->cashier()->create();

        $this->event($user, LoginEventType::Login, '2026-08-03 08:00');
        $this->event($other, LoginEventType::Login, '2026-08-03 09:00');
        $this->event($other, LoginEventType::Logout, '2026-08-03 17:00');
        $this->event($user, LoginEventType::Logout, '2026-08-03 12:00');

        $shifts = $this->shifts($user);

        $this->assertCount(1, $shifts);
        $this->assertSame(4.0, $shifts[0]['hours']);
    }

    public function test_an_event_with_no_occurred_at_falls_back_to_when_it_was_recorded()
    {
        $user = User::factory()->cashier()->create();

        // occurred_at is nullable by design: an event reconstructed on the next
        // launch has no accurate time of its own. Every login-history screen
        // coalesces to created_at, and so does this.
        $this->travelTo(Carbon::parse('2026-08-14 08:00'));
        LoginHistory::create(['user_id' => $user->id, 'event_type' => LoginEventType::Login->value]);
        $this->travelTo(Carbon::parse('2026-08-14 12:00'));
        LoginHistory::create(['user_id' => $user->id, 'event_type' => LoginEventType::Logout->value]);
        $this->travelBack();

        $shifts = $this->shifts($user);

        $this->assertCount(1, $shifts);
        $this->assertSame(4.0, $shifts[0]['hours']);
    }
}
