<?php

namespace App\Support\Reports;

use App\Support\Sync\OnlineRead;
use Carbon\Carbon;
use Illuminate\Database\Connection;

/**
 * The individual sales one employee rang on one day — the receipts behind a
 * single cell of the attendance calendar.
 *
 * The calendar carries a day's FIGURES ({@see EmployeePerformanceBuilder}): how
 * many transactions and what they netted. That answers "was this a busy day?" and
 * cannot answer "busy how" — one large sale and fourteen small ones are the same
 * two numbers. This is the list underneath: every sale in the order it was rung,
 * with the time, the branch and what it came to.
 *
 * The connection is an ARGUMENT, exactly as it is for {@see SalesFigures} and
 * {@see AttendanceFigures}, and in production it is always the Postgres
 * connection one {@see OnlineRead} opened. Employee performance is online-only
 * and business-wide for the same reason throughout: a person is not a branch, and
 * a day of theirs read from one branch's mirror is wrong rather than narrow.
 *
 * ## What foots to what
 *
 * `total` is the sale as it was rung, so the day's totals sum to that day's
 * `gross_sales` exactly — same window, same user filter, same column. They do NOT
 * sum to `net_sales`: an after-sales event reduces the day it HAPPENED on, which
 * is rarely the day of the sale it refunds. So each row also carries what has
 * since been refunded against it, recomputed from `sale_return_items` (never the
 * stored `sale_returns.total_refund`), and the dialog labels it as a fact about
 * the sale rather than a subtraction from the day.
 */
class EmployeeDaySales
{
    /**
     * How many receipts one cell will list. A day beyond this is a rare shift at
     * a busy till, and the count on the calendar is still the true one — the
     * dialog says it is showing the first hundred.
     */
    public const LIMIT = 100;

    /**
     * @param  Connection  $db  The business Postgres connection in production;
     *                          local SQLite in the recompute tests.
     */
    public function __construct(private readonly Connection $db) {}

    /**
     * Every sale this employee rang on this calendar day, oldest first.
     *
     * The branch join is Postgres-only, exactly as it is in
     * {@see AttendanceFigures::events()}: upstream every row carries the branch
     * it was rung at, and the local `transactions` table has no `branch_id`
     * column at all. Columns are therefore qualified — `branches` carries a
     * `created_at` and an `id` of its own.
     *
     * @return list<array<string, mixed>>
     */
    public function forDay(string $userId, Carbon $day): array
    {
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();

        $columns = [
            'transactions.id',
            'transactions.transaction_number',
            'transactions.payment_type',
            'transactions.total',
            'transactions.created_at',
        ];

        $query = $this->db->table('transactions')
            ->where('transactions.user_id', $userId);

        if ($this->db->getDriverName() === 'pgsql') {
            $query->leftJoin('branches', 'branches.id', '=', 'transactions.branch_id');
            $columns[] = 'branches.code as branch_code';
        }

        $rows = $query
            // On the raw column, so the (branch_id, created_at) index applies.
            ->whereBetween('transactions.created_at', [$this->bound($from), $this->bound($to)])
            ->orderBy('transactions.created_at')
            // A second key, because `timestamptz` is precision 0 upstream and two
            // sales at a busy till can land in the same second.
            ->orderBy('transactions.id')
            ->limit(self::LIMIT)
            ->get($columns);

        if ($rows->isEmpty()) {
            return [];
        }

        $refunds = $this->refunds($rows->pluck('id')->map(fn ($id): string => (string) $id)->all());

        return $rows->map(fn (object $row): array => [
            'id' => (string) $row->id,
            'transaction_number' => (string) $row->transaction_number,
            'payment_type' => (string) $row->payment_type,
            'total' => $this->money($row->total),
            'at' => $this->localise($row->created_at)->toIso8601String(),
            // Null on SQLite, which has no branch column, and null upstream for a
            // row written before the branch existed. The sale is still the sale.
            'branch' => isset($row->branch_code) ? (string) $row->branch_code : null,
            'returned' => $this->money($refunds['return'][(string) $row->id] ?? 0),
            'defected' => $this->money($refunds['defect'][(string) $row->id] ?? 0),
        ])->all();
    }

    /**
     * What has since been refunded against each of these sales, split by kind.
     *
     * One grouped read over the page of keys rather than a join per row, and
     * inside the caller's own online read, so it costs no extra connection — the
     * habit `SalesController::remotePage()` established for its line counts.
     *
     * Recomputed from the line amounts. `sale_returns.total_refund` is a stored
     * total and reporting never trusts one.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, float|string>>
     */
    private function refunds(array $ids): array
    {
        $rows = $this->db->table('sale_return_items as sri')
            ->join('sale_returns as sr', 'sr.id', '=', 'sri.sale_return_id')
            ->whereIn('sr.transaction_id', $ids)
            ->groupBy('sr.transaction_id', 'sr.type')
            ->selectRaw('sr.transaction_id, sr.type, sum(sri.refund_amount) as refund')
            ->get();

        $byType = [];
        foreach ($rows as $row) {
            $byType[(string) $row->type][(string) $row->transaction_id] = $row->refund;
        }

        return $byType;
    }

    /**
     * A window bound, in the form the connection compares correctly — the same
     * rule as {@see SalesFigures::bound()} and {@see AttendanceFigures::bound()}.
     *
     * SQLite holds naive business-local strings; Postgres holds `timestamptz`
     * against a UTC session, so the offset has to be written out or a Manila day
     * lands eight hours off — a list that renders perfectly and covers the wrong
     * hours.
     */
    private function bound(Carbon $moment): Carbon|string
    {
        return $this->db->getDriverName() === 'pgsql'
            ? $moment->copy()->setTimezone($this->timezone())->format('Y-m-d H:i:s.uP')
            : $moment;
    }

    /**
     * A stored timestamp as a business-local moment. Upstream values arrive with
     * an offset and are converted in PHP; local ones are already business-local
     * and only need parsing.
     */
    private function localise(mixed $value): Carbon
    {
        return Carbon::parse((string) $value)->setTimezone($this->timezone());
    }

    /**
     * Money as a "0.00" string whichever database answered: Postgres `numeric`
     * arrives as an exact string, SQLite as a float.
     */
    private function money(string|float|int $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /** The business timezone — one for the whole business. */
    private function timezone(): string
    {
        return (string) config('app.timezone');
    }
}
