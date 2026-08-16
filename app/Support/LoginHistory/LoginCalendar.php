<?php

namespace App\Support\LoginHistory;

use App\Http\Controllers\LoginHistoryController;
use App\Support\Reports\EmployeePerformanceBuilder;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 * A month of login events, cut into calendar days.
 *
 * The one copy of that shaping, shared by both of {@see LoginHistoryController}'s
 * query paths — the local SQLite read and the consolidated Postgres one. It is
 * deliberately given rows that are ALREADY mapped and ALREADY localised rather
 * than a connection: unlike {@see EmployeePerformanceBuilder} there is nothing to
 * aggregate here and no SQL to write, so the dialect differences stay where the
 * controller already handles them and this class cannot acquire one.
 *
 * ## Which day a row belongs to
 *
 * Its `occurred_at`, falling back to `created_at` — the same COALESCE the
 * controller filters and orders on, and the same value the row displays. There
 * is exactly one "when" for a login event and every surface must agree on it, or
 * a row filtered into the month renders in no cell at all.
 *
 * That timestamp is a BUSINESS-LOCAL Carbon by the time it arrives: local rows
 * through the model's `datetime` cast, upstream rows through the controller's
 * `localise()`. Bucketing on anything else — a raw string, a UTC moment — puts
 * an event rung at 00:30 in Manila on the previous day's cell, which is the same
 * eight-hour trap the window bounds carry an offset to avoid.
 *
 * ## Every day of the month, event or not
 *
 * Zero-filled, for the reason {@see EmployeePerformanceBuilder::days()} states: a
 * day missing from the grid reads as "no data", a day showing nothing reads as
 * "nobody signed in", and only the second is a fact anybody can act on. It is
 * also what keeps the grid rectangular — the frontend pads the leading week from
 * the first day's ISO weekday and can only do so if that day is present.
 */
class LoginCalendar
{
    /**
     * The month's days, each carrying its own events oldest-first.
     *
     * The entries are expected in ascending order already (the controller orders
     * both reads that way) and are bucketed without re-sorting, so a day reads
     * forward as it happened.
     *
     * @param  list<array<string, mixed>>  $entries  Mapped rows, ascending by the COALESCE'd timestamp.
     * @return list<array{date: string, day: int, weekday: int, entries: list<array<string, mixed>>}>
     */
    public function days(Carbon $from, Carbon $to, array $entries): array
    {
        $byDate = [];

        foreach ($entries as $entry) {
            $byDate[$this->dateOf($entry)][] = $entry;
        }

        $days = [];

        foreach (CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()) as $day) {
            $date = $day->toDateString();

            $days[] = [
                'date' => $date,
                'day' => $day->day,
                // ISO weekday, Monday = 1 — the grid starts on Monday, as the
                // attendance calendar does.
                'weekday' => $day->dayOfWeekIso,
                'entries' => $byDate[$date] ?? [],
            ];
        }

        return $days;
    }

    /**
     * The calendar date one row belongs to.
     *
     * `occurred_at` is nullable by design — an unclean exit is reconstructed on
     * the next launch and has no accurate time of its own — so `created_at`
     * carries it, exactly as the filter and the displayed "When" do.
     *
     * @param  array<string, mixed>  $entry
     */
    private function dateOf(array $entry): string
    {
        $when = $entry['occurred_at'] ?? $entry['created_at'];

        return $when instanceof Carbon ? $when->toDateString() : Carbon::parse((string) $when)->toDateString();
    }
}
