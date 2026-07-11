@if ($row->customer)
    <a href="{{ route('accounting.customers.ledger', $row->customer) }}" wire:navigate class="text-sm font-medium text-primary hover:underline">
        {{ $row->customer->organization_name ?: $row->customer->name }}
    </a>
@endif
