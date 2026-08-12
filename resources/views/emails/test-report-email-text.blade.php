@php
    $businessName = ($business['business_name'] ?? '') !== '' ? $business['business_name'] : 'Evitan Plus';
    $branch = $branchCode !== null && $branchName !== null ? $branchCode . ' - ' . $branchName : 'this branch';
@endphp
{{ $businessName }}

Report email is working. Sales reports from {{ $branch }} will arrive at this
address.

Test message sent on {{ $sentAt->format('M j, Y \a\t g:i A') }}. No report is attached.
