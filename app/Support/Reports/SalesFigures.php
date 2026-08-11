<?php

namespace App\Support\Reports;

use App\Support\Sync\OnlineRead;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * The sales recompute, over ONE connection (Phase 12 branch-local; M11
 * consolidated cross-branch).
 *
 * Everything is RECOMPUTED from the append-only source tables — never a stored
 * total (not `sale_returns.total_refund`, never a period sum cached anywhere).
 * Net sales = sales − returns − defects, per `CLAUDE.md` → "Reporting".
 *
 * ## Why this is a class and not eight methods on a controller
 *
 * It runs against two databases that are not the same database. Offline it is
 * handed the local SQLite connection and reports this branch; online it is
 * handed the Postgres connection {@see OnlineRead} opened for
 * the length of one request, and reports every branch, or one chosen branch.
 * The arithmetic must be the same arithmetic in both cases or the two screens
 * disagree, so there is exactly one copy of it and the connection is an argument.
 *
 * ## The three things that differ between the two databases
 *
 *  - **Bucketing** — SQLite has `strftime`, Postgres has `to_char`. See
 *    {@see self::bucket()}.
 *  - **Time** — SQLite columns hold naive business-local strings; Postgres holds
 *    `timestamptz`. See {@see self::bound()}, and `RowMapper`'s docblock for what
 *    getting this backwards looks like (a shift that sorts correctly, imports
 *    cleanly, and reports wrong).
 *  - **`branch_id`** — only the Postgres tables have it. `$branchId` is therefore
 *    always null on the local connection, and `by: 'branch'` is only ever asked
 *    for upstream.
 *
 * Cost / COGS / profit are Owner-only. The `$withCost` flag does not merely hide
 * them: the cost queries are never RUN and the keys are absent from the payload,
 * so no cost figure exists to leak — here as much as anywhere else (`M11` task:
 * "Item Cost Owner-only **even here**").
 */
class SalesFigures
{
    /**
     * The drill-down (sub-bucket) format per selected period — one level finer
     * than the selection itself, so a single period still yields a legible
     * multi-bar chart: a day → hours, a week/month → days, a year → months.
     *
     * The two maps must stay key-for-key identical in meaning: a bucket key is a
     * string the frontend parses, and the same period must produce the same
     * shape of key whichever database answered.
     */
    private const SQLITE_FORMATS = [
        'daily' => '%Y-%m-%d %H', // hourly
        'weekly' => '%Y-%m-%d',   // daily
        'monthly' => '%Y-%m-%d',  // daily
        'yearly' => '%Y-%m',      // monthly
    ];

    private const POSTGRES_FORMATS = [
        'daily' => 'YYYY-MM-DD HH24',
        'weekly' => 'YYYY-MM-DD',
        'monthly' => 'YYYY-MM-DD',
        'yearly' => 'YYYY-MM',
    ];

    /** How many series get their own stacked-chart segment before the rest fold into "Other". */
    private const CHART_TOP_SERIES = 8;

    /** Legend/segment key for the aggregated tail. */
    public const OTHER_KEY = '__other__';

    /**
     * @param  Connection  $db  Local SQLite, or the business Postgres connection.
     * @param  string|null  $branchId  Null = every branch (and the only valid value
     *                                 on SQLite, whose tables carry no branch_id).
     */
    public function __construct(
        private readonly Connection $db,
        private readonly ?string $branchId = null,
    ) {}

