<div class="space-y-6">
<x-tallui-notification />

<x-tallui-page-header
    title="Ledger"
    subtitle="Review account activity across general, customer, and vendor ledgers"
    icon="o-book-open"
/>

{{-- Ledger tab navigation --}}
<div role="tablist" class="tabs tabs-boxed w-fit">
    <a href="{{ route('accounting.ledger') }}" role="tab" class="tab">General Ledger</a>
    <a href="{{ route('accounting.ledger.customers') }}" role="tab" class="tab tab-active">Customer Ledger</a>
    <a href="{{ route('accounting.ledger.vendors') }}" role="tab" class="tab">Vendor Ledger</a>
</div>

{{-- Search --}}
<x-tallui-card padding="compact">
    <div class="flex items-center gap-3 p-1">
        <div class="flex-1 max-w-sm">
            <x-tallui-input
                wire:model.live.debounce.300ms="search"
                placeholder="Search by name, code, or email..."
                class="input-sm"
            />
        </div>
    </div>
</x-tallui-card>

<x-tallui-card padding="none">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="text-xs text-base-content/50 uppercase border-b border-base-200">
                    <th class="pl-5 py-3">Code</th>
                    <th>Customer</th>
                    <th>Contact</th>
                    <th class="text-right">Total Invoiced</th>
                    <th class="text-right">Total Received</th>
                    <th class="text-right">Outstanding</th>
                    <th class="pr-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse($customers as $customer)
                    @php
                        $invoiced    = (float) ($customer->invoiced_sum ?? 0);
                        $paid        = (float) ($customer->paid_sum ?? 0);
                        $outstanding = $invoiced - $paid;
                    @endphp
                    <tr class="hover:bg-base-200/40">
                        <td class="pl-5 font-mono text-xs text-base-content/60">{{ $customer->code }}</td>
                        <td>
                            <div class="font-medium text-sm">{{ $customer->name }}</div>
                            @if($customer->city)
                                <div class="text-xs text-base-content/50">
                                    {{ $customer->city }}{{ $customer->country ? ', ' . $customer->country : '' }}
                                </div>
                            @endif
                        </td>
                        <td>
                            <div class="text-sm">{{ $customer->email ?? '—' }}</div>
                            @if($customer->phone)
                                <div class="text-xs text-base-content/50">{{ $customer->phone }}</div>
                            @endif
                        </td>
                        <td class="text-right font-mono text-sm">
                            {{ $currency }} {{ number_format($invoiced, 2) }}
                        </td>
                        <td class="text-right font-mono text-sm text-success">
                            {{ $currency }} {{ number_format($paid, 2) }}
                        </td>
                        <td class="text-right font-mono text-sm font-semibold {{ $outstanding > 0 ? 'text-warning' : 'text-success' }}">
                            {{ $currency }} {{ number_format($outstanding, 2) }}
                        </td>
                        <td class="pr-5 text-right">
                            <x-tallui-button
                                :link="route('accounting.customers.ledger', $customer)"
                                label="View Ledger"
                                icon="o-book-open"
                                class="btn-ghost btn-xs"
                            />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="py-10 text-center text-sm text-base-content/50">
                            No active customers found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($customers->hasPages())
        <div class="px-5 py-4 border-t border-base-200">
            {{ $customers->links() }}
        </div>
    @endif
</x-tallui-card>
</div>
