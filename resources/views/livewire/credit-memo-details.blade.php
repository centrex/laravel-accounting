<div>
<x-tallui-notification />

<x-tallui-page-header :title="$creditMemo->credit_memo_number" subtitle="Credit memo" icon="o-receipt-refund">
    <x-slot:actions>
        <x-tallui-button :link="route('accounting.credit-memos')" icon="o-arrow-left" class="btn-ghost btn-sm">All Credit Memos</x-tallui-button>

        @if($creditMemo->status->value === 'draft')
            <x-tallui-button wire:click="issueMemo"
                wire:confirm="Issue {{ $creditMemo->credit_memo_number }}? This posts DR Sales Returns / CR Accounts Receivable."
                icon="o-check-circle" class="btn-primary btn-sm">Issue</x-tallui-button>
            <x-tallui-button wire:click="voidMemo"
                wire:confirm="Void {{ $creditMemo->credit_memo_number }}?"
                icon="o-x-circle" class="btn-error btn-sm">Void</x-tallui-button>
        @endif

        @if($canRefund)
            <x-tallui-button wire:click="openRefundModal" icon="o-banknotes" class="btn-success btn-sm">Record Refund</x-tallui-button>
        @endif
    </x-slot:actions>
</x-tallui-page-header>

{{-- Summary cards --}}
<div class="grid gap-4 md:grid-cols-3 mb-4">
    <x-tallui-card title="Memo" icon="o-receipt-refund">
        <div class="space-y-1.5 text-sm">
            <div class="flex justify-between">
                <span class="text-base-content/50">Status</span>
                <x-tallui-badge :type="match($creditMemo->status->value) {
                    'issued'             => 'info',
                    'partially_refunded' => 'warning',
                    'refunded'           => 'success',
                    'void'               => 'error',
                    default              => 'neutral',
                }">{{ $creditMemo->status->label() }}</x-tallui-badge>
            </div>
            <div class="flex justify-between">
                <span class="text-base-content/50">Date</span>
                <span>{{ $creditMemo->credit_memo_date->format('d M Y') }}</span>
            </div>
            @if($creditMemo->reason)
                <div class="flex justify-between">
                    <span class="text-base-content/50">Reason</span>
                    <span class="text-right">{{ $creditMemo->reason }}</span>
                </div>
            @endif
            @if($creditMemo->source_reference)
                <div class="flex justify-between">
                    <span class="text-base-content/50">Source</span>
                    <span class="font-mono">{{ $creditMemo->source_reference }}</span>
                </div>
            @endif
            @if($creditMemo->issued_at)
                <div class="flex justify-between">
                    <span class="text-base-content/50">Issued at</span>
                    <span>{{ $creditMemo->issued_at->format('d M Y, h:i A') }}</span>
                </div>
            @endif
        </div>
    </x-tallui-card>

    <x-tallui-card title="Invoice & Customer" icon="o-document-text">
        <div class="space-y-1.5 text-sm">
            <div class="flex justify-between">
                <span class="text-base-content/50">Invoice</span>
                <a href="{{ route('accounting.invoices.show', $creditMemo->invoice_id) }}" class="font-mono text-primary hover:underline">
                    {{ $creditMemo->invoice?->invoice_number ?? '—' }}
                </a>
            </div>
            <div class="flex justify-between">
                <span class="text-base-content/50">Invoice total</span>
                <span class="font-mono">{{ number_format((float) ($creditMemo->invoice?->total ?? 0), 2) }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-base-content/50">Invoice balance</span>
                <span class="font-mono">{{ number_format((float) ($creditMemo->invoice?->balance ?? 0), 2) }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-base-content/50">Customer</span>
                <span>{{ $creditMemo->customer?->organization_name ?? $creditMemo->customer?->name ?? '—' }}</span>
            </div>
        </div>
    </x-tallui-card>

    <x-tallui-card title="Amounts" icon="o-banknotes">
        <div class="space-y-1.5 text-sm">
            <div class="flex justify-between">
                <span class="text-base-content/50">Credit (excl. tax)</span>
                <span class="font-mono">{{ number_format((float) $creditMemo->subtotal, 2) }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-base-content/50">Tax reversal</span>
                <span class="font-mono">{{ number_format((float) $creditMemo->tax_amount, 2) }}</span>
            </div>
            <div class="flex justify-between font-semibold">
                <span>Total credit</span>
                <span class="font-mono">{{ number_format((float) $creditMemo->total, 2) }} {{ $creditMemo->currency }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-base-content/50">Refunded</span>
                <span class="font-mono">{{ number_format((float) $creditMemo->amount_refunded, 2) }}</span>
            </div>
            <div class="flex justify-between font-semibold {{ $creditMemo->refundable_amount > 0 && $creditMemo->status->value !== 'draft' ? 'text-warning' : '' }}">
                <span>Refundable</span>
                <span class="font-mono">{{ number_format($creditMemo->refundable_amount, 2) }}</span>
            </div>
        </div>
    </x-tallui-card>
</div>

@if($creditMemo->notes)
    <x-tallui-card class="mb-4" title="Notes">
        <p class="text-sm whitespace-pre-line">{{ $creditMemo->notes }}</p>
    </x-tallui-card>
@endif

{{-- Journal entry --}}
@if($creditMemo->journalEntry)
    <x-tallui-card class="mb-4" title="Journal Entry {{ $creditMemo->journalEntry->entry_number }}" icon="o-pencil-square" padding="none">
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                        <th class="pl-5">Account</th>
                        <th>Description</th>
                        <th class="text-right">Debit</th>
                        <th class="text-right pr-5">Credit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-200">
                    @foreach($creditMemo->journalEntry->lines as $line)
                        <tr class="even:bg-base-200/50">
                            <td class="pl-5 text-sm">{{ $line->account?->code }} — {{ $line->account?->name }}</td>
                            <td class="text-sm text-base-content/60">{{ $line->description }}</td>
                            <td class="text-right font-mono text-sm">{{ $line->type === 'debit' ? number_format((float) $line->amount, 2) : '—' }}</td>
                            <td class="text-right font-mono text-sm pr-5">{{ $line->type === 'credit' ? number_format((float) $line->amount, 2) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-tallui-card>
@endif

{{-- Refunds --}}
<x-tallui-card title="Refunds" icon="o-banknotes" padding="none">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                    <th class="pl-5">Refund #</th>
                    <th>Date</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th class="text-right pr-5">Amount</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse($creditMemo->payments as $payment)
                    <tr class="even:bg-base-200/50">
                        <td class="pl-5 font-mono text-sm">{{ $payment->payment_number }}</td>
                        <td class="text-sm">{{ $payment->payment_date->format('d M Y') }}</td>
                        <td class="text-sm">{{ ucwords(str_replace('_', ' ', $payment->payment_method)) }}</td>
                        <td class="text-sm text-base-content/60">{{ $payment->reference ?? '—' }}</td>
                        <td class="text-right font-mono text-sm pr-5">{{ number_format((float) $payment->amount, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="py-8 text-center text-sm text-base-content/50">
                            No refunds recorded yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-tallui-card>

{{-- Refund Modal --}}
<x-tallui-modal wire:model="showRefundModal" title="Record Refund — {{ $creditMemo->credit_memo_number }}" size="md">
    <form wire:submit="recordRefund" class="space-y-4">

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Amount" :required="true">
                <x-tallui-input type="number" min="0.01" step="0.01" wire:model="refund_amount" class="input-sm text-right" />
                <x-tallui-error-message field="refund_amount" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Date" :required="true">
                <x-tallui-input type="date" wire:model="refund_date" class="input-sm" />
                <x-tallui-error-message field="refund_date" />
            </x-tallui-form-group>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Method" :required="true">
                <x-tallui-select wire:model="refund_method" class="select-sm">
                    <option value="cash">Cash</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="check">Check</option>
                    <option value="card">Card</option>
                    <option value="mobile_banking">Mobile Banking</option>
                    <option value="other">Other</option>
                </x-tallui-select>
                <x-tallui-error-message field="refund_method" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Pay from" :required="true">
                <x-tallui-select wire:model="refund_account_code" class="select-sm">
                    @foreach($this->refundAccounts as $account)
                        <option value="{{ $account->code }}">{{ $account->code }} — {{ $account->name }}</option>
                    @endforeach
                </x-tallui-select>
                <x-tallui-error-message field="refund_account_code" />
            </x-tallui-form-group>
        </div>

        <x-tallui-form-group label="Reference">
            <x-tallui-input wire:model="refund_reference" placeholder="Cheque no, transaction id…" class="input-sm" />
        </x-tallui-form-group>

        <x-tallui-form-group label="Notes">
            <x-tallui-textarea wire:model="refund_notes" placeholder="Internal notes…" :rows="2" />
        </x-tallui-form-group>

        <x-tallui-alert type="info">
            Posts DR Accounts Receivable / CR the selected cash or bank account.
        </x-tallui-alert>

        <div class="flex justify-end gap-2 pt-2">
            <x-tallui-button type="button" wire:click="$set('showRefundModal', false)" class="btn-ghost btn-sm">Cancel</x-tallui-button>
            <x-tallui-button type="submit" class="btn-success btn-sm" :spinner="'recordRefund'">Record Refund</x-tallui-button>
        </div>
    </form>
</x-tallui-modal>

</div>