    /**
     * One ordered row per group in the range, recomputing sales, returns and
     * defects from source tables and deriving net (and, for the Owner, COGS +
     * gross profit).
     *
     * `$by` picks what a row IS: a time bucket (the breakdown table, both
     * screens) or a branch (the consolidated screen's per-branch table). Both
     * read the same seven aggregates over the same window — only the GROUP BY
     * differs — so the per-branch rows sum to the per-bucket rows exactly.
     *
     * All money is reconciled through integer centavos so the sums are
     * cent-exact despite SQLite's float SUM().
     *
     * @param  'bucket'|'branch'  $by
     * @return list<array<string, mixed>>
     */
    public function rows(string $by, string $period, Carbon $dateFrom, Carbon $dateTo, bool $withCost): array
    {
        $from = $dateFrom->copy();
        $to = $dateTo->copy();

        // Sales & transaction count, grouped by the sale's own date (or branch).
        $sales = $this->db->table('transactions as t')
            ->tap($this->window('t', $from, $to))
            ->selectRaw($this->dimension($by, 't', $period).' as grp, count(*) as txns, sum(t.total) as gross', $this->dimensionBindings($by))
            ->groupBy('grp')
            ->get()
            ->keyBy('grp');

        // Returns and defects, grouped by the EVENT date (sale_returns.created_at)
        // — an after-sales event reduces the period it happened in.
        $returns = $this->refunds('return', $by, $period, $from, $to);
        $defects = $this->refunds('defect', $by, $period, $from, $to);

        // COGS + profit are Owner-only. Sold cost is attributed to the sale's date;
        // the cost of RETURNED goods is credited back (they re-enter stock) on the
        // return's event date. Defected goods are written off — their cost stays in
        // COGS. Items with NO recorded cost can't yield a margin, so their net sales
        // are split out into `uncosted_sales` and excluded from profit entirely
        // (rather than silently treated as zero-cost, which would inflate profit).
        $soldCost = $withCost ? $this->soldCost($by, $period, $from, $to) : collect();
        $returnedCost = $withCost ? $this->refundedCost('return', $by, $period, $from, $to) : collect();
        $uncostedRevenue = $withCost ? $this->uncostedRevenue($by, $period, $from, $to) : collect();
        $uncostedRefund = $withCost ? $this->uncostedRefund($by, $period, $from, $to) : collect();

        $groups = collect([$sales, $returns, $defects, $soldCost, $returnedCost, $uncostedRevenue, $uncostedRefund])
            ->flatMap(fn ($map) => $map->keys())
            ->unique()
            ->sort()
            ->values();

        return $groups->map(function (string $group) use ($by, $sales, $returns, $defects, $soldCost, $returnedCost, $uncostedRevenue, $uncostedRefund, $withCost) {
            $gross = $this->centavos($sales[$group]->gross ?? 0);
            $returnRefund = $this->centavos($returns[$group] ?? 0);
            $defectRefund = $this->centavos($defects[$group] ?? 0);
            $net = $gross - $returnRefund - $defectRefund;

            // The key's name follows what the row IS, so the frontend never has to
            // guess: a bucket row is keyed `bucket`, a branch row `branch_id`.
            $row = [
                $by === 'branch' ? 'branch_id' : 'bucket' => $group,
                'transactions' => (int) ($sales[$group]->txns ?? 0),
                'gross_sales' => $this->pesos($gross),
                'returns' => $this->pesos($returnRefund),
                'defects' => $this->pesos($defectRefund),
                'net_sales' => $this->pesos($net),
            ];

            if ($withCost) {
                // Net sales attributable to un-costed items (their sale revenue,
                // discount-apportioned, less any of their own refunds). Whatever is
                // left is the costed portion that profit is computed over.
                $uncostedNet = $this->centavos($uncostedRevenue[$group] ?? 0) - $this->centavos($uncostedRefund[$group] ?? 0);
                $costedNet = $net - $uncostedNet;

                // Net COGS excludes returned goods (credited back); defected goods'
                // cost is retained (written off, not restored to stock). Not emitted:
                // no screen and no PDF has ever rendered it, so it stays a local term
                // of the profit arithmetic below.
                $cogs = $this->centavos($soldCost[$group] ?? 0) - $this->centavos($returnedCost[$group] ?? 0);

                // Profit covers costed items only; un-costed sales are reported apart.
                $row['profit'] = $this->pesos($costedNet - $cogs);
                $row['uncosted_sales'] = $this->pesos($uncostedNet);
            }

            return $row;
        })->all();
    }

