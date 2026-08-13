<?php

namespace App\Support\Email;

use App\Console\Commands\SendScheduledSalesReport;
use App\Support\Reports\ReportPeriods;
use Carbon\Carbon;

/**
 * One automatic send: which report, on which days, at what time of day.
 *
 * The four kinds are independent — an owner can have the previous day's figures
 * at opening time, last week's on a Monday morning and last month's early in the
 * new month, all at once. {@see ReportEmailSettings::schedules()} builds all four
 * from one settings read and {@see SendScheduledSalesReport} walks them.
 *
 * ## The two questions this answers
 *
 * `lastDueAt()` is "when should this most recently have gone out?" and
 * `windowFor()` is "what does a send at that instant cover?". Neither asks
 * whether it actually went out — that is the marker's job in the command, and
 * keeping the two apart is what lets a device that was shut at the chosen time
 * still send when it opens.
 *
 * `lastDueAt()` therefore always looks BACKWARD to the most recent firing
 * instant that is not in the future, never forward to the next one. A device off
 * for three weeks gets one weekly report, not three: the answer is a position,
 * not a queue.
 *
 * ## Why the time of day lives here and not in the scheduler
 *
 * `bootstrap/app.php` cannot read these values — its closure runs on every
 * artisan invocation, including `migrate` on a device whose tables do not exist
 * yet — so `->dailyAt()` is not available to us. The command ticks on a fixed
 * interval and each schedule decides for itself whether its moment has passed.
 * That interval is why {@see self::TIMES} is a closed quarter-hour list: a time
 * the tick cannot land on is a picker that lies.
 *
 * All Carbon arithmetic here is in the business timezone, which
 * App\Support\Settings\ApplyBusinessTimezone sets as the process default at
 * boot. Nothing in this class sets a timezone of its own.
 */
final class ReportSchedule
{
    /** In the order they are offered, saved and sent. */
    public const KINDS = ['daily', 'weekly', 'monthly', 'yearly'];

    /** ISO-8601 weekday numbers, Monday = 1. Carbon's isoWeekday() spelling. */
    public const DAYS = [1, 2, 3, 4, 5, 6, 7];

    public const DEFAULT_TIME = '08:00';

    /**
     * The quarter-hour a send time must fall on. The command's tick is fifteen
     * minutes wide, so any other minute would fire late by up to fourteen of
     * them with nothing on screen to say so.
     */
    public const MINUTES = ['00', '15', '30', '45'];

    /**
     * @param  list<int>  $days  ISO weekdays this fires on. Daily only; the other
     *                           three kinds derive their day from the calendar.
     */
    public function __construct(
        public readonly string $kind,
        public readonly bool $enabled,
        public readonly string $time = self::DEFAULT_TIME,
        public readonly array $days = self::DAYS,
    ) {}

    /**
     * Build one kind's schedule from the raw settings map.
     *
     * @param  array<string, string>  $values  a {@see ReportEmailSettings::all()} map
     */
    public static function fromValues(string $kind, array $values): self
    {
        return new self(
            kind: $kind,
            enabled: ($values['report_email_'.$kind.'_enabled'] ?? '0') === '1',
            time: self::normalizeTime($values['report_email_'.$kind.'_time'] ?? ''),
            days: $kind === 'daily'
                ? self::parseDays($values['report_email_daily_days'] ?? '')
                : self::DAYS,
        );
    }

    /**
     * The most recent instant this schedule should have fired at, or null when
     * it never has — which for daily means no weekday is ticked at all.
     */
    public function lastDueAt(Carbon $now): ?Carbon
    {
        return match ($this->kind) {
            'weekly' => $this->stepBack($now, $now->copy()->startOfWeek(), fn (Carbon $d): Carbon => $d->copy()->subWeek()),
            'monthly' => $this->stepBack($now, $this->firstMonday($now->copy()->startOfMonth()),
                fn (Carbon $d): Carbon => $this->firstMonday($d->copy()->startOfMonth()->subMonthNoOverflow())),
            'yearly' => $this->stepBack($now, $this->firstMonday($now->copy()->startOfYear()),
                fn (Carbon $d): Carbon => $this->firstMonday($d->copy()->startOfYear()->subYear())),
            default => $this->lastDailyDueAt($now),
        };
    }

