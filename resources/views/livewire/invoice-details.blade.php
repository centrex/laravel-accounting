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
    $journalStatus = $invoice->journalEntry?->status?->value ?? (string) $invoice->journalEntry?->status;
@endphp

<x-tallui-page-header title="Invoice {{ $invoice->invoice_number }}" subtitle="Customer invoice details and payment history" icon="o-document-text">
    <x-slot:actions>
        <a href="{{ route('accounting.invoices') }}" class="btn btn-ghost btn-sm">Back to Invoices</a>
        @if(in_array($status, ['issued', 'sent', 'partially_settled', 'partial', 'overdue']) && $invoice->balance > 0)
            <a href="{{ route('accounting.invoices', ['action' => 'pay', 'invoice' => $invoice->id]) }}" class="btn btn-primary btn-sm">
                Record Payment
            </a>
        @endif
    </x-slot:actions>
</x-tallui-page-header>

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
                <div class="text-xs uppercase text-base-content/50">Journal Entry</div>
                <div class="font-medium">{{ $invoice->journalEntry?->entry_number ?? 'Not posted' }}</div>
            </div>
            <div>
                <div class="text-xs uppercase text-base-content/50">Document Currency</div>
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

<div class="mt-4 grid gap-4 xl:grid-cols-3">
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

    <x-tallui-card padding="none">
        <div class="border-b border-base-200 px-5 py-4">
            <h3 class="font-semibold">Payments</h3>
        </div>
        <div class="divide-y divide-base-200">
            @forelse($invoice->payments as $payment)
                <div class="px-5 py-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="font-medium">{{ $payment->payment_date?->format('M d, Y') }}</div>
                            <div class="text-sm text-base-content/60">{{ str($payment->payment_method)->replace('_', ' ')->title() }}</div>
                            @if($payment->reference)
                                <div class="text-xs text-base-content/50">Ref: {{ $payment->reference }}</div>
                            @endif
                        </div>
                        <div class="text-right">
                            <div class="font-mono font-semibold">{{ $invoice->base_currency }} {{ number_format($invoice->convertToBase($payment->amount), 2) }}</div>
                            <div class="text-xs text-base-content/50">{{ $payment->journalEntry?->entry_number ?? 'Manual payment' }}</div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="px-5 py-6 text-sm text-base-content/60">No payments recorded yet.</div>
            @endforelse
        </div>
    </x-tallui-card>
</div>

<x-tallui-card class="mt-4" padding="none">
    <div class="border-b border-base-200 px-5 py-4">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h3 class="font-semibold">Journal Entry</h3>
                <p class="text-sm text-base-content/60">Posting impact for this invoice</p>
            </div>
            @if($invoice->journalEntry)
                <x-tallui-badge :type="match($journalStatus) {
                    'posted' => 'success',
                    'void' => 'error',
                    default => 'neutral',
                }">
                    {{ str($journalStatus)->replace('_', ' ')->title() }}
                </x-tallui-badge>
            @endif
        </div>
    </div>

    @if($invoice->journalEntry)
        <div class="grid gap-4 border-b border-base-200 px-5 py-4 md:grid-cols-2 xl:grid-cols-5">
            <div>
                <div class="text-xs uppercase text-base-content/50">Entry #</div>
                <div class="font-medium">{{ $invoice->journalEntry->entry_number }}</div>
            </div>
            <div>
                <div class="text-xs uppercase text-base-content/50">Date</div>
                <div class="font-medium">{{ $invoice->journalEntry->date?->format('M d, Y') }}</div>
            </div>
            <div>
                <div class="text-xs uppercase text-base-content/50">Type</div>
                <div class="font-medium">{{ str($invoice->journalEntry->type)->replace('_', ' ')->title() }}</div>
            </div>
            <div>
                <div class="text-xs uppercase text-base-content/50">Reference</div>
                <div class="font-medium">{{ $invoice->journalEntry->reference ?: 'N/A' }}</div>
            </div>
            <div>
                <div class="text-xs uppercase text-base-content/50">Description</div>
                <div class="font-medium">{{ $invoice->journalEntry->description ?: 'N/A' }}</div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-50 text-xs uppercase text-base-content/50">
                        <th class="pl-5">Account</th>
                        <th>Line Description</th>
                        <th class="text-right">Debit</th>
                        <th class="pr-5 text-right">Credit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-200">
                    @foreach($invoice->journalEntry->lines as $line)
                        <tr>
                            <td class="pl-5 text-sm">
                                <div class="font-medium">{{ $line->account?->name ?? 'Unknown account' }}</div>
                                <div class="text-xs text-base-content/50">{{ $line->account?->code }}</div>
                            </td>
                            <td class="text-sm text-base-content/70">{{ $line->description ?: $invoice->journalEntry->description ?: 'Posted from invoice' }}</td>
                            <td class="text-right font-mono text-sm">
                                {{ $line->type === 'debit' ? number_format((float) $line->amount, 2) : '0.00' }}
                            </td>
                            <td class="pr-5 text-right font-mono text-sm">
                                {{ $line->type === 'credit' ? number_format((float) $line->amount, 2) : '0.00' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="bg-base-50 font-semibold">
                        <td colspan="2" class="pl-5">Totals</td>
                        <td class="text-right font-mono">
                            {{ number_format((float) $invoice->journalEntry->lines->where('type', 'debit')->sum('amount'), 2) }}
                        </td>
                        <td class="pr-5 text-right font-mono">
                            {{ number_format((float) $invoice->journalEntry->lines->where('type', 'credit')->sum('amount'), 2) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @else
        <div class="px-5 py-6 text-sm text-base-content/60">This invoice has not been posted yet, so no journal entry is available.</div>
    @endif
</x-tallui-card>
</div>
