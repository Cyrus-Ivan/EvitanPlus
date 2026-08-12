<?php

namespace App\Support\Reports;

use App\Http\Controllers\ReportsController;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The branch-local report, recomputed from this device's SQLite mirror.
 *
 * Lifted out of {@see ReportsController} unchanged when
 * the scheduled send needed the same figures with no request behind it. The
 * CONSOLIDATED report stays in the controller: it needs an online read, the
 * branch roster and the fall-back-and-say-so behaviour, none of which a console
 * command has or should grow.
 */
class SalesReportBuilder
{
    /** The branch-local report: SQLite, this branch, works offline. */
    public const SCOPE_LOCAL = 'local';

    /** The consolidated report: Postgres, every branch. */
    public const SCOPE_ALL = 'all';

    /**
     * @return array<string, mixed>
     */
    public function local(string $period, Carbon $from, Carbon $to, bool $canViewCost, bool $topItems = false): array
    {
        $figures = new SalesFigures(DB::connection());
        $rows = $figures->rows('bucket', $period, $from, $to, $canViewCost);
        $chart = $figures->chart('item', $period, $from, $to);

        return [
            'scope' => self::SCOPE_LOCAL,
            'chartBy' => 'item',
            'rows' => $rows,
            'totals' => $figures->totals($rows, $canViewCost),
            'chartItems' => $chart['items'],
            'chart' => $chart['buckets'],
            'branches' => [],
            'branchRows' => null,
            // Null, not false: nothing consolidated was asked for, so there is
            // nothing to report as unavailable.
            'consolidatedOnline' => null,
            'topItems' => $topItems ? $figures->topItems($from, $to) : [],
        ];
    }
}
