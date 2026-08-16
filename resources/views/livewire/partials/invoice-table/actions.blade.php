@php
    $status = $row->status->value;

    $moreActions = [];

    if (in_array($status, ['sent', 'issued', 'partially_settled', 'overdue'], true) && $row->base_balance > 0) {
        $moreActions[] = [
            'label'      => 'Record payment',
            'icon'       => 'o-banknotes',
            'color'      => 'success',
            'attributes' => ['wire:click' => "\$dispatch('invoice-table:pay', { id: {$row->id} })"],
        ];
    }

    $moreActions[] = [
        'label'      => 'Audit trail',
        'icon'       => 'o-clock',
        'attributes' => ['wire:click' => "\$dispatch('invoice-table:audit', { id: {$row->id} })"],
    ];
@endphp
<div class="flex justify-end items-center gap-1">
    <x-tallui-button icon="o-eye" :link="route('accounting.invoices.show', $row)" class="btn-ghost btn-xs" label="View" :responsive="true" wire:navigate />
    @if($status === 'draft')
        <x-tallui-button icon="o-paper-airplane" @click="$dispatch('open-dialog', 'confirm-post-{{ $row->id }}')" class="btn-ghost btn-xs text-info" label="Post" :responsive="true" />
    @endif
    <x-tallui-dropdown position="bottom-end" :items="$moreActions">
        <x-slot:trigger>
            <x-tallui-button icon="o-ellipsis-vertical" class="btn-ghost btn-xs" />
        </x-slot:trigger>
    </x-tallui-dropdown>

    @if($status === 'draft')
        <x-tallui-dialog id="confirm-post-{{ $row->id }}" type="confirm" title="Post this invoice?" size="sm">
            Posting invoice {{ $row->invoice_number }} will create the journal entry and lock it for editing. This cannot be undone.
            <x-slot:footer>
                <button type="button" @click="open = false" class="btn btn-ghost flex-1">Cancel</button>
                <button
                    type="button"
                    wire:click="$dispatch('invoice-table:post', { id: {{ $row->id }} })"
                    wire:loading.attr="disabled"
                    @click="open = false"
                    class="btn btn-info flex-1"
                >Post Invoice</button>
            </x-slot:footer>
        </x-tallui-dialog>
    @endif
</div>