    /**
     * The [from, to] report window a send at $dueAt covers — always the period
     * that FINISHED before it. A report on a period still in progress would be a
     * partial figure presented as a final one.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function windowFor(Carbon $dueAt): array
    {
        $anchor = match ($this->kind) {
            // The due day is a Monday, so this lands on the previous Mon–Sun.
            'weekly' => $dueAt->copy()->subWeek(),
            // The due day is the 1st–7th, so no month can be skipped.
            'monthly' => $dueAt->copy()->subMonthNoOverflow(),
            'yearly' => $dueAt->copy()->subYear(),
            default => $dueAt->copy()->subDay(),
        };

        return ReportPeriods::resolveWindow($this->kind, $anchor);
    }

    /**
     * The report period this schedule sends, in {@see ReportPeriods}' vocabulary.
     * One name serves both because the sending period and the report period
     * happen to coincide for every kind offered.
     */
    public function period(): string
    {
        return $this->kind;
    }

    /**
     * Every quarter-hour of the day, as the closed list the picker offers and
     * the validation rule accepts.
     *
     * @return list<string>
     */
    public static function times(): array
    {
        $times = [];

        foreach (range(0, 23) as $hour) {
            foreach (self::MINUTES as $minute) {
                $times[] = str_pad((string) $hour, 2, '0', STR_PAD_LEFT).':'.$minute;
            }
        }

        return $times;
    }

    /**
     * Sort, dedupe and drop anything that is not an ISO weekday. Total, like
     * every other read in {@see ReportEmailSettings}: a stored value that cannot
     * be parsed must degrade to something the screen can show, not throw on the
     * settings page that would fix it.
     *
     * @return list<int>
     */
    public static function parseDays(string $value): array
    {
        $days = array_values(array_unique(array_filter(
            array_map(intval(...), explode(',', $value)),
            fn (int $day): bool => in_array($day, self::DAYS, true),
        )));

        sort($days);

        return $days;
    }

    /**
     * @param  array<int, int|string>  $days
     */
    public static function encodeDays(array $days): string
    {
        return implode(',', self::parseDays(implode(',', $days)));
    }

    /** An unrecognised or off-grid time reads as the default. */
    public static function normalizeTime(string $value): string
    {
        return in_array($value, self::times(), true) ? $value : self::DEFAULT_TIME;
    }

    /**
     * Walk back a day at a time to the most recent ticked weekday whose time has
     * passed. Eight steps, not seven: with only Monday ticked and the clock at
     * Monday 06:00 against an 08:00 send, the answer is LAST Monday, which is
     * seven days back.
     */
    private function lastDailyDueAt(Carbon $now): ?Carbon
    {
        if ($this->days === []) {
            return null;
        }

        for ($back = 0; $back <= 7; $back++) {
            $day = $now->copy()->subDays($back);

            if (! in_array($day->isoWeekday(), $this->days, true)) {
                continue;
            }

            $due = $this->at($day);

            if ($due->lessThanOrEqualTo($now)) {
                return $due;
            }
        }

        return null;
    }

    /**
     * This period's firing instant if it has arrived, else the previous
     * period's. The candidate is a date; $previous turns it into the one before.
     *
     * @param  callable(Carbon): Carbon  $previous
     */
    private function stepBack(Carbon $now, Carbon $candidate, callable $previous): Carbon
    {
        $due = $this->at($candidate);

        return $due->lessThanOrEqualTo($now) ? $due : $this->at($previous($candidate));
    }

    /** The first Monday on or after the start of a month or year. */
    private function firstMonday(Carbon $periodStart): Carbon
    {
        return $periodStart->copy()->addDays((8 - $periodStart->isoWeekday()) % 7);
    }

    /** This schedule's time of day on the given date. */
    private function at(Carbon $day): Carbon
    {
        [$hour, $minute] = array_map(intval(...), explode(':', $this->time));

        return $day->copy()->startOfDay()->setTime($hour, $minute);
    }
}
