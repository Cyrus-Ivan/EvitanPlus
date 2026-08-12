@php
    $businessName = ($business['business_name'] ?? '') !== '' ? $business['business_name'] : 'Evitan Plus';
    $branch = $branchCode !== null && $branchName !== null ? $branchCode . ' — ' . $branchName : null;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="color-scheme" content="light">
</head>
<body style="margin:0; padding:24px; background:#f5f5f4; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; color:#1a1a1a;">
<div style="max-width:560px; margin:0 auto; background:#ffffff; border:1px solid #e7e5e4; border-radius:8px; padding:24px;">

    <div style="font-size:18px; font-weight:bold; margin-bottom:12px;">{{ $businessName }}</div>

    <p style="font-size:14px; line-height:1.6; margin:0 0 12px;">
        Report email is working. Sales reports from
        @if ($branch !== null)<strong>{{ $branch }}</strong>@else this branch @endif
        will arrive at this address.
    </p>

    <p style="font-size:12px; color:#78716c; margin:0; padding-top:12px; border-top:1px solid #e7e5e4;">
        Test message sent on {{ $sentAt->format('M j, Y \a\t g:i A') }}. No report is attached.
    </p>

</div>
</body>
</html>
