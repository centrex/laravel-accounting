<div>
<x-tallui-notification />

@php
    $status = $invoice->status->value ?? (string) $invoice->status;
    $badgeType = match($status) {
        'settled' => 'success',
        'issued', 'sent' => 'info',
        'partially_settled', 'partial' => 'warning',
        'overdue', 'void' => 'error',
        default => 'neutral',
    };

    // Collect all journal entries in chronological order:
    // 1. Invoice posting JE
    // 2. Payment JEs
    // 3. Charge JEs (from expenses)
    $allJournals = collect();

    if ($invoice->journalEntry) {
        $allJournals->push([
            'type'  => 'Invoice',
            'badge' => 'primary',
            'entry' => $invoice->journalEntry,
        ]);
    }

    foreach ($invoice->payments as $payment) {
        if ($payment->journalEntry) {
            $allJournals->push([
                'type'  => 'Payment',
                'badge' => 'success',
                'entry' => $payment->journalEntry,
            ]);
        }
    }

    foreach ($invoice->expenses as $charge) {
        if ($charge->journalEntry) {
            $allJournals->push([
                'type'  => $charge->account?->name ?? 'Charge',
                'badge' => match($charge->account?->code ?? '') {
                    '6310', '6320', '6330', '6340' => 'info',
                    '6130' => 'error',
                    default => 'neutral',
                },
                'entry' => $charge->journalEntry,
            ]);
        }
    }

    $allJournals = $allJournals->sortBy(fn($j) => $j['entry']->date?->timestamp ?? 0)->values();
@endphp

<x-tallui-page-header title="Invoice {{ $invoice->invoice_number }}" subtitle="Customer invoice details and payment history" icon="o-document-text">
    <x-slot:actions>
        <a href="{{ route('accounting.invoices') }}" class="btn btn-ghost btn-sm">Back to Invoices</a>
        @if(!in_array($status, ['void']))
            <button wire:click="openDiscountModal" class="btn btn-outline btn-sm">
                Record Discount
            </button>
            <button wire:click="openChargeModal" class="btn btn-outline btn-sm">
                Record Charge
            </button>
        @endif
        @if(in_array($status, ['issued', 'sent', 'partially_settled', 'partial', 'overdue']) && $invoice->balance > 0)
            <a href="{{ route('accounting.invoices', ['action' => 'pay', 'invoice' => $invoice->id]) }}" class="btn btn-primary btn-sm">
                Record Payment
            </a>
        @endif
    </x-slot:actions>
</x-tallui-page-header>

{{-- Invoice header + summary --}}
<div class="grid gap-4 lg:grid-cols-3">
    <x-tallui-card class="lg:col-span-2">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="text-sm text-base-content/60">Customer</div>
                <div class="text-lg font-semibold">{{ $invoice->customer?->name ?? 'Unknown customer' }}</div>
                @if($invoice->customer?->email)
                    <div class="text-sm text-base-content/60">{{ $invoice->customer->email }}</div>
                @endif
                @if($invoice->customer?->phone)
                    <div class="text-sm text-base-content/60">{{ $invoice->customer->phone }}</div>
                @endif
            </div>
            <x-tallui-badge :type="$badgeType">{{ str($status)->replace('_', ' ')->title() }}</x-tallui-badge>
        </div>

        <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div>
                <div class="text-xs uppercase text-base-content/50">Invoice Date</div>
                <div class="font-medium">{{ $invoice->invoice_date?->format('M d, Y') }}</div>
            </div>
            <div>
                <div class="text-xs uppercase text-base-content/50">Due Date</div>
                <div class="font-medium">{{ $invoice->due_date?->format('M d, Y') }}</div>
            </div>
            <div>
                <div class="text-xs uppercase text-base-content/50">Currency</div>
                <div class="font-medium">{{ $invoice->currency }}</div>
            </div>
            <div>
                <div class="text-xs uppercase text-base-content/50">Exchange Rate</div>
                <div class="font-medium">{{ number_format((float) ($invoice->exchange_rate ?? 1), 4) }}</div>
            </div>
        </div>

        @if($invoice->notes)
            <div class="mt-6 rounded-xl border border-base-200 bg-base-50 p-4">
                <div class="text-xs uppercase text-base-content/50">Notes</div>
                <div class="mt-1 whitespace-pre-line text-sm">{{ $invoice->notes }}</div>
            </div>
        @endif
    </x-tallui-card>

    <x-tallui-card>
        <div class="space-y-3">
            <div class="flex items-center justify-between">
                <span class="text-sm text-base-content/60">Subtotal</span>
                <span class="font-mono">{{ $invoice->base_currency }} {{ number_format((float) $invoice->base_subtotal, 2) }}</span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-sm text-base-content/60">Tax</span>
                <span class="font-mono">{{ $invoice->base_currency }} {{ number_format((float) $invoice->base_tax_amount, 2) }}</span>
            </div>
            @if((float) $invoice->discount_amount > 0)
                <div class="flex items-center justify-between">
                    <span class="text-sm text-base-content/60">Discount</span>
                    <span class="font-mono">-{{ $invoice->base_currency }} {{ number_format((float) $invoice->base_discount_amount, 2) }}</span>
                </div>
            @endif
            <div class="flex items-center justify-between border-t border-base-200 pt-3">
                <span class="font-medium">Total</span>
                <span class="font-mono font-semibold">{{ $invoice->base_currency }} {{ number_format((float) $invoice->base_total, 2) }}</span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-sm text-base-content/60">Paid</span>
                <span class="font-mono text-success">{{ $invoice->base_currency }} {{ number_format((float) $invoice->base_paid_amount, 2) }}</span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-sm text-base-content/60">Balance</span>
                <span class="font-mono {{ $invoice->base_balance > 0 ? 'text-warning' : 'text-success' }}">{{ $invoice->base_currency }} {{ number_format((float) $invoice->base_balance, 2) }}</span>
            </div>
        </div>
    </x-tallui-card>
