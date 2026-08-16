<?php

namespace App\Support\Reports;

use App\Support\Sync\OnlineRead;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Connection;

/**
 * One employee's month: the shifts they worked and the sales they rang, on the
 * same calendar.
 *
 * The connection is an ARGUMENT, exactly as it is for {@see SalesFigures} and
 * {@see AttendanceFigures}. In production it is always the Postgres connection a
 * single {@see OnlineRead} closure opened — this report is online-only and covers
 * the whole business, because a person is not a branch and a branch-local view of
 * their month is not a smaller version of the truth but a wrong one. It is called
 * once per employee inside that same closure when several are emailed at once.
 *
 * ## Two clocks that do not agree, and are not made to
 *
 * A SHIFT belongs to the day it started, so a night shift is one entry on the
 * earlier day. A SALE belongs to the moment it was rung, so the same night
 * shift's after-midnight sales land on the NEXT calendar day. Both are right,
 * and reconciling them would mean inventing a shift boundary for sales — deciding
 * that a sale at 00:30 "belongs" to yesterday's shift, which is only true until
 * two employees overlap or somebody sells while signed out. The screen and the
 * PDF say what the rule is instead.
 *
 * Cost, COGS and profit are absent by construction: {@see SalesFigures} is called
 * with `withCost: false`, so those queries never run and the keys never exist.
 * That is what lets an Admin and the Owner read the same document, and what keeps
 * margin off an email whose recipient list is not a role.
 */
class EmployeePerformanceBuilder
{
    /** The period this report is always over. There is no daily or weekly view. */
    public const PERIOD = 'monthly';

    /**
     * @return array{days: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function build(Connection $db, string $userId, Carbon $from, Carbon $to): array
    {
        $shifts = (new AttendanceFigures($db))->shifts($userId, $from, $to);

        // Null branch throughout: this report is never narrowed to one.
        $figures = new SalesFigures($db, null, $userId);
        // `monthly` buckets by day, which is exactly one row per calendar cell.
        $rows = $figures->rows('bucket', self::PERIOD, $from, $to, withCost: false);
        $totals = $figures->totals($rows, withCost: false);

        $days = $this->days($from, $to, $shifts, $rows);

        return [
            'days' => $days,
            'summary' => $this->summary($days, $totals),
        ];
    }

    /**
     * Every day of the month, worked or not.
     *
     * Zero-filled on purpose: a day missing from the grid reads as "no data",
     * a day showing zero reads as "sold nothing and did not come in", and only
     * the second is a fact anybody can act on. Same rule the consolidated
     * report's per-branch table follows.
     *
     * @param  list<array{date: string, start: Carbon, end: ?Carbon, hours: ?float, open: bool, branch: ?string}>  $shifts
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function days(Carbon $from, Carbon $to, array $shifts, array $rows): array
    {
        $byDate = [];
        foreach ($shifts as $shift) {
            $byDate[$shift['date']][] = [
                'start' => $shift['start']->toIso8601String(),
                'end' => $shift['end']?->toIso8601String(),
                'hours' => $shift['hours'],
                'open' => $shift['open'],
                // Which branch they signed in at. The report spans the business,
                // so the calendar has to say where each shift happened.
                'branch' => $shift['branch'],
                // Rendered as a "+1d" marker rather than a second calendar entry.
                'crosses_midnight' => $shift['end'] !== null
                    && $shift['end']->toDateString() !== $shift['date'],
            ];
        }

        $sales = [];
        foreach ($rows as $row) {
            $sales[$row['bucket']] = $row;
        }

        $days = [];
        foreach (CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()) as $day) {
            $date = $day->toDateString();
            $onThisDay = $byDate[$date] ?? [];

            $days[] = [
                'date' => $date,
                'day' => $day->day,
                // ISO weekday, Monday = 1 — the grid starts on Monday, as
                // ReportSchedule's day picker does.
                'weekday' => $day->dayOfWeekIso,
                'shifts' => $onThisDay,
                // Null, not 0: a day whose only shift is open has unknown hours,
                // which is not the same as a day off.
                'hours' => $this->hoursOf($onThisDay),
                'transactions' => (int) ($sales[$date]['transactions'] ?? 0),
                'gross_sales' => $sales[$date]['gross_sales'] ?? '0.00',
                'returns' => $sales[$date]['returns'] ?? '0.00',
                'defects' => $sales[$date]['defects'] ?? '0.00',
                'net_sales' => $sales[$date]['net_sales'] ?? '0.00',
            ];
        }

        return $days;
    }

    /**
     * The month's headline figures.
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<string, mixed>  $totals
     * @return array<string, mixed>
     */
    private function summary(array $days, array $totals): array
    {
        $worked = 0;
        $shifts = 0;
        $openShifts = 0;
        $hours = 0.0;
        $byBranch = [];

        foreach ($days as $day) {
            if ($day['shifts'] !== []) {
                $worked++;
            }

            foreach ($day['shifts'] as $shift) {
                $shifts++;

                if ($shift['open']) {
                    $openShifts++;

                    continue;
                }

                $hours += (float) $shift['hours'];

                // Open shifts are excluded here for the same reason they are
                // excluded from the total above: they have no hours to add. So
                // the split always foots to `hours`, and never to `shifts`.
                if ($shift['branch'] !== null) {
                    $code = (string) $shift['branch'];
                    $byBranch[$code] = ($byBranch[$code] ?? 0.0) + (float) $shift['hours'];
                }
            }
        }

        $hours = round($hours, 2);
        ksort($byBranch);

        return [
            'days_worked' => $worked,
            'shifts' => $shifts,
            // Surfaced next to the hours it is deliberately missing from, so the
            // total never has to be explained twice.
            'open_shifts' => $openShifts,
            'hours' => $hours,
            // Where those hours were worked. Empty when nothing closed, and
            // empty on SQLite, whose login_history has no branch at all.
            'hours_by_branch' => array_values(array_map(
                fn (string $code, float $worked): array => ['code' => $code, 'hours' => round($worked, 2)],
                array_keys($byBranch),
                $byBranch,
            )),
            'transactions' => $totals['transactions'],
            'gross_sales' => $totals['gross_sales'],
            'returns' => $totals['returns'],
            'defects' => $totals['defects'],
            'net_sales' => $totals['net_sales'],
            // Null rather than a division by zero — and null for a month whose
            // every shift is open, where the honest answer is "not from here".
            'sales_per_hour' => $hours > 0
                ? number_format((float) $totals['net_sales'] / $hours, 2, '.', '')
                : null,
        ];
    }

    /**
     * A day's worked hours, or null when nothing about the day is knowable.
     *
     * @param  list<array<string, mixed>>  $shifts
     */
    private function hoursOf(array $shifts): ?float
    {
        $closed = array_filter($shifts, fn (array $shift): bool => ! $shift['open']);

        if ($closed === []) {
            return null;
        }

        return round(array_sum(array_column($closed, 'hours')), 2);
    }
}