    /**
     * Build the stacked-bar chart payload: per time bucket, each series' NET
     * sales (its discount-apportioned revenue minus its own returns + defects)
     * as a separate segment.
     *
     * `$by` picks the series dimension. `'item'` is the branch-local answer and
     * the consolidated default — what sold. `'branch'` is the question only the
     * consolidated screen can ask — which branch carried the period. Both are
     * always bucketed by TIME on the x-axis; only the stacking differs.
     *
     * Segments reconcile to the table's net per bucket because per-series revenue
     * is apportioned exactly like the un-costed split, so the sum over series of
     * a bucket equals its net_sales.
     *
     * Only the top {@see self::CHART_TOP_SERIES} by gross revenue keep their own
     * segment; the rest fold into a single "Other" segment so the chart stays
     * legible for large catalogs. Net can be negative (a series whose returns in
     * a bucket exceed its sales), which the frontend renders downward.
     *
     * Item cost is never exposed here (net = revenue − refunds), so this is safe
     * for every Admin+ viewer regardless of the Owner-only cost gate.
     *
     * @param  'item'|'branch'  $by
     * @param  array<string, string>  $names  Series key → display name, for `by: 'branch'`
     *                                        (the roster is read once by the caller rather
     *                                        than joined into every aggregate).
     * @return array{items: list<array{key: string, name: string}>, buckets: list<array{bucket: string, values: object}>}
     */
    public function chart(string $by, string $period, Carbon $dateFrom, Carbon $dateTo, array $names = []): array
    {
        $from = $dateFrom->copy();
        $to = $dateTo->copy();

        $revenueRows = $by === 'branch'
            ? $this->branchRevenueByBucket($period, $from, $to)
            : $this->itemRevenueByBucket($period, $from, $to);
        $refundRows = $by === 'branch'
            ? $this->branchRefundByBucket($period, $from, $to)
            : $this->itemRefundByBucket($period, $from, $to);

        $totalRevenue = [];   // series key => gross revenue centavos (ranking key)
        $net = [];            // series key => [bucket => net centavos]
        $bucketSet = [];      // bucket => true

        foreach ($revenueRows as $row) {
            $cents = $this->centavos($row->revenue);
            $names[$row->series] ??= (string) ($row->series_name ?? $row->series);
            $totalRevenue[$row->series] = ($totalRevenue[$row->series] ?? 0) + $cents;
            $net[$row->series][$row->bucket] = ($net[$row->series][$row->bucket] ?? 0) + $cents;
            $bucketSet[$row->bucket] = true;
        }

        foreach ($refundRows as $row) {
            $cents = $this->centavos($row->refund);
            $names[$row->series] ??= (string) ($row->series_name ?? $row->series);
            $totalRevenue[$row->series] ??= 0;
            $net[$row->series][$row->bucket] = ($net[$row->series][$row->bucket] ?? 0) - $cents;
            $bucketSet[$row->bucket] = true;
        }

        if ($net === []) {
            return ['items' => [], 'buckets' => []];
        }

        // Rank by gross revenue desc, name asc as a stable tie-break.
        $ranked = array_keys($totalRevenue);
        usort($ranked, fn ($a, $b) => ($totalRevenue[$b] <=> $totalRevenue[$a]) ?: strcmp($names[$a], $names[$b]));

        $kept = array_slice($ranked, 0, self::CHART_TOP_SERIES);
        $keptSet = array_flip($kept);
        $hasOther = count($ranked) > count($kept);

        $items = [];
        foreach ($kept as $key) {
            $items[] = ['key' => (string) $key, 'name' => $names[$key]];
        }
        if ($hasOther) {
            $items[] = ['key' => self::OTHER_KEY, 'name' => 'Other'];
        }

        $buckets = array_keys($bucketSet);
        sort($buckets); // bucket formats are zero-padded, so lexicographic == chronological.

        $chartBuckets = [];
        foreach ($buckets as $bucket) {
            $values = [];
            $otherCents = 0;
            foreach ($net as $key => $byBucket) {
                $cents = $byBucket[$bucket] ?? 0;
                if ($cents === 0) {
                    continue; // omit empties; the frontend treats a missing key as 0.
                }
                if (isset($keptSet[$key])) {
                    $values[$key] = $this->pesos($cents);
                } else {
                    $otherCents += $cents;
                }
            }
            if ($otherCents !== 0) {
                $values[self::OTHER_KEY] = $this->pesos($otherCents);
            }
            // (string) the bucket: PHP coerces a purely-numeric bucket key
            // (e.g. a bare-year format like "2026") to an int, which would
            // serialise as a JSON number and break the frontend's string
            // handling. No current format is hyphen-free, but keep the cast
            // as a guard. Cast values so an empty bucket serialises as {} not [].
            $chartBuckets[] = ['bucket' => (string) $bucket, 'values' => (object) $values];
        }

        return ['items' => $items, 'buckets' => $chartBuckets];
    }

