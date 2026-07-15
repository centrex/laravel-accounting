<div>
<x-tallui-notification />

<x-tallui-page-header title="Credit Memos" subtitle="Credit customers for sale returns and pay out refunds" icon="o-receipt-refund">
    <x-slot:actions>
        <x-tallui-button wire:click="openCreate" icon="o-plus" class="btn-primary btn-sm">New Credit Memo</x-tallui-button>
    </x-slot:actions>
</x-tallui-page-header>

{{-- Filters --}}
<x-tallui-card class="mb-4" padding="compact">
    <div class="flex flex-wrap gap-3 items-end p-1">
        <div class="flex-1 min-w-52">
            <x-tallui-form-group label="Search">
                <x-tallui-input wire:model.live.debounce.300ms="search" placeholder="Memo #, invoice # or customer…" class="input-sm" />
            </x-tallui-form-group>
        </div>
        <div class="w-44">
            <x-tallui-form-group label="Status">
                <x-tallui-select wire:model.live="statusFilter" class="select-sm">
                    <option value="">All</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>
        </div>
        <div class="w-36">
            <x-tallui-form-group label="From">
                <x-tallui-input type="date" wire:model.live="dateFrom" class="input-sm" />
            </x-tallui-form-group>
        </div>
        <div class="w-36">
            <x-tallui-form-group label="To">
                <x-tallui-input type="date" wire:model.live="dateTo" class="input-sm" />
            </x-tallui-form-group>
        </div>
    </div>
</x-tallui-card>

{{-- Credit Memos Table --}}
<x-tallui-card padding="none">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                    <th class="pl-5">Memo #</th>
                    <th>Invoice</th>
                    <th>Customer</th>
                    <th>Date</th>
                    <th>Reason</th>
                    <th class="text-right">Total</th>
                    <th class="text-right">Refunded</th>
                    <th>Status</th>
                    <th class="pr-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse($memos as $memo)
                    <tr class="even:bg-base-200/50 hover:bg-base-200">
                        <td class="pl-5 font-mono text-sm text-primary font-semibold">
                            <a href="{{ route('accounting.credit-memos.show', $memo) }}" class="hover:underline">
                                {{ $memo->credit_memo_number }}
                            </a>
                        </td>
                        <td class="font-mono text-sm">
                            <a href="{{ route('accounting.invoices.show', $memo->invoice_id) }}" class="hover:underline">
                                {{ $memo->invoice?->invoice_number ?? '—' }}
                            </a>
                        </td>
                        <td class="text-sm">{{ $memo->customer?->organization_name ?? $memo->customer?->name ?? '—' }}</td>
                        <td class="text-sm text-base-content/60">{{ $memo->credit_memo_date->format('d M Y') }}</td>
                        <td class="text-sm text-base-content/60 max-w-40 truncate">{{ $memo->reason ?? $memo->source_reference ?? '—' }}</td>
                        <td class="text-right text-sm font-mono font-medium">{{ number_format((float) $memo->total, 2) }}</td>
                        <td class="text-right text-sm font-mono text-base-content/60">{{ number_format((float) $memo->amount_refunded, 2) }}</td>
                        <td>
                            <x-tallui-badge :type="match($memo->status->value) {
                                'issued'             => 'info',
                                'partially_refunded' => 'warning',
                                'refunded'           => 'success',
                                'void'               => 'error',
                                default              => 'neutral',
                            }">{{ $memo->status->label() }}</x-tallui-badge>
                        </td>
                        <td class="pr-5">
                            <div class="flex justify-end gap-1">
                                <x-tallui-button :link="route('accounting.credit-memos.show', $memo)"
                                    class="btn-ghost btn-xs" icon="o-eye" />

                                @if($memo->status->value === 'draft')
                                    <x-tallui-button wire:click="issueMemo({{ $memo->id }})"
                                        wire:confirm="Issue {{ $memo->credit_memo_number }}? This posts DR Sales Returns / CR Accounts Receivable."
                                        class="btn-primary btn-xs">Issue</x-tallui-button>
                                    <x-tallui-button wire:click="voidMemo({{ $memo->id }})"
                                        wire:confirm="Void {{ $memo->credit_memo_number }}?"
                                        class="btn-error btn-xs" icon="o-x-circle" />
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="py-12">
                            <x-tallui-empty-state title="No credit memos found"
                                description="Credit memos record what you owe a customer back after a sale return."
                                icon="o-receipt-refund" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($memos->hasPages())
        <div class="p-4 border-t border-base-200">{{ $memos->links() }}</div>
    @endif
</x-tallui-card>

{{-- Create Modal --}}
<x-tallui-modal wire:model="showModal" title="New Credit Memo" size="lg">
    <form wire:submit="save" class="space-y-4">

        <x-tallui-form-group label="Invoice" :required="true">
            <x-tallui-select wire:model="invoice_id" class="select-sm">
                <option value="">— Select posted invoice —</option>
                @foreach($invoices as $invoice)
                    <option value="{{ $invoice->id }}">
                        {{ $invoice->invoice_number }} — {{ $invoice->customer?->organization_name ?? $invoice->customer?->name }} ({{ number_format((float) $invoice->total, 2) }} {{ $invoice->currency }})
                    </option>
                @endforeach
            </x-tallui-select>
            <x-tallui-error-message field="invoice_id" />
        </x-tallui-form-group>

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Date" :required="true">
                <x-tallui-input type="date" wire:model="memo_date" class="input-sm" />
                <x-tallui-error-message field="memo_date" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Reason">
                <x-tallui-input wire:model="reason" placeholder="e.g. Sale return SR-0001" class="input-sm" />
                <x-tallui-error-message field="reason" />
            </x-tallui-form-group>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Credit amount (excl. tax)" :required="true">
                <x-tallui-input type="number" min="0.01" step="0.01" wire:model="subtotal" class="input-sm text-right" />
                <x-tallui-error-message field="subtotal" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Tax reversal">
                <x-tallui-input type="number" min="0" step="0.01" wire:model="tax_amount" class="input-sm text-right" />
                <x-tallui-error-message field="tax_amount" />
            </x-tallui-form-group>
        </div>

        <x-tallui-form-group label="Notes">
            <x-tallui-textarea wire:model="notes" placeholder="Internal notes…" :rows="2" />
        </x-tallui-form-group>

        <x-tallui-alert type="info">
            The memo is created as a draft with no accounting impact. Issuing it posts
            DR Sales Returns &amp; Allowances (+ tax reversal) / CR Accounts Receivable.
        </x-tallui-alert>

        <div class="flex justify-end gap-2 pt-2">
            <x-tallui-button type="button" wire:click="$set('showModal', false)" class="btn-ghost btn-sm">Cancel</x-tallui-button>
            <x-tallui-button type="submit" class="btn-primary btn-sm" :spinner="'save'">Create Draft</x-tallui-button>
        </div>
    </form>
</x-tallui-modal>

</div>
