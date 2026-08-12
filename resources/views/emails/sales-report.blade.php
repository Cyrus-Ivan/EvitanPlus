{{--
    The sales report email body. The full breakdown is the attached PDF; this is
    the summary a recipient reads without opening it.

    Plain self-contained HTML with inline styles rather than the `x-mail::message`
    markdown components: no mail theme is published in this app, and the markdown
    renderer would buy nothing here.

    Cost / Uncosted / Profit are Owner-only and guarded by $canViewCost, exactly
    as in reports/pdf.blade.php. Never emit a cost figure when it is false.
--}}
@php
    $symbol = ($business['currency_symbol'] ?? '') !== '' ? $business['currency_symbol'] : '₱';
    $peso = fn ($value) => $symbol . number_format((float) $value, 2);
    $minus = fn ($value) => (float) $value > 0 ? '−' . $peso($value) : $peso($value);
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
            {{ $scopeLabel }} · {{ $periodLabel }}
        </div>
    </div>

    <table role="presentation" style="width:100%; border-collapse:collapse; margin-bottom:16px;">
        <tr>
            <td style="padding:6px 0; font-size:13px; color:#57534e;">Transactions</td>
            <td style="padding:6px 0; font-size:13px; text-align:right; font-weight:bold;">{{ $totals['transactions'] }}</td>
        </tr>
        <tr>
            <td style="padding:6px 0; font-size:13px; color:#57534e;">Gross sales</td>
            <td style="padding:6px 0; font-size:13px; text-align:right;">{{ $peso($totals['gross_sales']) }}</td>
        </tr>
        <tr>
            <td style="padding:6px 0; font-size:13px; color:#57534e;">Returns</td>
            <td style="padding:6px 0; font-size:13px; text-align:right;">{{ $minus($totals['returns']) }}</td>
        </tr>
        <tr>
            <td style="padding:6px 0; font-size:13px; color:#57534e;">Defects</td>
            <td style="padding:6px 0; font-size:13px; text-align:right;">{{ $minus($totals['defects']) }}</td>
        </tr>
        <tr>
            <td style="padding:10px 0 0; font-size:14px; font-weight:bold; border-top:1px solid #e7e5e4;">Net sales</td>
            <td style="padding:10px 0 0; font-size:14px; text-align:right; font-weight:bold; border-top:1px solid #e7e5e4;">{{ $peso($totals['net_sales']) }}</td>
        </tr>
        @if ($canViewCost)
            <tr>
                <td style="padding:6px 0; font-size:13px; color:#57534e;">Profit</td>
                <td style="padding:6px 0; font-size:13px; text-align:right;">{{ $peso($totals['profit'] ?? '0') }}</td>
            </tr>
            <tr>
                <td style="padding:6px 0; font-size:13px; color:#57534e;">Uncosted sales</td>
                <td style="padding:6px 0; font-size:13px; text-align:right;">{{ $peso($totals['uncosted_sales'] ?? '0') }}</td>
            </tr>
        @endif
    </table>

    <p style="font-size:13px; color:#57534e; line-height:1.5; margin:0 0 16px;">
        The full breakdown — a net-sales chart, one row per {{ strtolower($periodNoun) }}, and the top selling
        items — is in the attached PDF.
    </p>

    <p style="font-size:12px; color:#78716c; line-height:1.5; margin:0; padding-top:12px; border-top:1px solid #e7e5e4;">
        @if ($sentBy !== null)
            Sent by {{ $sentBy }} on {{ $generatedAt->format('M j, Y \a\t g:i A') }}.
        @else
            Sent automatically on {{ $generatedAt->format('M j, Y \a\t g:i A') }}.
        @endif
    </p>

</div>
</body>
</html>
