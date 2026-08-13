<?php

namespace App\Support\Reports;

use App\Http\Controllers\ReportsController;
use Carbon\Carbon;

/**
 * What a report `period` means: which keys are valid, what window one resolves
 * to, and how a window and its sub-buckets are spelled for a human.
 *
 * Lifted out of {@see ReportsController} when the report
 * grew a second and third caller — the emailed report and the scheduled one —
 * that must land on the identical window. The bucket FORMATS stay in
 * {@see SalesFigures}, which is the only thing that has to know how a given
 * database spells a date.
 */
class ReportPeriods
{
    /**
     * The set of valid period keys, and the sub-bucket's granularity noun used
     * as the breakdown table's first column header.
     */
    public const SUB_NOUNS = [
        'daily' => 'Hour',
        'weekly' => 'Day',
        'monthly' => 'Day',
        'yearly' => 'Month',
    ];

    /**
     * Constrain the granularity to a known period, defaulting to daily.
     */
    public static function normalize(mixed $value): string
    {
        $value = (string) $value;

        return array_key_exists($value, self::SUB_NOUNS) ? $value : 'daily';
    }

    /**
     * Snap an anchor date to the [start, end] bounds of the single selected
     * period. The anchor defaults to "now" when no (parseable) date is given.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function resolveWindow(string $period, ?Carbon $anchor): array
    {
        $a = ($anchor ?? Carbon::now())->copy();

        return match ($period) {
            'weekly' => [$a->copy()->startOfWeek(), $a->copy()->endOfWeek()],
            'monthly' => [$a->copy()->startOfMonth(), $a->copy()->endOfMonth()],
            'yearly' => [$a->copy()->startOfYear(), $a->copy()->endOfYear()],
            default => [$a->copy()->startOfDay(), $a->copy()->endOfDay()],
        };
    }

    /** A human-readable label for the selected period, e.g. "July 2026". */
    public static function label(string $period, Carbon $from): string
    {
        return match ($period) {
            'weekly' => $from->format('M j').' – '.$from->copy()->endOfWeek()->format('M j, Y'),
            'monthly' => $from->format('F Y'),
            'yearly' => $from->format('Y'),
            default => $from->format('l, M j, Y'),
        };
    }

    /**
     * Human label for one sub-bucket key (the breakdown table's first column in
     * the PDF), mirroring the frontend's formatBucket. Daily buckets are
     * 'Y-m-d H' (hour), weekly/monthly 'Y-m-d' (day), yearly 'Y-m' (month).
     */
    public static function bucketLabel(string $period, string $bucket): string
    {
        return match ($period) {
            'daily' => Carbon::createFromFormat('Y-m-d H', $bucket)->format('g:00 A'),
            'yearly' => Carbon::createFromFormat('Y-m', $bucket)->format('F Y'),
            default => Carbon::createFromFormat('Y-m-d', $bucket)->format('D, M j'),
        };
    }
}
