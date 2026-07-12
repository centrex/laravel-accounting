@php $status = $row->status->value ?? $row->status; @endphp
<div class="flex justify-end gap-1">
    <a href="{{ route('accounting.bills.show', $row) }}" class="btn btn-ghost btn-xs">View</a>
    <x-tallui-button wire:click="$dispatch('bill-table:audit', { id: {{ $row->getKey() }} })" icon="o-clock" class="btn-ghost btn-xs" title="Audit trail" />
    @if($status === 'draft')
        <x-tallui-button
            wire:click="$dispatch('bill-table:post', { id: {{ $row->getKey() }} })"
            wire:confirm="Approve bill {{ $row->bill_number }}?"
            class="btn-info btn-xs"
        >Approve</x-tallui-button>
    @endif
    @if(in_array($status, ['sent', 'issued', 'partially_settled', 'overdue'], true) && $row->base_balance > 0)
        <x-tallui-button wire:click="$dispatch('bill-table:pay', { id: {{ $row->getKey() }} })" class="btn-success btn-xs">Pay</x-tallui-button>
    @endif
</div>