    /**
     * The best-selling items across the WHOLE selected range (not bucketed), for
     * the PDF's "Top selling items" table. Each item's net sales is its
     * discount-apportioned revenue minus its own returns + defects — the same
     * recompute {@see self::chart()} uses, only grouped by item alone. Ranked by
     * net sales desc with a name tie-break, capped at $limit.
     *
     * Only items that actually SOLD in the window are listed; an item touched only
     * by a refund (its sale fell in an earlier period) is omitted. Item cost is
     * never read here (net = revenue − refunds), so this is safe for every Admin+
     * viewer regardless of the Owner-only cost gate.
     *
     * @return list<array{name: string, quantity: int, net_sales: string}>
     */
    public function topItems(Carbon $dateFrom, Carbon $dateTo, int $limit = 10): array
    {
        $from = $dateFrom->copy();
        $to = $dateTo->copy();

        // Quantity sold + discount-apportioned revenue per item, keyed by item.
        $sales = $this->db->table('transaction_items as ti')
            ->join('transactions as t', 't.id', '=', 'ti.transaction_id')
            ->join('variants as v', 'v.id', '=', 'ti.variant_id')
            ->join('items as it', 'it.id', '=', 'v.item_id')
            ->joinSub($this->subtotals($from, $to), 'sub', 'sub.transaction_id', '=', 'ti.transaction_id')
            ->where('sub.subtotal', '>', 0)
            ->tap($this->window('t', $from, $to))
            ->selectRaw('it.id as item_id, it.name as item_name, sum(ti.quantity) as qty, sum(ti.total * t.total / sub.subtotal) as revenue')
            ->groupBy('it.id', 'it.name')
            ->get();

        // Refunds (returns + defects both reduce net) per item, keyed by item.
        $refunds = $this->db->table('sale_return_items as sri')
            ->join('sale_returns as sr', 'sr.id', '=', 'sri.sale_return_id')
            ->join('variants as v', 'v.id', '=', 'sri.variant_id')
            ->tap($this->window('sr', $from, $to))
            ->selectRaw('v.item_id as item_id, sum(sri.refund_amount) as refund')
            ->groupBy('v.item_id')
            ->pluck('refund', 'item_id');

        $names = [];
        $qty = [];
        $net = []; // item_id => net sales centavos
        foreach ($sales as $row) {
            $names[$row->item_id] = $row->item_name;
            $qty[$row->item_id] = (int) $row->qty;
            $net[$row->item_id] = $this->centavos($row->revenue);
        }
        foreach ($refunds as $itemId => $refund) {
            if (isset($net[$itemId])) {
                $net[$itemId] -= $this->centavos($refund);
            }
        }

        // Rank by net sales desc, name asc as a stable tie-break (mirrors chart()).
        $ids = array_keys($net);
        usort($ids, fn ($a, $b) => ($net[$b] <=> $net[$a]) ?: strcmp($names[$a], $names[$b]));

        return array_map(fn ($id) => [
            'name' => $names[$id],
            'quantity' => $qty[$id],
            'net_sales' => $this->pesos($net[$id]),
        ], array_slice($ids, 0, $limit));
    }

    /**
     * Sum rows into period totals for the summary cards. Money is re-summed
     * through centavos for a cent-exact grand total.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function totals(array $rows, bool $withCost): array
    {
        $totals = [
            'transactions' => 0,
            'gross_sales' => 0,
            'returns' => 0,
            'defects' => 0,
            'net_sales' => 0,
            'profit' => 0,
            'uncosted_sales' => 0,
        ];

        foreach ($rows as $row) {
            $totals['transactions'] += $row['transactions'];
            $totals['gross_sales'] += $this->centavos($row['gross_sales']);
            $totals['returns'] += $this->centavos($row['returns']);
            $totals['defects'] += $this->centavos($row['defects']);
            $totals['net_sales'] += $this->centavos($row['net_sales']);
            if ($withCost) {
                $totals['profit'] += $this->centavos($row['profit']);
                $totals['uncosted_sales'] += $this->centavos($row['uncosted_sales']);
            }
        }

        $result = [
            'transactions' => $totals['transactions'],
            'gross_sales' => $this->pesos($totals['gross_sales']),
            'returns' => $this->pesos($totals['returns']),
            'defects' => $this->pesos($totals['defects']),
            'net_sales' => $this->pesos($totals['net_sales']),
        ];

        if ($withCost) {
            $result['profit'] = $this->pesos($totals['profit']);
            $result['uncosted_sales'] = $this->pesos($totals['uncosted_sales']);
        }

        return $result;
    }

    // ---------------------------------------------------------------- aggregates

    /**
     * SUM(refund_amount) per group for one after-sales type, keyed by the event
     * date's group.
     *
     * @return Collection<string, float|string>
     */
    private function refunds(string $type, string $by, string $period, Carbon $from, Carbon $to): Collection
    {
        return $this->db->table('sale_return_items as sri')
            ->join('sale_returns as sr', 'sr.id', '=', 'sri.sale_return_id')
            ->where('sr.type', $type)
            ->tap($this->window('sr', $from, $to))
            ->selectRaw($this->dimension($by, 'sr', $period).' as grp, sum(sri.refund_amount) as refund', $this->dimensionBindings($by))
            ->groupBy('grp')
            ->pluck('refund', 'grp');
    }

