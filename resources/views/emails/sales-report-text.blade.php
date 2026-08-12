@php
    $symbol = ($business['currency_symbol'] ?? '') !== '' ? $business['currency_symbol'] : '₱';
    $peso = fn ($value) => $symbol . number_format((float) $value, 2);
    $minus = fn ($value) => (float) $value > 0 ? '-' . $peso($value) : $peso($value);
    $businessName = ($business['business_name'] ?? '') !== '' ? $business['business_name'] : 'Evitan Plus';
@endphp
{{ $businessName }}
{{ $scopeLabel }} - {{ $periodLabel }}

Transactions:   {{ $totals['transactions'] }}
Gross sales:    {{ $peso($totals['gross_sales']) }}
Returns:        {{ $minus($totals['returns']) }}
Defects:        {{ $minus($totals['defects']) }}
Net sales:      {{ $peso($totals['net_sales']) }}
@if ($canViewCost)
Profit:         {{ $peso($totals['profit'] ?? '0') }}
Uncosted sales: {{ $peso($totals['uncosted_sales'] ?? '0') }}
@endif

The full breakdown - a net-sales chart, one row per {{ strtolower($periodNoun) }}, and the
top selling items - is in the attached PDF.

@if ($sentBy !== null)
Sent by {{ $sentBy }} on {{ $generatedAt->format('M j, Y \a\t g:i A') }}.
@else
Sent automatically on {{ $generatedAt->format('M j, Y \a\t g:i A') }}.
@endif
