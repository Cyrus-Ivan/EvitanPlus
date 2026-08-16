{{--
    The employee performance email body. Each employee's calendar is their own
    attached PDF; this is the line a recipient reads without opening one.

    Plain self-contained HTML with inline styles rather than the `x-mail::message`
    markdown components: no mail theme is published in this app, and the markdown
    renderer would buy nothing here.

    There is no cost, COGS or profit on this report at all, so unlike
    emails/sales-report.blade.php there is no $canViewCost guard to get wrong.
--}}
@php
    $symbol = ($business['currency_symbol'] ?? '') !== '' ? $business['currency_symbol'] : '₱';
    $peso = fn ($value) => $symbol . number_format((float) $value, 2);
    $businessName = ($business['business_name'] ?? '') !== '' ? $business['business_name'] : 'Evitan Plus';
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="color-scheme" content="light">
</head>
<body style="margin:0; padding:24px; background:#f5f5f4; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; color:#1a1a1a;">
<div style="max-width:560px; margin:0 auto; background:#ffffff; border:1px solid #e7e5e4; border-radius:8px; padding:24px;">

    <div style="border-bottom:2px solid #1a1a1a; padding-bottom:10px; margin-bottom:16px;">
        <div style="font-size:18px; font-weight:bold;">{{ $businessName }}</div>
        <div style="font-size:13px; color:#57534e; margin-top:4px;">
            Employee performance · All branches · {{ $periodLabel }}
        </div>
    </div>

    <table role="presentation" style="width:100%; border-collapse:collapse; margin-bottom:16px;">
        <tr>
            <td style="padding:0 0 6px; font-size:11px; color:#78716c; text-transform:uppercase; letter-spacing:0.04em;">Employee</td>
            <td style="padding:0 0 6px; font-size:11px; color:#78716c; text-transform:uppercase; letter-spacing:0.04em; text-align:right;">Days</td>
            <td style="padding:0 0 6px; font-size:11px; color:#78716c; text-transform:uppercase; letter-spacing:0.04em; text-align:right;">Hours</td>
            <td style="padding:0 0 6px; font-size:11px; color:#78716c; text-transform:uppercase; letter-spacing:0.04em; text-align:right;">Net sales</td>
        </tr>
        @foreach ($employees as $employee)
            <tr>
                <td style="padding:6px 0; font-size:13px; border-top:1px solid #e7e5e4;">{{ $employee['name'] }}</td>
                <td style="padding:6px 0; font-size:13px; text-align:right; border-top:1px solid #e7e5e4;">{{ $employee['summary']['days_worked'] }}</td>
                <td style="padding:6px 0; font-size:13px; text-align:right; border-top:1px solid #e7e5e4;">{{ number_format((float) $employee['summary']['hours'], 2) }}</td>
                <td style="padding:6px 0; font-size:13px; text-align:right; border-top:1px solid #e7e5e4; font-weight:bold;">{{ $peso($employee['summary']['net_sales']) }}</td>
            </tr>
        @endforeach
    </table>

    <p style="font-size:13px; color:#57534e; line-height:1.5; margin:0 0 16px;">
        Each employee's month — the calendar of shifts worked and the sales rung on each day —
        is in their own attached PDF.
    </p>

    <p style="font-size:12px; color:#78716c; line-height:1.5; margin:0; padding-top:12px; border-top:1px solid #e7e5e4;">
        @if ($sentBy !== null)
            Sent by {{ $sentBy }} on {{ $generatedAt->format('M j, Y \a\t g:i A') }}.
        @else
            Sent on {{ $generatedAt->format('M j, Y \a\t g:i A') }}.
        @endif
    </p>

</div>
</body>
</html>