    /**
     * SUM(cost * quantity) of sold lines per group, keyed by the sale's date.
     *
     * @return Collection<string, float|string>
     */
    private function soldCost(string $by, string $period, Carbon $from, Carbon $to): Collection
    {
        return $this->db->table('transaction_items as ti')
            ->join('transactions as t', 't.id', '=', 'ti.transaction_id')
            ->tap($this->window('t', $from, $to))
            ->selectRaw($this->dimension($by, 't', $period).' as grp, sum(ti.cost * ti.quantity) as cost', $this->dimensionBindings($by))
            ->groupBy('grp')
            ->pluck('cost', 'grp');
    }

    /**
     * SUM(cost * quantity) of after-sales lines of one type per group, keyed by
     * the event date.
     *
     * @return Collection<string, float|string>
     */
    private function refundedCost(string $type, string $by, string $period, Carbon $from, Carbon $to): Collection
    {
        return $this->db->table('sale_return_items as sri')
            ->join('sale_returns as sr', 'sr.id', '=', 'sri.sale_return_id')
            ->where('sr.type', $type)
            ->tap($this->window('sr', $from, $to))
            ->selectRaw($this->dimension($by, 'sr', $period).' as grp, sum(sri.cost * sri.quantity) as cost', $this->dimensionBindings($by))
            ->groupBy('grp')
            ->pluck('cost', 'grp');
    }

    /**
     * Sale revenue of un-costed lines (cost IS NULL) per group, keyed by the
     * sale's date. Each line's revenue is its share of the transaction total
     * (line total ÷ transaction subtotal × transaction total), so any
     * transaction-level discount is apportioned and the costed + un-costed split
     * reconciles exactly to net sales.
     *
     * @return Collection<string, float|string>
     */
    private function uncostedRevenue(string $by, string $period, Carbon $from, Carbon $to): Collection
    {
        return $this->db->table('transaction_items as ti')
            ->join('transactions as t', 't.id', '=', 'ti.transaction_id')
            ->joinSub($this->subtotals($from, $to), 'sub', 'sub.transaction_id', '=', 'ti.transaction_id')
            ->whereNull('ti.cost')
            ->where('sub.subtotal', '>', 0)
            ->tap($this->window('t', $from, $to))
            ->selectRaw($this->dimension($by, 't', $period).' as grp, sum(ti.total * t.total / sub.subtotal) as revenue', $this->dimensionBindings($by))
            ->groupBy('grp')
            ->pluck('revenue', 'grp');
    }

    /**
     * SUM(refund_amount) of un-costed after-sales lines (cost IS NULL, both
     * returns and defects) per group, keyed by the event date. Mirrors the
     * un-costed revenue split on the refund side.
     *
     * @return Collection<string, float|string>
     */
    private function uncostedRefund(string $by, string $period, Carbon $from, Carbon $to): Collection
    {
        return $this->db->table('sale_return_items as sri')
            ->join('sale_returns as sr', 'sr.id', '=', 'sri.sale_return_id')
            ->whereNull('sri.cost')
            ->tap($this->window('sr', $from, $to))
            ->selectRaw($this->dimension($by, 'sr', $period).' as grp, sum(sri.refund_amount) as refund', $this->dimensionBindings($by))
            ->groupBy('grp')
            ->pluck('refund', 'grp');
    }

