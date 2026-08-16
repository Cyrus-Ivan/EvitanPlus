<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Controllers\EmployeePerformanceController;
use App\Http\Controllers\LoginHistoryController;
use App\Support\Reports\ReportPeriods;
// The base Carbon, because that is what ReportPeriods::resolveWindow() is
// declared to hand back. Callers must hint this class and not Laravel's
// subclass: `Illuminate\Support\Carbon` is narrower, and hinting it turns a
// window that resolved perfectly into a TypeError — one that a screen wrapping
// its read in `rescue()` will swallow and report as being offline.
use Carbon\Carbon;

/**
 * A screen whose window is ONE MONTH, picked by a month and a year rather than
 * by a free date range.
 *
 * Two screens ask that question and must answer it identically: employee
 * performance ({@see EmployeePerformanceController}, which has never had another
 * period) and login history ({@see LoginHistoryController}, whose date range
 * became a month when its table became a calendar). Both render the same control
 * — two selects flanked by ‹ › steppers — and both need the same three things
 * behind it, which is why this is a trait rather than a fourth copy.
 *
 * The window itself still comes from {@see ReportPeriods}: one copy of what a
 * month is, shared with the sales report and the scheduled send.
 *
 * ## Lenient on the way in, exact on the way out
 *
 * A GET parameter is never validated here, only constrained. A hand-typed or
 * bookmarked `?month=13` falls back to the current month rather than 500ing or
 * 422ing, because there is nothing a person can do about a bad month in a URL
 * and an empty error page is a worse answer than this month. The POST paths that
 * act on a month (emailing a report) validate properly in their FormRequest
 * first, and resolve the window through the same method afterwards, so the two
 * surfaces can never disagree about which days they covered.
 */
trait NormalizesMonthFilter
{
    /** How many years back the year picker offers, this one included. */
    private const YEARS_OFFERED = 6;

    /**
     * The selected month, as a [from, to] window.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function monthWindow(mixed $month, mixed $year): array
    {
        $m = (int) $month;
        $y = (int) $year;

        $anchor = ($m >= 1 && $m <= 12 && $y >= 2000 && $y <= 2100)
            ? Carbon::create($y, $m, 1)
            : Carbon::now()->startOfMonth();

        return ReportPeriods::resolveWindow('monthly', $anchor);
    }

    /**
     * What the month/year selects and the ‹ › steppers need.
     *
     * The neighbours are computed HERE rather than in the browser so a year
     * rollover is decided in one place, and with `NoOverflow` so stepping from
     * the 31st of a month never lands two months away.
     *
     * @return array<string, int|string>
     */
    private function monthFilters(Carbon $from): array
    {
        $prev = $from->copy()->subMonthNoOverflow();
        $next = $from->copy()->addMonthNoOverflow();

        return [
            'month' => $from->month,
            'year' => $from->year,
            'label' => ReportPeriods::label('monthly', $from),
            'prev_month' => $prev->month,
            'prev_year' => $prev->year,
            'next_month' => $next->month,
            'next_year' => $next->year,
        ];
    }

    /**
     * The years the picker offers: this one and the five before it.
     *
     * A fixed window rather than a query over the data — the earliest year this
     * DEVICE holds is not the earliest year the business holds, and a list built
     * from the local mirror would quietly hide a branch's older months.
     *
     * @return list<int>
     */
    private function yearsOffered(): array
    {
        $current = (int) Carbon::now()->year;

        return range($current, $current - (self::YEARS_OFFERED - 1));
    }
}
