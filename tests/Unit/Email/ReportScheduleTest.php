<?php

namespace Tests\Unit\Email;

use App\Support\Email\ReportSchedule;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Tests\Feature\Reports\ScheduledReportEmailTest;

/**
 * The two questions a schedule answers: when should it most recently have fired,
 * and what does a firing at that instant cover.
 *
 * A plain PHPUnit test — nothing here touches the database. Whether a due window
 * has already gone out is the command's business
 * ({@see ScheduledReportEmailTest}).
 */
class ReportScheduleTest extends TestCase
{
    private function schedule(string $kind, string $time = '08:00', string $days = '1,2,3,4,5,6,7'): ReportSchedule
    {
        return ReportSchedule::fromValues($kind, [
            'report_email_'.$kind.'_enabled' => '1',
            'report_email_'.$kind.'_time' => $time,
            'report_email_daily_days' => $days,
        ]);
    }

    private function dueAt(ReportSchedule $schedule, string $now): ?string
    {
        return $schedule->lastDueAt(Carbon::parse($now))?->toDateTimeString();
    }

    /** @return array{0: string, 1: string} */
    private function window(ReportSchedule $schedule, string $now): array
    {
        $due = $schedule->lastDueAt(Carbon::parse($now));
        $this->assertNotNull($due);
        [$from, $to] = $schedule->windowFor($due);

        return [$from->toDateTimeString(), $to->toDateTimeString()];
    }

    // --------------------------------------------------------------- daily

    public function test_daily_is_not_due_until_its_time_has_arrived(): void
    {
        $schedule = $this->schedule('daily');

        // 07:59 against 08:00 — the answer is yesterday's firing, not today's.
        $this->assertSame('2026-03-10 08:00:00', $this->dueAt($schedule, '2026-03-11 07:59'));
        $this->assertSame('2026-03-11 08:00:00', $this->dueAt($schedule, '2026-03-11 08:00'));
        $this->assertSame('2026-03-11 08:00:00', $this->dueAt($schedule, '2026-03-11 23:59'));
    }

    public function test_daily_covers_the_day_before_it_fires(): void
    {
        $this->assertSame(
            ['2026-03-10 00:00:00', '2026-03-10 23:59:59'],
            $this->window($this->schedule('daily'), '2026-03-11 08:00'),
        );
    }

    public function test_daily_skips_back_to_the_most_recent_ticked_weekday(): void
    {
        // Monday and Thursday. 2026-03-14 is a Saturday.
        $schedule = $this->schedule('daily', days: '1,4');

        $this->assertSame('2026-03-12 08:00:00', $this->dueAt($schedule, '2026-03-14 10:00'));
        $this->assertSame(
            ['2026-03-11 00:00:00', '2026-03-11 23:59:59'],
            $this->window($schedule, '2026-03-14 10:00'),
        );
    }

    public function test_a_single_ticked_weekday_before_its_time_reaches_back_a_full_week(): void
    {
        // Mondays only, and it is Monday morning before the send time. Seven
        // days back, which is why the walk is eight steps and not seven.
        $schedule = $this->schedule('daily', days: '1');

        $this->assertSame('2026-03-09 08:00:00', $this->dueAt($schedule, '2026-03-16 06:00'));
    }

    public function test_daily_with_no_weekday_ticked_is_never_due(): void
    {
        $this->assertNull($this->dueAt($this->schedule('daily', days: ''), '2026-03-11 23:00'));
    }

    // -------------------------------------------------------------- weekly

    public function test_weekly_fires_on_a_monday_for_the_week_before_it(): void
    {
        $schedule = $this->schedule('weekly');

        // 2026-03-16 is a Monday.
        $this->assertSame('2026-03-16 08:00:00', $this->dueAt($schedule, '2026-03-16 08:00'));
        $this->assertSame('2026-03-16 08:00:00', $this->dueAt($schedule, '2026-03-20 12:00'));
        // Before Monday's send time, the answer is the Monday before.
        $this->assertSame('2026-03-09 08:00:00', $this->dueAt($schedule, '2026-03-16 07:00'));

        $this->assertSame(
            ['2026-03-09 00:00:00', '2026-03-15 23:59:59'],
            $this->window($schedule, '2026-03-16 08:00'),
        );
    }

    // ------------------------------------------------------------- monthly

