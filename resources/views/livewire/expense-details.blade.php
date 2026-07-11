<div>
<x-tallui-notification />

@php
    $status    = (string) $expense->status;
    $badgeType = match($status) {
        'paid'     => 'success',
        'approved' => 'info',
        'partial'  => 'warning',
        'void'     => 'error',
        default    => 'neutral',
    };
@endphp

{{-- ── Page Header ──────────────────────────────────────────────────────── --}}
<x-tallui-page-header
    title="Expense {{ $expense->expense_number }}"
    subtitle="Expense details and journal entry"
    icon="o-credit-card"
    :separator="true"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Accounting'],
            ['label' => 'Expenses', 'href' => route('accounting.expenses')],
            ['label' => $expense->expense_number],
        ]" />
    </x-slot:breadcrumbs>

    <x-slot:actions>
        <a href="{{ route('accounting.expenses') }}" wire:navigate class="btn btn-ghost btn-sm">← Back</a>

        @if($status === 'draft')
            <x-tallui-button
                wire:click="postExpense"
                wire:confirm="Post {{ $expense->expense_number }}?"
                class="btn-info btn-sm"
                spinner="postExpense"
            >Post</x-tallui-button>
        @endif

        @if(in_array($status, ['approved', 'partial']) && $expense->balance > 0)
            <x-tallui-button wire:click="openPayModal" class="btn-primary btn-sm" spinner="openPayModal">
                Record Payment
            </x-tallui-button>
        @endif
    </x-slot:actions>
</x-tallui-page-header>

<div class="px-4 md:px-6 space-y-5">

{{-- ── Top cards: info + amounts ───────────────────────────────────────── --}}
<div class="grid gap-4 lg:grid-cols-3">

    {{-- Expense info --}}
    <x-tallui-card class="lg:col-span-2" title="Expense Information">
        <div class="grid gap-x-8 gap-y-3 sm:grid-cols-2">
            <div>
                <div class="text-xs text-base-content/50 uppercase tracking-wide mb-0.5">Expense #</div>
                <div class="font-mono font-semibold text-primary">{{ $expense->expense_number }}</div>
            </div>
            <div>
                <div class="text-xs text-base-content/50 uppercase tracking-wide mb-0.5">Status</div>
                <x-tallui-badge :type="$badgeType">{{ ucfirst($status) }}</x-tallui-badge>
            </div>

            <div>
                <div class="text-xs text-base-content/50 uppercase tracking-wide mb-0.5">Expense Date</div>
                <div class="font-medium">{{ $expense->expense_date->format('d M Y') }}</div>
            </div>
            <div>
                <div class="text-xs text-base-content/50 uppercase tracking-wide mb-0.5">Due Date</div>
                <div class="font-medium">{{ $expense->due_date ? $expense->due_date->format('d M Y') : '—' }}</div>
            </div>

            <div>
                <div class="text-xs text-base-content/50 uppercase tracking-wide mb-0.5">Vendor / Payee</div>
                <div class="font-medium">{{ $expense->vendor_name ?: '—' }}</div>
            </div>
            <div>
                <div class="text-xs text-base-content/50 uppercase tracking-wide mb-0.5">Payment Method</div>
                <div class="font-medium capitalize">{{ str_replace('_', ' ', $expense->payment_method ?? '—') }}</div>
            </div>

            <div>
                <div class="text-xs text-base-content/50 uppercase tracking-wide mb-0.5">Account</div>
                <div class="font-medium">
                    @if($expense->account)
                        <span class="font-mono text-sm text-base-content/70">{{ $expense->account->code }}</span>
                        · {{ $expense->account->name }}
                    @else
                        —
                    @endif
                </div>
            </div>
            <div>
                <div class="text-xs text-base-content/50 uppercase tracking-wide mb-0.5">Reference</div>
                <div class="font-medium font-mono text-sm">{{ $expense->reference ?: '—' }}</div>
            </div>

            @if($expense->chargeable)
                <div class="sm:col-span-2">
                    <div class="text-xs text-base-content/50 uppercase tracking-wide mb-0.5">Linked Document</div>
                    <div class="font-medium text-sm">
                        {{ class_basename($expense->chargeable_type) }} #{{ $expense->chargeable->getKey() }}
                    </div>
                </div>
            @endif

            @if($expense->notes)
                <div class="sm:col-span-2">
                    <div class="text-xs text-base-content/50 uppercase tracking-wide mb-0.5">Notes</div>
                    <div class="text-sm whitespace-pre-line text-base-content/80">{{ $expense->notes }}</div>
                </div>
            @endif
        </div>
    </x-tallui-card>

    {{-- Amounts summary --}}
    <x-tallui-card title="Summary">
        <div class="space-y-3">
            <div class="flex justify-between text-sm">
                <span class="text-base-content/60">Subtotal</span>
                <span class="font-mono">{{ $expense->currency ?? '' }} {{ number_format((float) $expense->subtotal, 2) }}</span>
            </div>
            <div class="flex justify-between text-sm">
                <span class="text-base-content/60">Tax</span>
                <span class="font-mono">{{ number_format((float) $expense->tax_amount, 2) }}</span>
            </div>
            <div class="flex justify-between font-semibold border-t border-base-200 pt-3">
                <span>Total</span>
                <span class="font-mono">{{ number_format((float) $expense->total, 2) }}</span>
            </div>
            <div class="flex justify-between text-sm">
                <span class="text-base-content/60">Paid</span>
                <span class="font-mono text-success">{{ number_format((float) $expense->paid_amount, 2) }}</span>
            </div>
            <div class="flex justify-between font-semibold border-t border-base-200 pt-3 {{ $expense->balance > 0 ? 'text-warning' : 'text-success' }}">
                <span>Balance</span>
                <span class="font-mono">{{ number_format((float) $expense->balance, 2) }}</span>
            </div>
        </div>
    </x-tallui-card>