    /**
     * Per item + bucket, discount-apportioned sale revenue, keyed by the sale's
     * date, with the item's current name.
     *
     * @return Collection<int, \stdClass>
     */
    private function itemRevenueByBucket(string $period, Carbon $from, Carbon $to): Collection
    {
        return $this->db->table('transaction_items as ti')
            ->join('transactions as t', 't.id', '=', 'ti.transaction_id')
            ->join('variants as v', 'v.id', '=', 'ti.variant_id')
            ->join('items as it', 'it.id', '=', 'v.item_id')
            ->joinSub($this->subtotals($from, $to), 'sub', 'sub.transaction_id', '=', 'ti.transaction_id')
            ->where('sub.subtotal', '>', 0)
            ->tap($this->window('t', $from, $to))
            ->selectRaw(
                'it.id as series, it.name as series_name, '.$this->bucket('t.created_at', $period).' as bucket, sum(ti.total * t.total / sub.subtotal) as revenue',
                $this->bucketBindings()
            )
            ->groupBy('it.id', 'it.name', 'bucket')
            ->get();
    }

    /**
     * Per item + bucket, refund reversed by after-sales events of BOTH types
     * (returns and defects each reduce net), keyed by the event date.
     *
     * @return Collection<int, \stdClass>
     */
    private function itemRefundByBucket(string $period, Carbon $from, Carbon $to): Collection
    {
        return $this->db->table('sale_return_items as sri')
            ->join('sale_returns as sr', 'sr.id', '=', 'sri.sale_return_id')
            ->join('variants as v', 'v.id', '=', 'sri.variant_id')
            ->join('items as it', 'it.id', '=', 'v.item_id')
            ->tap($this->window('sr', $from, $to))
            ->selectRaw(
                'it.id as series, it.name as series_name, '.$this->bucket('sr.created_at', $period).' as bucket, sum(sri.refund_amount) as refund',
                $this->bucketBindings()
            )
            ->groupBy('it.id', 'it.name', 'bucket')
            ->get();
    }

    /**
     * Per branch + bucket, discount-apportioned sale revenue (M11). The branch's
     * NAME is not joined: the caller already read the roster in the same online
     * read, and keeping the remote query one flat select is the habit
     * `InventoryQueueController` established.
     *
     * @return Collection<int, \stdClass>
     */
    private function branchRevenueByBucket(string $period, Carbon $from, Carbon $to): Collection
    {
        return $this->db->table('transaction_items as ti')
            ->join('transactions as t', 't.id', '=', 'ti.transaction_id')
            ->joinSub($this->subtotals($from, $to), 'sub', 'sub.transaction_id', '=', 'ti.transaction_id')
            ->where('sub.subtotal', '>', 0)
            ->tap($this->window('t', $from, $to))
            ->selectRaw(
                't.branch_id as series, '.$this->bucket('t.created_at', $period).' as bucket, sum(ti.total * t.total / sub.subtotal) as revenue',
                $this->bucketBindings()
            )
            ->groupBy('t.branch_id', 'bucket')
            ->get();
    }

    /**
     * Per branch + bucket, refunds of BOTH after-sales types. Needs no
     * variants/items join at all — the branch is on `sale_returns` itself.
     *
     * @return Collection<int, \stdClass>
     */
    private function branchRefundByBucket(string $period, Carbon $from, Carbon $to): Collection
    {
        return $this->db->table('sale_return_items as sri')
            ->join('sale_returns as sr', 'sr.id', '=', 'sri.sale_return_id')
            ->tap($this->window('sr', $from, $to))
            ->selectRaw(
                'sr.branch_id as series, '.$this->bucket('sr.created_at', $period).' as bucket, sum(sri.refund_amount) as refund',
                $this->bucketBindings()
            )
            ->groupBy('sr.branch_id', 'bucket')
            ->get();
    }

    /**
     * Each transaction's line subtotal, for the discount apportionment
     * (line total ÷ subtotal × transaction total).
     *
     * Scoped to the SAME window as its callers: the join key is
     * `transaction_id` and every outer query already restricts to transactions
     * in the window, so the result is identical either way — but unscoped this
     * aggregates every `transaction_items` row the database holds, which is a
     * whole-business scan once the connection is Postgres.
     */
    private function subtotals(Carbon $from, Carbon $to): Builder
    {
        return $this->db->table('transaction_items as sti')
            ->join('transactions as st', 'st.id', '=', 'sti.transaction_id')
            ->tap($this->window('st', $from, $to))
            ->select('sti.transaction_id')
            ->selectRaw('sum(sti.total) as subtotal')
            ->groupBy('sti.transaction_id');
    }

