<div>
<x-tallui-notification />

{{-- Screen header --}}
<div class="print:hidden">
<x-tallui-page-header
    title="Customer Ledger"
    :subtitle="$customer->name . ' · ' . $customer->code"
    icon="o-book-open"
>
    <x-slot:actions>
        <button onclick="window.print()" class="btn btn-outline btn-sm">Print / Export</button>
    </x-slot:actions>
</x-tallui-page-header>

{{-- Ledger tab navigation --}}
<div role="tablist" class="tabs tabs-boxed w-fit mb-4">
    <a href="{{ route('accounting.ledger') }}" role="tab" class="tab">General Ledger</a>
    <a href="{{ route('accounting.ledger.customers') }}" role="tab" class="tab tab-active">Customer Ledger</a>
    <a href="{{ route('accounting.ledger.vendors') }}" role="tab" class="tab">Vendor Ledger</a>
</div>

{{-- Date filters --}}
<x-tallui-card class="mb-4" padding="compact">
    <div class="flex flex-wrap items-end gap-3 p-1">
        <x-tallui-form-group label="From">
            <x-tallui-input type="date" wire:model.live="startDate" class="input-sm" />
        </x-tallui-form-group>
        <x-tallui-form-group label="To">
            <x-tallui-input type="date" wire:model.live="endDate" class="input-sm" />
        </x-tallui-form-group>
    </div>
</x-tallui-card>
</div>

{{-- Print-only header --}}
<div class="hidden print:block mb-6 border-b pb-4">
    <h1 class="text-xl font-bold">Customer Ledger — Statement of Account</h1>
    <p class="text-base font-medium mt-1">{{ $customer->name }} ({{ $customer->code }})</p>
    <p class="text-sm text-gray-500 mt-1">Period: {{ $startDate }} to {{ $endDate }}</p>
    <p class="text-sm text-gray-500">Generated: {{ now()->format('d M Y, h:i A') }}</p>
</div>

{{-- Customer info summary --}}
<x-tallui-card class="mb-4">
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div>
            <div class="text-xs uppercase text-base-content/50">Email</div>
            <div class="text-sm font-medium">{{ $customer->email ?? '—' }}</div>
        </div>
        <div>
            <div class="text-xs uppercase text-base-content/50">Phone</div>
            <div class="text-sm font-medium">{{ $customer->phone ?? '—' }}</div>
        </div>
        <div>
            <div class="text-xs uppercase text-base-content/50">Credit Limit</div>
            <div class="text-sm font-mono font-medium">{{ $customer->currency }} {{ number_format((float) $customer->credit_limit, 2) }}</div>
        </div>
        <div>
            <div class="text-xs uppercase text-base-content/50">Payment Terms</div>
            <div class="text-sm font-medium">{{ $customer->payment_terms }} days</div>
        </div>
    </div>
</x-tallui-card>

{{-- Ledger table --}}
<x-tallui-card padding="none">
    <div class="border-b border-base-200 px-5 py-4 flex items-center justify-between">
        <div>
            <h3 class="font-semibold">Statement of Account</h3>
            <p class="text-sm text-base-content/60">{{ $startDate }} — {{ $endDate }}</p>
        </div>
        <div class="text-right">
            <div class="text-xs uppercase text-base-content/50">Closing Balance</div>
            <div class="text-lg font-mono font-bold {{ $ledger['closing'] > 0 ? 'text-warning' : ($ledger['closing'] < 0 ? 'text-error' : 'text-success') }}">
                {{ $customer->currency }} {{ number_format($ledger['closing'], 2) }}
            </div>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-50 text-xs uppercase text-base-content/50">
                    <th class="pl-5 w-28">Date</th>
                    <th class="w-48">Reference</th>
                    <th>Description</th>
                    <th class="text-right w-32">Invoiced</th>
                    <th class="text-right w-32">Received</th>
                    <th class="pr-5 text-right w-36">Balance</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">

                {{-- Opening balance --}}
                @if($startDate !== '')
                <tr class="bg-base-100 text-sm font-medium">
                    <td class="pl-5 text-base-content/50">—</td>
                    <td></td>
                    <td class="italic text-base-content/60">Opening Balance</td>
                    <td class="text-right font-mono text-base-content/40">—</td>
                    <td class="text-right font-mono text-base-content/40">—</td>
                    <td class="pr-5 text-right font-mono font-semibold">{{ number_format($ledger['opening'], 2) }}</td>
                </tr>
                @endif

                @forelse($ledger['entries'] as $entry)
                    <tr class="hover:bg-base-50 text-sm">
                        <td class="pl-5 text-base-content/70 whitespace-nowrap">
                            {{ $entry['date']->format('d M Y') }}
                        </td>
                        <td class="font-mono text-xs">
                            @if($entry['link'])
                                <a href="{{ $entry['link'] }}" class="link link-primary print:no-underline print:text-black">
                                    {{ $entry['reference'] }}
                                </a>
                            @else
                                {{ $entry['reference'] }}
                            @endif
                        </td>
                        <td class="text-base-content/70">
                            {{ $entry['description'] }}
                            @if($entry['status'])
                                @php
                                    $badge = match($entry['status']) {
                                        'settled'                       => 'success',
                                        'issued', 'sent'                => 'info',
                                        'partially_settled', 'partial'  => 'warning',
                                        'overdue', 'void'               => 'error',
                                        default                         => 'neutral',
                                    };
                                @endphp
                                <x-tallui-badge :type="$badge" size="sm" class="ml-1 print:hidden">
                                    {{ str($entry['status'])->replace('_', ' ')->title() }}
                                </x-tallui-badge>
                            @endif
                        </td>
                        <td class="text-right font-mono {{ $entry['debit'] > 0 ? '' : 'text-base-content/30' }}">
                            {{ $entry['debit'] > 0 ? number_format($entry['debit'], 2) : '—' }}
                        </td>
                        <td class="text-right font-mono {{ $entry['credit'] > 0 ? 'text-success' : 'text-base-content/30' }}">
                            {{ $entry['credit'] > 0 ? number_format($entry['credit'], 2) : '—' }}
                        </td>
                        <td class="pr-5 text-right font-mono font-semibold {{ $entry['balance'] > 0 ? 'text-warning' : ($entry['balance'] < 0 ? 'text-error' : 'text-success') }}">
                            {{ number_format($entry['balance'], 2) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-10 text-center text-sm text-base-content/50">
                            No transactions found in this period.
                        </td>
                    </tr>
                @endforelse

                {{-- Period totals --}}
                @if(count($ledger['entries']) > 0)
                <tr class="bg-base-100 text-sm font-semibold border-t-2 border-base-300">
                    <td class="pl-5" colspan="3">Period Totals</td>
                    <td class="text-right font-mono">{{ number_format($ledger['total_debit'], 2) }}</td>
                    <td class="text-right font-mono text-success">{{ number_format($ledger['total_credit'], 2) }}</td>
                    <td class="pr-5 text-right font-mono {{ $ledger['closing'] > 0 ? 'text-warning' : ($ledger['closing'] < 0 ? 'text-error' : 'text-success') }}">
                        {{ number_format($ledger['closing'], 2) }}
                    </td>
                </tr>
                @endif

            </tbody>
        </table>
    </div>
</x-tallui-card>
</div>
