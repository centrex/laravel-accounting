@php $status = $row->status->value; @endphp
<div class="flex justify-end">
    <a href="{{ route('accounting.invoices.show', $row) }}" class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-primary transition hover:bg-primary/10">
        <x-tallui-icon name="o-eye" class="h-4 w-4" /> View
    </a>
    
    <div x-data="{ open: false }" class="relative">
        <button type="button" @click="open = !open" class="btn btn-ghost btn-xs btn-circle" aria-label="Row actions">
            <x-tallui-icon name="o-ellipsis-horizontal" class="h-4 w-4" />
        </button>
        <div
            x-show="open"
            x-transition.opacity
            @click.outside="open = false"
            @click="open = false"
            class="absolute right-0 top-9 z-50 w-52 rounded-xl border border-base-300 bg-base-100 p-1.5 shadow-theme-xl"
            style="display: none;"
        >
            @if($status === 'draft')
                <button type="button" @click="open = false; $dispatch('open-dialog', 'confirm-post-{{ $row->id }}')" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-info transition hover:bg-info/10">
                    <x-tallui-icon name="o-paper-airplane" class="h-4 w-4" /> Post
                </button>
            @endif
                        
            @if(in_array($status, ['sent', 'issued', 'partially_settled', 'overdue'], true) && $row->base_balance > 0)
                <button type="button" wire:click="$dispatch('invoice-table:pay', { id: {{ $row->id }} })" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm text-success transition hover:bg-success/10">
                    <x-tallui-icon name="o-banknotes" class="h-4 w-4" /> Record payment
                </button>
            @endif

            <button type="button" wire:click="$dispatch('invoice-table:audit', { id: {{ $row->id }} })" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm transition hover:bg-base-200">
                <x-tallui-icon name="o-clock" class="h-4 w-4 text-base-content/40" /> Audit trail
            </button>
        </div>
    </div>

    @if($status === 'draft')
        <x-tallui-dialog id="confirm-post-{{ $row->id }}" type="confirm" title="Post this invoice?" size="sm">
            Posting invoice {{ $row->invoice_number }} will create the journal entry and lock it for editing. This cannot be undone.
            <x-slot:footer>
                <button type="button" @click="open = false" class="btn btn-ghost flex-1">Cancel</button>
                <button
                    type="button"
                    wire:click="$dispatch('invoice-table:post', { id: {{ $row->id }} })"
                    @click="open = false"
                    class="btn btn-info flex-1"
                >Post Invoice</button>
            </x-slot:footer>
        </x-tallui-dialog>
    @endif
</div>