    // ------------------------------------------------------------------ dialect

    /**
     * The GROUP BY expression that turns a row into a report row: a time bucket,
     * or the branch that rang it.
     *
     * @param  'bucket'|'branch'  $by
     * @param  string  $alias  The branch-carrying table (`t` or `sr`) — the
     *                         `*_items` children have no branch_id and inherit
     *                         it through the join that is already there.
     */
    private function dimension(string $by, string $alias, string $period): string
    {
        return $by === 'branch'
            ? $alias.'.branch_id'
            : $this->bucket($alias.'.created_at', $period);
    }

    /** @return list<string> */
    private function dimensionBindings(string $by): array
    {
        return $by === 'branch' ? [] : $this->bucketBindings();
    }

    /**
     * A timestamp column → the bucket key string the frontend parses.
     *
     * The FORMAT is inlined rather than bound: it comes from a constant map
     * keyed by a period the caller has already constrained to four values, and
     * inlining keeps the select/where binding order trivial. The TIMEZONE is
     * bound — it comes from an Owner-editable settings row.
     *
     * SQLite needs no timezone at all: its datetime columns already hold naive
     * business-local strings (see `RowMapper`). Postgres holds `timestamptz`, so
     * the instant has to be brought back into business-local time before it can
     * be cut into days — otherwise a 23:30 Manila sale reports on the following
     * UTC day.
     */
    private function bucket(string $column, string $period): string
    {
        return $this->db->getDriverName() === 'pgsql'
            ? "to_char({$column} at time zone ?, '".self::POSTGRES_FORMATS[$period]."')"
            : "strftime('".self::SQLITE_FORMATS[$period]."', {$column})";
    }

    /** @return list<string> */
    private function bucketBindings(): array
    {
        return $this->db->getDriverName() === 'pgsql' ? [$this->timezone()] : [];
    }

    /**
     * The [from, to] window filter, plus the branch scope when there is one.
     *
     * Deliberately filters the RAW timestamp column rather than a converted one:
     * `created_at at time zone ?` is not sargable and would throw away the
     * `(branch_id, created_at)` indexes that exist on `transactions` and
     * `sale_returns` precisely for this query.
     *
     * @return Closure(Builder): void
     */
    private function window(string $alias, Carbon $from, Carbon $to): Closure
    {
        return function (Builder $query) use ($alias, $from, $to): void {
            $query->whereBetween($alias.'.created_at', [$this->bound($from), $this->bound($to)]);

            if ($this->branchId !== null) {
                $query->where($alias.'.branch_id', $this->branchId);
            }
        };
    }

    /**
     * A window bound, in the form the connection compares correctly.
     *
     * SQLite: hand Carbon straight over — Laravel formats it 'Y-m-d H:i:s', which
     * is exactly what the column holds.
     *
     * Postgres: the column is `timestamptz`, and Laravel's date format drops the
     * offset, which would leave the server reading a Manila wall-clock time as
     * UTC — eight hours of sales in the wrong period. So the offset is written
     * out, and so are the MICROSECONDS: `endOfDay()` is 23:59:59.999999 and
     * `timestamptz` keeps them, so truncating (as `toIso8601String()` does) would
     * silently drop the last second of every window.
     */
    private function bound(Carbon $moment): Carbon|string
    {
        return $this->db->getDriverName() === 'pgsql'
            ? $moment->copy()->setTimezone($this->timezone())->format('Y-m-d H:i:s.uP')
            : $moment;
    }

    /** The business timezone — one for the whole business (cross-timezone branches are deferred). */
    private function timezone(): string
    {
        return (string) config('app.timezone');
    }

    // -------------------------------------------------------------------- money

    /** Money (peso decimal string/number) → integer centavos, drift-free. */
    private function centavos(string|float|int $value): int
    {
        return (int) round((float) $value * 100);
    }

    /** Integer centavos → a "0.00" peso string. */
    private function pesos(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }
}