    public function test_monthly_fires_on_the_first_monday_for_the_previous_month(): void
    {
        $schedule = $this->schedule('monthly');

        // The first Monday of April 2026 is the 6th.
        $this->assertSame('2026-04-06 08:00:00', $this->dueAt($schedule, '2026-04-06 08:00'));
        $this->assertSame('2026-04-06 08:00:00', $this->dueAt($schedule, '2026-04-30 23:00'));

        $this->assertSame(
            ['2026-03-01 00:00:00', '2026-03-31 23:59:59'],
            $this->window($schedule, '2026-04-06 08:00'),
        );
    }

    public function test_monthly_before_this_months_first_monday_reaches_into_the_previous_month(): void
    {
        $schedule = $this->schedule('monthly');

        // 2026-04-02 is before the 6th, so the answer is March's first Monday,
        // the 2nd — which covers February.
        $this->assertSame('2026-03-02 08:00:00', $this->dueAt($schedule, '2026-04-02 08:00'));
        $this->assertSame(
            ['2026-02-01 00:00:00', '2026-02-28 23:59:59'],
            $this->window($schedule, '2026-04-02 08:00'),
        );
    }

    public function test_a_month_beginning_on_a_monday_fires_on_the_first(): void
    {
        // 2026-06-01 is a Monday: the first Monday is the 1st, not the 8th.
        $this->assertSame('2026-06-01 08:00:00', $this->dueAt($this->schedule('monthly'), '2026-06-01 09:00'));
    }

    public function test_a_month_whose_first_monday_is_the_seventh(): void
    {
        // 2026-09-01 is a Tuesday, so the first Monday is the 7th.
        $this->assertSame('2026-09-07 08:00:00', $this->dueAt($this->schedule('monthly'), '2026-09-10 08:00'));
    }

    public function test_monthly_crosses_the_year_boundary(): void
    {
        // Before January 2026's first Monday (the 5th): December 2025's first
        // Monday was the 1st, covering November.
        $this->assertSame(
            ['2025-11-01 00:00:00', '2025-11-30 23:59:59'],
            $this->window($this->schedule('monthly'), '2026-01-03 08:00'),
        );
    }

    // -------------------------------------------------------------- yearly

    public function test_yearly_fires_on_the_first_monday_for_the_previous_year(): void
    {
        $schedule = $this->schedule('yearly');

        // The first Monday of 2026 is the 5th of January.
        $this->assertSame('2026-01-05 08:00:00', $this->dueAt($schedule, '2026-01-05 08:00'));
        $this->assertSame('2026-01-05 08:00:00', $this->dueAt($schedule, '2026-11-20 08:00'));

        $this->assertSame(
            ['2025-01-01 00:00:00', '2025-12-31 23:59:59'],
            $this->window($schedule, '2026-06-01 08:00'),
        );
    }

    public function test_yearly_before_this_years_first_monday_covers_the_year_before_last(): void
    {
        // 2026-01-02 precedes the 5th, so the answer is 2025's first Monday
        // (the 6th of January), which covers 2024.
        $this->assertSame('2025-01-06 08:00:00', $this->dueAt($this->schedule('yearly'), '2026-01-02 08:00'));
        $this->assertSame(
            ['2024-01-01 00:00:00', '2024-12-31 23:59:59'],
            $this->window($this->schedule('yearly'), '2026-01-02 08:00'),
        );
    }

    // ------------------------------------------------------------- parsing

    public function test_an_off_grid_or_unparseable_time_reads_as_the_default(): void
    {
        $this->assertSame('08:00', ReportSchedule::normalizeTime('08:07'));
        $this->assertSame('08:00', ReportSchedule::normalizeTime('25:00'));
        $this->assertSame('08:00', ReportSchedule::normalizeTime('nonsense'));
        $this->assertSame('08:00', ReportSchedule::normalizeTime(''));
        $this->assertSame('23:45', ReportSchedule::normalizeTime('23:45'));
    }

    public function test_the_weekday_list_is_sorted_deduped_and_bounded(): void
    {
        $this->assertSame([1, 3, 5], ReportSchedule::parseDays('5,3,1,3'));
        $this->assertSame([1, 2], ReportSchedule::parseDays('0,1,2,8,x'));
        $this->assertSame([], ReportSchedule::parseDays(''));
        $this->assertSame('1,4', ReportSchedule::encodeDays(['4', '1', '9']));
    }

    public function test_the_times_offered_are_every_quarter_hour(): void
    {
        $times = ReportSchedule::times();

        $this->assertCount(96, $times);
        $this->assertSame('00:00', $times[0]);
        $this->assertSame('23:45', $times[95]);
        $this->assertNotContains('08:07', $times);
    }
}
