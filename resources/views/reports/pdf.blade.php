{{--
    Sales report PDF (dompdf). Rendered by ReportsController::export from the same
    recomputed rows/totals as the on-screen report, so figures match exactly.

    Cost / Uncosted / Profit are Owner-only: every cost-bearing column is guarded
    by $canViewCost, mirroring the controller and the React page. Never emit a
    cost figure when $canViewCost is false.
--}}
@php
    // Money formatter for the "0.00" strings the controller emits, using the
    // business currency symbol (Business Settings; default ₱).
    $symbol = ($business['currency_symbol'] ?? '') !== '' ? $business['currency_symbol'] : '₱';
    $peso = fn ($value) => $symbol . number_format((float) $value, 2);
    // Returns/defects read as reductions; show them signed when non-zero.
    $minus = fn ($value) => (float) $value > 0 ? '−' . $peso($value) : $peso($value);
    $businessName = $business['business_name'] !== '' ? $business['business_name'] : 'Evitan Plus';

    // Compact money for the chart's y-axis ticks: ₱1.2k / ₱3.4M, sign preserved.
    $compact = function ($value) use ($symbol) {
        $sign = $value < 0 ? '−' : '';
        $abs = abs($value);
        if ($abs >= 1000000) return $sign . $symbol . number_format($abs / 1000000, 1) . 'M';
        if ($abs >= 1000) return $sign . $symbol . number_format($abs / 1000, 1) . 'k';
        return $sign . $symbol . round($abs);
    };
    // Short x-axis label from the raw bucket key, mirroring the on-screen chart's
    // shortLabel: daily '%Y-%m-%d %H' → hour ('9a'); yearly '%Y-%m' → month abbr;
    // weekly/monthly '%Y-%m-%d' → day-of-month.
    $shortLabel = function ($bucket) use ($period) {
        if ($period === 'daily') {
            $h = (int) substr($bucket, 11, 2);
            return ($h % 12 === 0 ? 12 : $h % 12) . ($h < 12 ? 'a' : 'p');
        }
        if ($period === 'yearly') {
            return \Carbon\Carbon::createFromFormat('Y-m', $bucket)->format('M');
        }
        return (string) (int) substr($bucket, 8, 2);
    };
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px 32px; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1a1a1a; font-size: 11px; }
        .header { border-bottom: 2px solid #1a1a1a; padding-bottom: 8px; margin-bottom: 14px; }
        .biz-name { font-size: 18px; font-weight: bold; }
        .biz-meta { color: #555; font-size: 10px; margin-top: 2px; }
        .report-title { font-size: 13px; font-weight: bold; margin-top: 10px; }
        .report-sub { color: #555; font-size: 10px; margin-top: 2px; }

        .cards { width: 100%; margin: 14px 0; border-collapse: collapse; }
        .cards td { border: 1px solid #ddd; padding: 8px 10px; width: 25%; vertical-align: top; }
        .card-label { text-transform: uppercase; letter-spacing: 0.04em; color: #777; font-size: 8px; }
        .card-value { font-size: 15px; font-weight: bold; margin-top: 3px; }

        table.breakdown { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.breakdown th, table.breakdown td { padding: 5px 8px; border-bottom: 1px solid #e5e5e5; }
        table.breakdown thead th { border-bottom: 1.5px solid #1a1a1a; text-align: right; font-size: 9px; text-transform: uppercase; letter-spacing: 0.03em; color: #555; }
        table.breakdown thead th.left, table.breakdown td.left { text-align: left; }
        table.breakdown td { text-align: right; font-variant-numeric: tabular-nums; }
        table.breakdown tfoot td { border-top: 1.5px solid #1a1a1a; border-bottom: none; font-weight: bold; }
        .muted { color: #777; }

        .section-title { font-size: 12px; font-weight: bold; margin: 16px 0 6px; }

        /* Net-sales bar chart — HTML divs (dompdf renders these reliably; inline SVG it does not). */
        .chart { margin: 6px 0 4px; }
        .plot { position: relative; margin-left: 48px; border-left: 1px solid #ccc; }
        .plot .zero-line { position: absolute; left: 0; width: 100%; height: 0; border-top: 1px solid #bbb; }
        .plot .bar { position: absolute; }
        .plot .ylabel { position: absolute; left: -48px; width: 44px; text-align: right; font-size: 8px; color: #777; }
        .xaxis { position: relative; margin-left: 48px; height: 12px; margin-top: 3px; }
        .xaxis .xlabel { position: absolute; text-align: center; font-size: 8px; color: #777; }

        .footer { margin-top: 18px; color: #888; font-size: 9px; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <div class="biz-name">{{ $businessName }}</div>
        @if ($business['business_address'] !== '' || $business['tin'] !== '')
            <div class="biz-meta">
                {{ $business['business_address'] }}@if ($business['business_address'] !== '' && $business['tin'] !== '') · @endif
                @if ($business['tin'] !== '') TIN: {{ $business['tin'] }} @endif
            </div>
        @endif
        <div class="report-title">Sales Report — {{ $label }}</div>
        <div class="report-sub">
            Net sales recomputed as gross − returns − defects. Generated {{ $generatedAt->format('M j, Y g:i A') }}.
        </div>
    </div>

    <table class="cards">
        <tr>
            <td>
                <div class="card-label">Gross sales</div>
                <div class="card-value">{{ $peso($totals['gross_sales']) }}</div>
            </td>
            <td>
                <div class="card-label">Returns</div>
                <div class="card-value">{{ $minus($totals['returns']) }}</div>
            </td>
            <td>
                <div class="card-label">Defects</div>
                <div class="card-value">{{ $minus($totals['defects']) }}</div>
            </td>
            <td>
                <div class="card-label">Net sales</div>
                <div class="card-value">{{ $peso($totals['net_sales']) }}</div>
            </td>
        </tr>
        @if ($canViewCost)
            <tr>
                <td>
                    <div class="card-label">Gross profit</div>
                    <div class="card-value">{{ $peso($totals['profit'] ?? '0') }}</div>
                </td>
                <td>
                    <div class="card-label">Uncosted sales</div>
                    <div class="card-value">{{ $peso($totals['uncosted_sales'] ?? '0') }}</div>
                </td>
                <td colspan="2" style="border: none;"></td>
            </tr>
        @endif
    </table>

    @if (count($rows) === 0)
        <p class="muted">No sales in this period.</p>
    @else
        @php
            // Diverging net-sales bar chart geometry, ported from sales-bar-chart.tsx:
            // positive bars grow up from the zero baseline, negative ones down.
            $nets = array_map(fn ($r) => (float) $r['net_sales'], $rows);
            $domainMax = max(0, max($nets));
            $domainMin = min(0, min($nets));
            $span = ($domainMax - $domainMin) ?: 1;
            $plotH = 150; // px
            $zeroTop = ($domainMax / $span) * $plotH; // px from the top down to the zero line
            $count = count($rows);
            $slotPct = 100 / $count;
            $barWPct = $slotPct * 0.62;
            $labelEvery = (int) max(1, ceil($count / 12));
        @endphp

        <div class="section-title">Net sales</div>
        <div class="chart">
            <div class="plot" style="height: {{ $plotH }}px;">
                {{-- Y-axis ticks: max at top, zero at the baseline, min at the bottom (only when negative). --}}
                <div class="ylabel" style="top: -5px;">{{ $compact($domainMax) }}</div>
                <div class="ylabel" style="top: {{ $zeroTop - 5 }}px;">{{ $symbol }}0</div>
                @if ($domainMin < 0)
                    <div class="ylabel" style="top: {{ $plotH - 5 }}px;">{{ $compact($domainMin) }}</div>
                @endif

                <div class="zero-line" style="top: {{ $zeroTop }}px;"></div>

                @foreach ($rows as $i => $row)
                    @php
                        $net = (float) $row['net_sales'];
                        $h = max(1, abs($net) / $span * $plotH);
                        $barLeft = $i * $slotPct + ($slotPct - $barWPct) / 2;
                        $barTop = $net >= 0 ? $zeroTop - $h : $zeroTop;
                        $color = $net >= 0 ? '#2a78d6' : '#e34948';
                    @endphp
                    <div class="bar" style="left: {{ $barLeft }}%; width: {{ $barWPct }}%; top: {{ $barTop }}px; height: {{ $h }}px; background-color: {{ $color }};"></div>
                @endforeach
            </div>
            <div class="xaxis">
                @foreach ($rows as $i => $row)
                    @if ($i % $labelEvery === 0)
                        <div class="xlabel" style="left: {{ $i * $slotPct }}%; width: {{ $slotPct }}%;">{{ $shortLabel($row['bucket']) }}</div>
                    @endif
                @endforeach
            </div>
        </div>

        <table class="breakdown">
            <thead>
                <tr>
                    <th class="left">{{ $periodNoun }}</th>
                    <th>Txns</th>
                    <th>Gross</th>
                    <th>Returns</th>
                    <th>Defects</th>
                    <th>Net</th>
                    @if ($canViewCost)
                        <th>Uncosted</th>
                        <th>Profit</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td class="left">{{ $row['label'] }}</td>
                        <td>{{ $row['transactions'] }}</td>
                        <td>{{ $peso($row['gross_sales']) }}</td>
                        <td class="muted">{{ $minus($row['returns']) }}</td>
                        <td class="muted">{{ $minus($row['defects']) }}</td>
                        <td>{{ $peso($row['net_sales']) }}</td>
                        @if ($canViewCost)
                            <td class="muted">{{ $peso($row['uncosted_sales'] ?? '0') }}</td>
                            <td>{{ $peso($row['profit'] ?? '0') }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td class="left">Total</td>
                    <td>{{ $totals['transactions'] }}</td>
                    <td>{{ $peso($totals['gross_sales']) }}</td>
                    <td>{{ $minus($totals['returns']) }}</td>
                    <td>{{ $minus($totals['defects']) }}</td>
                    <td>{{ $peso($totals['net_sales']) }}</td>
                    @if ($canViewCost)
                        <td>{{ $peso($totals['uncosted_sales'] ?? '0') }}</td>
                        <td>{{ $peso($totals['profit'] ?? '0') }}</td>
                    @endif
                </tr>
            </tfoot>
        </table>
    @endif

    @if (count($topItems) > 0)
        <div class="section-title">Top selling items — {{ $label }}</div>
        <table class="breakdown">
            <thead>
                <tr>
                    <th class="left">Item</th>
                    <th>Qty sold</th>
                    <th>Net sales</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($topItems as $item)
                    <tr>
                        <td class="left">{{ $item['name'] }}</td>
                        <td>{{ $item['quantity'] }}</td>
                        <td>{{ $peso($item['net_sales']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (($business['receipt_footer'] ?? '') !== '')
        <div class="footer">{{ $business['receipt_footer'] }}</div>
    @endif
</body>
</html>
