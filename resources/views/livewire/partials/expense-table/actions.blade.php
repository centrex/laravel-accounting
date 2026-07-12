<div class="flex justify-end gap-1">
    <a href="{{ route('accounting.expenses.show', $row) }}" wire:navigate class="btn btn-ghost btn-xs">View</a>
    <x-tallui-button wire:click="$dispatch('expense-table:audit', { id: {{ $row->getKey() }} })" icon="o-clock" class="btn-ghost btn-xs" title="Audit trail" />
    @if($row->status === 'draft')
        <x-tallui-button
            wire:click="$dispatch('expense-table:post', { id: {{ $row->getKey() }} })"
            wire:confirm="Post expense {{ $row->expense_number }}?"
            class="btn-info btn-xs"
        >Post</x-tallui-button>
        @can('accounting.expense.delete')
            <x-tallui-button
                wire:click="$dispatch('expense-table:delete', { id: {{ $row->getKey() }} })"
                wire:confirm="Delete expense {{ $row->expense_number }}? This cannot be undone."
                class="btn-error btn-xs btn-outline"
            >Delete</x-tallui-button>
        @endcan
    @endif
    @if(in_array($row->status, ['approved', 'partial'], true) && $row->balance > 0)
        <x-tallui-button wire:click="$dispatch('expense-table:pay', { id: {{ $row->getKey() }} })" class="btn-success btn-xs">Pay</x-tallui-button>
    @endif
</div>
