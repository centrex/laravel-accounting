@php
    $overdue = $row->due_date->isPast() && !in_array($row->status->value ?? $row->status, ['settled', 'void'], true);
@endphp
<span class="text-sm {{ $overdue ? 'text-error font-medium' : 'text-base-content/60' }}">
    {{ $row->due_date->format('M d, Y') }}
</span>