</div>

{{-- Line Items + Payments --}}
<div class="mt-4 mb-4 grid gap-4 xl:grid-cols-3">
    <x-tallui-card class="xl:col-span-2" padding="none">
        <div class="border-b border-base-200 px-5 py-4">
            <h3 class="font-semibold">Line Items</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-50 text-xs uppercase text-base-content/50">
                        <th class="pl-5">Description</th>
                        <th class="text-right">Qty</th>
                        <th class="text-right">Unit Price</th>
                        <th class="text-right">Tax</th>
                        <th class="pr-5 text-right">Line Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-200">
                    @forelse($invoice->items as $item)
                        <tr>
                            <td class="pl-5 text-sm">{{ $item->description }}</td>
                            <td class="text-right font-mono text-sm">{{ number_format((float) $item->quantity, 2) }}</td>
                            <td class="text-right font-mono text-sm">{{ $invoice->base_currency }} {{ number_format($invoice->convertToBase($item->unit_price), 2) }}</td>
                            <td class="text-right font-mono text-sm">{{ $invoice->base_currency }} {{ number_format($invoice->convertToBase($item->tax_amount), 2) }}</td>
                            <td class="pr-5 text-right font-mono text-sm">{{ $invoice->base_currency }} {{ number_format($invoice->convertToBase($item->total), 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-6 text-center text-sm text-base-content/60">No line items recorded.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-tallui-card>

    <div class="flex flex-col gap-4">
        {{-- Payments --}}
        <x-tallui-card padding="none">
            <div class="border-b border-base-200 px-5 py-4">
                <h3 class="font-semibold">Payments</h3>
            </div>
            <div class="divide-y divide-base-200">
                @forelse($invoice->payments as $payment)
                    <div class="px-5 py-3">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-medium text-sm">{{ $payment->payment_date?->format('M d, Y') }}</div>
                                <div class="text-xs text-base-content/60">{{ str($payment->payment_method)->replace('_', ' ')->title() }}</div>
                                @if($payment->reference)
                                    <div class="text-xs text-base-content/50">Ref: {{ $payment->reference }}</div>
                                @endif
                            </div>
                            <div class="text-right">
                                <div class="font-mono font-semibold text-sm">{{ $invoice->base_currency }} {{ number_format($invoice->convertToBase($payment->amount), 2) }}</div>
                                @if($payment->journalEntry)
                                    <div class="font-mono text-xs text-base-content/40">{{ $payment->journalEntry->entry_number }}</div>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-6 text-sm text-base-content/60">No payments recorded yet.</div>
                @endforelse
            </div>
        </x-tallui-card>

        {{-- Charges (Delivery / COD) --}}
        @if($invoice->expenses->isNotEmpty())
        <x-tallui-card padding="none">
            <div class="border-b border-base-200 px-5 py-4">
                <div class="flex items-center justify-between">
                    <h3 class="font-semibold">Charges</h3>
                    <span class="badge badge-ghost badge-sm">{{ $invoice->expenses->count() }}</span>
                </div>
            </div>
            <div class="divide-y divide-base-200">
                @foreach($invoice->expenses as $charge)
                    @php
                        $chargeBadge = match($charge->account?->code ?? '') {
                            '6310', '6320', '6330', '6340' => 'info',
                            '6130' => 'error',
                            default => 'neutral',
                        };
                    @endphp
                    <div class="px-5 py-3">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <x-tallui-badge :type="$chargeBadge" size="sm">{{ $charge->account?->name ?? 'Charge' }}</x-tallui-badge>
                                <div class="mt-1 text-xs text-base-content/60">{{ $charge->expense_date?->format('M d, Y') }}</div>
                                @if($charge->notes)
                                    <div class="text-xs text-base-content/50">{{ $charge->notes }}</div>
                                @endif
                            </div>
                            <div class="text-right">
                                <div class="font-mono font-semibold text-sm">{{ $invoice->base_currency }} {{ number_format((float) $charge->total, 2) }}</div>
                                @if($charge->journalEntry)
                                    <div class="font-mono text-xs text-base-content/40">{{ $charge->journalEntry->entry_number }}</div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-tallui-card>
        @endif
    </div>
</div>

{{-- Unified Journal Ledger --}}
<x-tallui-card class="mt-4" padding="none">
    <div class="border-b border-base-200 px-5 py-4">
        <div class="flex items-center justify-between">
            <div>
                <h3 class="font-semibold">Journal Ledger</h3>
                <p class="text-sm text-base-content/60">All accounting entries for this invoice</p>
            </div>
            @if($allJournals->isNotEmpty())
                <span class="badge badge-ghost badge-sm">{{ $allJournals->count() }} {{ Str::plural('entry', $allJournals->count()) }}</span>
            @endif
        </div>
    </div>

    @forelse($allJournals as $journal)
        @php
            $je = $journal['entry'];
            $jeStatus = $je->status?->value ?? (string) $je->status;
        @endphp

        {{-- Entry header --}}
        <div class="border-b border-base-200 bg-base-50 px-5 py-3">
            <div class="flex flex-wrap items-center gap-3">
                <x-tallui-badge :type="$journal['badge']" size="sm">{{ $journal['type'] }}</x-tallui-badge>
                <span class="font-mono text-sm font-semibold">{{ $je->entry_number }}</span>
                <span class="text-sm text-base-content/60">{{ $je->date?->format('M d, Y') }}</span>
                <span class="text-sm text-base-content/50">·</span>
                <span class="text-sm text-base-content/70">{{ $je->description }}</span>
                <span class="ml-auto">
                    <x-tallui-badge :type="match($jeStatus) { 'posted' => 'success', 'void' => 'error', default => 'neutral' }" size="sm">
                        {{ str($jeStatus)->replace('_', ' ')->title() }}
                    </x-tallui-badge>
                </span>
            </div>
        </div>

        {{-- Entry lines --}}
        <div class="overflow-x-auto {{ !$loop->last ? 'border-b border-base-200' : '' }}">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="text-xs uppercase text-base-content/40">
                        <th class="pl-5">Account</th>
                        <th>Description</th>
                        <th class="text-right">Debit</th>
                        <th class="pr-5 text-right">Credit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-200">
                    @foreach($je->lines as $line)
                        <tr>
                            <td class="pl-5 text-sm">
                                <div class="font-medium">{{ $line->account?->name ?? 'Unknown account' }}</div>
                                <div class="text-xs text-base-content/40">{{ $line->account?->code }}</div>
                            </td>
                            <td class="text-sm text-base-content/60">{{ $line->description ?: $je->description ?: '—' }}</td>
                            <td class="text-right font-mono text-sm">
                                {{ $line->type === 'debit' ? number_format((float) $line->amount, 2) : '—' }}
                            </td>
                            <td class="pr-5 text-right font-mono text-sm">
                                {{ $line->type === 'credit' ? number_format((float) $line->amount, 2) : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="bg-base-50/50 text-xs font-semibold text-base-content/60">
                        <td colspan="2" class="pl-5 py-2">Totals</td>
                        <td class="py-2 text-right font-mono">{{ number_format((float) $je->lines->where('type', 'debit')->sum('amount'), 2) }}</td>
                        <td class="pr-5 py-2 text-right font-mono">{{ number_format((float) $je->lines->where('type', 'credit')->sum('amount'), 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @empty
        <div class="px-5 py-6 text-sm text-base-content/60">No journal entries recorded for this invoice yet.</div>
    @endforelse
</x-tallui-card>

{{-- Record Discount Modal --}}
@if($showDiscountModal)
<div class="modal modal-open">
    <div class="modal-box max-w-md">
        <h3 class="mb-4 text-lg font-bold">Record Discount</h3>

        <div class="space-y-4">
            <div>
                <label class="label"><span class="label-text font-medium">Discount Amount <span class="text-error">*</span></span></label>
                <label class="input input-bordered flex items-center gap-2">
                    <span class="text-sm text-base-content/60">{{ $invoice->base_currency }}</span>
                    <input wire:model="discount_amount" type="number" step="0.01" min="0.01" placeholder="0.00" class="grow" />
                </label>
                @error('discount_amount') <span class="mt-1 text-xs text-error">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="label"><span class="label-text font-medium">Date <span class="text-error">*</span></span></label>
                <input wire:model="discount_date" type="date" class="input input-bordered w-full" />
                @error('discount_date') <span class="mt-1 text-xs text-error">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="label"><span class="label-text font-medium">Notes</span></label>
                <textarea wire:model="discount_notes" rows="2" placeholder="Reason for discount..." class="textarea textarea-bordered w-full"></textarea>
            </div>

            <div class="rounded-lg border border-base-200 bg-base-50 px-4 py-3 text-sm">
                <div class="flex items-center justify-between">
                    <span class="text-base-content/60">Available AR balance</span>
                    <span class="font-mono font-semibold {{ $this->availableAr() > 0 ? 'text-success' : 'text-error' }}">
                        {{ $invoice->base_currency }} {{ number_format($this->availableAr(), 2) }}
                    </span>
                </div>
            </div>
            <div class="rounded-lg border border-error/30 bg-error/10 px-4 py-3 text-sm text-base-content/70">
                Journal entry posted: <span class="font-mono">DR Sales Discount (6130) / CR AR (1200)</span>
            </div>
        </div>

        <div class="modal-action">
            <button wire:click="$set('showDiscountModal', false)" class="btn btn-ghost">Cancel</button>
            <button wire:click="recordDiscount" wire:loading.attr="disabled" class="btn btn-error">
                <span wire:loading wire:target="recordDiscount" class="loading loading-spinner loading-sm"></span>
                Record Discount
            </button>
        </div>
    </div>
    <div class="modal-backdrop" wire:click="$set('showDiscountModal', false)"></div>
</div>
@endif

{{-- Record Charge Modal --}}
@if($showChargeModal)
<div class="modal modal-open">
    <div class="modal-box max-w-md">
        <h3 class="mb-4 text-lg font-bold">Record Charge</h3>

        <div class="space-y-4">
            <div>
                <label class="label"><span class="label-text font-medium">Charge Type <span class="text-error">*</span></span></label>
                <select wire:model="charge_type" class="select select-bordered w-full">
                    <option value="6310">Delivery / Courier Charge (6310)</option>
                    <option value="6320">Shipping / Carriage Charge (6320)</option>
                    <option value="6330">Hand Carry Delivery (6330)</option>
                    <option value="6340">Delivery Return Charge (6340)</option>
                </select>
                @error('charge_type') <span class="mt-1 text-xs text-error">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="label"><span class="label-text font-medium">Amount <span class="text-error">*</span></span></label>
                <label class="input input-bordered flex items-center gap-2">
                    <span class="text-sm text-base-content/60">{{ $invoice->base_currency }}</span>
                    <input wire:model="charge_amount" type="number" step="0.01" min="0.01" placeholder="0.00" class="grow" />
                </label>
                @error('charge_amount') <span class="mt-1 text-xs text-error">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="label"><span class="label-text font-medium">Date <span class="text-error">*</span></span></label>
                <input wire:model="charge_date" type="date" class="input input-bordered w-full" />
                @error('charge_date') <span class="mt-1 text-xs text-error">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="label"><span class="label-text font-medium">Notes</span></label>
                <textarea wire:model="charge_notes" rows="2" placeholder="Optional notes..." class="textarea textarea-bordered w-full"></textarea>
            </div>

            <div class="rounded-lg border border-base-200 bg-base-50 px-4 py-3 text-sm">
                <div class="flex items-center justify-between">
                    <span class="text-base-content/60">Available AR balance</span>
                    <span class="font-mono font-semibold text-success">
                        {{ $invoice->base_currency }} {{ number_format($this->availableAr(), 2) }}
                    </span>
                </div>
            </div>
            <div class="rounded-lg border border-info/30 bg-info/10 px-4 py-3 text-sm text-base-content/70">
                Journal entry posted: <span class="font-mono">DR AR (1200) / CR {{ $charge_type }}</span>
            </div>
        </div>

        <div class="modal-action">
            <button wire:click="$set('showChargeModal', false)" class="btn btn-ghost">Cancel</button>
            <button wire:click="recordCharge" wire:loading.attr="disabled" class="btn btn-primary">
                <span wire:loading wire:target="recordCharge" class="loading loading-spinner loading-sm"></span>
                Record Charge
            </button>
        </div>
    </div>
    <div class="modal-backdrop" wire:click="$set('showChargeModal', false)"></div>
</div>
@endif

</div>
