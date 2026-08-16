@php
    $symbol = ($business['currency_symbol'] ?? '') !== '' ? $business['currency_symbol'] : '₱';
    $peso = fn ($value) => $symbol . number_format((float) $value, 2);
    $businessName = ($business['business_name'] ?? '') !== '' ? $business['business_name'] : 'Evitan Plus';
@endphp
{{ $businessName }}
Employee performance - All branches - {{ $periodLabel }}

@foreach ($employees as $employee)
{{ $employee['name'] }}
  Days worked: {{ $employee['summary']['days_worked'] }}
  Hours:       {{ number_format((float) $employee['summary']['hours'], 2) }}
  Transactions:{{ $employee['summary']['transactions'] }}
  Net sales:   {{ $peso($employee['summary']['net_sales']) }}

@endforeach
Each employee's month - the calendar of shifts worked and the sales rung on each
day - is in their own attached PDF.

@if ($sentBy !== null)
Sent by {{ $sentBy }} on {{ $generatedAt->format('M j, Y \a\t g:i A') }}.
@else
Sent on {{ $generatedAt->format('M j, Y \a\t g:i A') }}.
@endif
