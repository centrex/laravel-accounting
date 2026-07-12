@if($row->chargeable_type && $row->chargeable)
    @php $isInvoice = str_contains($row->chargeable_type, 'Invoice') @endphp
    @if($isInvoice)
        <a href="{{ route('accounting.invoices.show', $row->chargeable) }}" wire:navigate class="font-mono text-sm text-primary hover:underline">
            {{ $row->chargeable->invoice_number }}
        </a>
    @else
        <a href="{{ route('accounting.bills.show', $row->chargeable) }}" wire:navigate class="font-mono text-sm text-primary hover:underline">
            {{ $row->chargeable->bill_number }}
        </a>
    @endif
@else
    <span class="text-base-content/30">—</span>
@endif
