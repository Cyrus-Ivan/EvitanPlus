{{--
    Employee performance PDF (dompdf). Rendered by
    App\Support\Reports\EmployeePerformancePdf from the same recomputed days and
    summary as the on-screen page, so the figures match exactly.

    One employee per document, always. There is no cost, COGS or profit anywhere
    on it — the builder never runs those queries — which is why this template has
    no $canViewCost guard to get wrong.
--}}
@php
    // Money formatter for the "0.00" strings the builder emits, using the
    // business currency symbol (Business Settings; default ₱).
    $symbol = ($business['currency_symbol'] ?? '') !== '' ? $business['currency_symbol'] : '₱';
    $peso = fn ($value) => $symbol . number_format((float) $value, 2);
    $businessName = $business['business_name'] !== '' ? $business['business_name'] : 'Evitan Plus';

    $time = fn ($iso) => \Carbon\Carbon::parse($iso)->format('g:ia');
    // Hours are null on an open shift — never 0, which would read as "worked no
    // time" rather than "we do not know".
    $hours = fn ($value) => $value === null ? '—' : number_format((float) $value, 2) . 'h';
    $weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px 32px; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1a1a1a; font-size: 11px; }
        .header { border-bottom: 2px solid #1a1a1a; padding-bottom: 8px; }
        .biz-name { font-size: 18px; font-weight: bold; }
        .report-title { font-size: 13px; font-weight: bold; margin-top: 10px; }
        .report-note { color: #555; font-size: 10px; margin: 6px 0 14px; }

        .cards { width: 100%; margin: 14px 0; border-collapse: collapse; }
        .cards td { border: 1px solid #ddd; padding: 8px 10px; width: 25%; vertical-align: top; }
        .card-label { text-transform: uppercase; letter-spacing: 0.04em; color: #777; font-size: 8px; }
        .card-value { font-size: 15px; font-weight: bold; margin-top: 3px; }

        .section-title { font-size: 12px; font-weight: bold; margin: 16px 0 6px; }
        .muted { color: #777; }

        table.calendar { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.calendar th { padding: 4px; font-size: 8px; text-transform: uppercase; letter-spacing: 0.03em;
                            color: #555; border-bottom: 1.5px solid #1a1a1a; }
        table.calendar td { border: 1px solid #e5e5e5; padding: 4px; height: 62px; vertical-align: top; font-size: 8px; }
        table.calendar td.empty { border: none; }
        .daynum { font-weight: bold; font-size: 9px; }
        .shift { margin-top: 2px; }
        .shift .open { color: #777; font-style: italic; }
        .plus { color: #777; }
        .hours { margin-top: 1px; color: #555; }
        .sales { margin-top: 3px; font-variant-numeric: tabular-nums; }

        .footer { margin-top: 18px; padding-top: 6px; border-top: 1px solid #ddd; color: #777; font-size: 9px; }
        .footer-title { font-weight: bold; margin-bottom: 2px; }
    </style>
</head>
<body>
    {{-- That these figures cover the whole business is part of the first line,
         not a note under it: a file that has left the screen has nothing else to
         say what it is about, and a reader who assumed one branch would read a
         person's month as far quieter than it was. --}}
    <div class="header">
        <div class="biz-name">{{ $businessName }} (All branches)</div>
        <div class="report-title">
            Employee Performance — {{ $employee['name'] }}
            <span class="muted">({{ $employee['username'] }} · {{ ucfirst($employee['role']) }}@if ($employee['archived']) · archived @endif)</span>
        </div>
        <div class="report-title" style="font-weight: normal;">{{ $label }}</div>
    </div>
    <p class="report-note">
        A shift is shown on the day it started, so a night shift crossing midnight is one entry (+1d).
        Sales are counted on the day they were rung, so that shift's after-midnight sales appear on the next day.
        Net sales recomputed as gross − returns − defects.
    </p>

    <table class="cards">
        <tr>
            <td>
                <div class="card-label">Days worked</div>
                <div class="card-value">{{ $summary['days_worked'] }}</div>
            </td>
            <td>
                <div class="card-label">Hours</div>
                <div class="card-value">{{ number_format((float) $summary['hours'], 2) }}</div>
            </td>
            <td>
                <div class="card-label">Transactions</div>
                <div class="card-value">{{ $summary['transactions'] }}</div>
            </td>
            <td>
                <div class="card-label">Net sales</div>
                <div class="card-value">{{ $peso($summary['net_sales']) }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="card-label">Gross sales</div>
                <div class="card-value">{{ $peso($summary['gross_sales']) }}</div>
            </td>
            <td>
                <div class="card-label">Returns</div>
                <div class="card-value">{{ $peso($summary['returns']) }}</div>
            </td>
            <td>
                <div class="card-label">Defects</div>
                <div class="card-value">{{ $peso($summary['defects']) }}</div>
            </td>
            <td>
                <div class="card-label">Net sales per hour</div>
                <div class="card-value">{{ $summary['sales_per_hour'] === null ? '—' : $peso($summary['sales_per_hour']) }}</div>
            </td>
        </tr>
    </table>

    @if (count($summary['hours_by_branch']) > 0)
        {{-- Where the hours above were worked. Printed whenever it is known,
             including for a single branch: "84.50 hours, all of them at MNL" is
             an answer, and its absence would read as a missing one. --}}
        <p class="muted">
            Hours by branch:
            {{ implode(' · ', array_map(
                fn (array $branch): string => $branch['code'].' '.number_format((float) $branch['hours'], 2).'h',
                $summary['hours_by_branch'],
            )) }}
        </p>
    @endif

    @if ($summary['open_shifts'] > 0)
        {{-- Stated next to the hours it is deliberately missing from. The app
             records an unclean exit on the NEXT launch, so its timestamp says a
             shift ended and says nothing trustworthy about when. --}}
        <p class="muted">
            {{ $summary['open_shifts'] }} {{ \Illuminate\Support\Str::plural('shift', $summary['open_shifts']) }}
            with no recorded sign-out {{ $summary['open_shifts'] === 1 ? 'is' : 'are' }} excluded from the hours above.
        </p>
    @endif

    <div class="section-title">{{ $label }}</div>

    @if (count($weeks) === 0)
        <p class="muted">Nothing to report for this month.</p>
    @else
        <table class="calendar">
            <thead>
                <tr>
                    @foreach ($weekdays as $weekday)
                        <th>{{ $weekday }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($weeks as $week)
                    <tr>
                        @foreach ($week as $day)
                            @if ($day === null)
                                <td class="empty"></td>
                            @else
                                <td>
                                    <div class="daynum">{{ $day['day'] }}</div>
                                    @foreach ($day['shifts'] as $shift)
                                        <div class="shift">
                                            @if ($shift['open'])
                                                <span class="open">{{ $time($shift['start']) }} → no sign-out</span>
                                            @else
                                                {{ $time($shift['start']) }} → {{ $time($shift['end']) }}@if ($shift['crosses_midnight'])<span class="plus"> +1d</span>@endif
                                            @endif
                                            {{-- Where they signed in. The report spans the
                                                 business, so a month at two branches has to
                                                 say which day was which. --}}
                                            @if ($shift['branch'])<span class="muted"> · {{ $shift['branch'] }}</span>@endif
                                        </div>
                                    @endforeach
                                    @if ($day['hours'] !== null)
                                        <div class="hours">{{ $hours($day['hours']) }}</div>
                                    @endif
                                    @if ($day['transactions'] > 0)
                                        <div class="sales">{{ $day['transactions'] }} · {{ $peso($day['net_sales']) }}</div>
                                    @endif
                                </td>
                            @endif
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- How this document came to exist. The branch named here is the DEVICE's,
         not the report's: an Owner standing at one branch can email another
         branch's figures, and the two facts are different ones. --}}
    <div class="footer">
        <div class="footer-title">Report generation details</div>
        <div>Generated {{ $generatedAt->format('M j, Y g:i A') }}</div>
        <div>Generated at {{ $generatedFrom }}</div>
        <div>Generated on request.</div>
    </div>
</body>
</html>