</div>

{{-- ── Line items ───────────────────────────────────────────────────────── --}}
@if($expense->items->isNotEmpty())
<x-tallui-card title="Line Items" padding="none">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                    <th class="pl-5">Description</th>
                    <th class="text-right w-24">Qty</th>
                    <th class="text-right w-32">Unit Price</th>
                    <th class="text-right w-20">Tax %</th>
                    <th class="text-right w-28">Tax Amt</th>
                    <th class="pr-5 text-right w-32">Amount</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @foreach($expense->items as $item)
                    <tr class="even:bg-base-200/50 hover:bg-base-200">
                        <td class="pl-5 text-sm">{{ $item->description }}</td>
                        <td class="text-right font-mono text-sm">{{ number_format((float) $item->quantity, 2) }}</td>
                        <td class="text-right font-mono text-sm">{{ number_format((float) $item->unit_price, 2) }}</td>
                        <td class="text-right font-mono text-sm">{{ number_format((float) $item->tax_rate, 2) }}%</td>
                        <td class="text-right font-mono text-sm">{{ number_format((float) $item->tax_amount, 2) }}</td>
                        <td class="pr-5 text-right font-mono text-sm font-medium">{{ number_format((float) $item->amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t border-base-200 bg-base-200/50 font-semibold text-sm">
                    <td class="pl-5" colspan="5">Total</td>
                    <td class="pr-5 text-right font-mono">{{ number_format((float) $expense->total, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</x-tallui-card>
@endif

{{-- ── Journal entry ────────────────────────────────────────────────────── --}}
@if($expense->journalEntry)
<x-tallui-card title="Journal Entry" padding="none">
    <div class="px-5 py-3 border-b border-base-200 flex items-center gap-3 text-sm">
        <span class="font-mono font-semibold text-primary">{{ $expense->journalEntry->entry_number }}</span>
        <x-tallui-badge type="neutral">{{ ucfirst((string) $expense->journalEntry->type) }}</x-tallui-badge>
        <span class="text-base-content/50">{{ $expense->journalEntry->date?->format('d M Y') }}</span>
        @if($expense->journalEntry->description)
            <span class="text-base-content/60 ml-auto truncate max-w-xs">{{ $expense->journalEntry->description }}</span>
        @endif
    </div>
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                    <th class="pl-5">Account</th>
                    <th>Description</th>
                    <th class="text-right w-32">Debit</th>
                    <th class="pr-5 text-right w-32">Credit</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @foreach($expense->journalEntry->lines as $line)
                    <tr class="even:bg-base-200/50 hover:bg-base-200">
                        <td class="pl-5 font-mono text-sm">
                            {{ $line->account?->code }} · {{ $line->account?->name }}
                        </td>
                        <td class="text-sm text-base-content/60">{{ $line->description ?? '—' }}</td>
                        <td class="text-right font-mono text-sm">
                            {{ $line->type === 'debit' ? number_format((float) $line->amount, 2) : '—' }}
                        </td>
                        <td class="pr-5 text-right font-mono text-sm">
                            {{ $line->type === 'credit' ? number_format((float) $line->amount, 2) : '—' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-tallui-card>
@endif

</div>{{-- /px wrapper --}}

{{-- ── Pay Modal ────────────────────────────────────────────────────────── --}}
<x-tallui-modal id="pay-expense-detail-modal" title="Record Expense Payment" icon="o-banknotes" size="md">
    <x-slot:trigger>
        <span
            x-effect="if ($wire.showPayModal) $dispatch('open-modal', 'pay-expense-detail-modal'); else $dispatch('close-modal', 'pay-expense-detail-modal')"
            @modal-closed.window="if ($event.detail === 'pay-expense-detail-modal') $wire.showPayModal = false"
        ></span>
    </x-slot:trigger>

    <form wire:submit.prevent="recordPayment" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Payment Date *" :error="$errors->first('pay_date')">
                <x-tallui-input type="date" wire:model="pay_date" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Amount *" :error="$errors->first('pay_amount')">
                <x-tallui-input type="number" step="0.01" wire:model="pay_amount" class="text-right" />
            </x-tallui-form-group>
        </div>
        <x-tallui-form-group label="Payment Method *">
            <x-tallui-select wire:model="pay_method">
                <option value="cash">Cash</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="check">Check</option>
                <option value="card">Card</option>
                <option value="other">Other</option>
            </x-tallui-select>
        </x-tallui-form-group>
        <x-tallui-form-group label="Reference">
            <x-tallui-input wire:model="pay_reference" placeholder="Transaction ID, check #…" />
        </x-tallui-form-group>
        <x-tallui-form-group label="Notes">
            <x-tallui-textarea wire:model="pay_notes" rows="2" />
        </x-tallui-form-group>
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showPayModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="recordPayment" spinner="recordPayment" class="btn-warning">Record Payment</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>
</div>
