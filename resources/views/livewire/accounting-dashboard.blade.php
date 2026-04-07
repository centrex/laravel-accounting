<x-layouts.app :title="__('Accounting Dashboard')">
<x-tallui-notification />

<x-tallui-page-header
    title="Accounting Dashboard"
    subtitle="Financial overview for your business"
    icon="heroicon-o-chart-bar-square"
>
    <x-slot:actions>
        <x-tallui-select wire:model.live="dateRange" class="select-sm">
            <option value="today">Today</option>
            <option value="this_week">This Week</option>
            <option value="this_month">This Month</option>
            <option value="this_quarter">This Quarter</option>
            <option value="this_year">This Year</option>
        </x-tallui-select>
        <span class="text-sm text-base-content/50 hidden md:block">
            {{ \Carbon\Carbon::parse($startDate)->format('M d') }} – {{ \Carbon\Carbon::parse($endDate)->format('M d, Y') }}
        </span>
    </x-slot:actions>
</x-tallui-page-header>

{{-- Key Metrics --}}
<div class="stats stats-vertical lg:stats-horizontal shadow-sm w-full mb-6 bg-base-100 border border-base-200 rounded-2xl overflow-hidden">
    <x-tallui-stat
        title="Total Revenue"
        :value="config('accounting.base_currency', 'BDT') . ' ' . number_format($metrics['revenue'], 2)"
        icon="heroicon-o-arrow-trending-up"
        icon-color="text-success"
        desc="Income for period"
    />
    <x-tallui-stat
        title="Total Expenses"
        :value="config('accounting.base_currency', 'BDT') . ' ' . number_format($metrics['expenses'], 2)"
        icon="heroicon-o-arrow-trending-down"
        icon-color="text-error"
        desc="Expenses for period"
    />
    <x-tallui-stat
        :title="$metrics['net_income'] >= 0 ? 'Net Profit' : 'Net Loss'"
        :value="config('accounting.base_currency', 'BDT') . ' ' . number_format(abs($metrics['net_income']), 2)"
        :icon="$metrics['net_income'] >= 0 ? 'heroicon-o-face-smile' : 'heroicon-o-face-frown'"
        :icon-color="$metrics['net_income'] >= 0 ? 'text-primary' : 'text-error'"
        :desc="$metrics['net_income'] >= 0 ? 'Profitable' : 'Loss-making'"
    />
    <x-tallui-stat
        title="Total Assets"
        :value="config('accounting.base_currency', 'BDT') . ' ' . number_format($metrics['total_assets'], 2)"
        icon="heroicon-o-building-library"
        icon-color="text-info"
        desc="Current assets"
    />
    <x-tallui-stat
        title="Liabilities"
        :value="config('accounting.base_currency', 'BDT') . ' ' . number_format($metrics['total_liabilities'], 2)"
        icon="heroicon-o-banknotes"
        icon-color="text-warning"
        desc="Current liabilities"
    />
    <x-tallui-stat
        title="Equity"
        :value="config('accounting.base_currency', 'BDT') . ' ' . number_format($metrics['total_equity'], 2)"
        icon="heroicon-o-scale"
        icon-color="text-secondary"
        desc="Owner's equity"
    />
</div>

{{-- Quick Actions --}}
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <x-tallui-card class="hover:shadow-md transition-all cursor-pointer group" padding="compact">
        <a href="{{ route('accounting.journal') }}" class="flex items-center gap-3 p-1">
            <div class="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center group-hover:bg-primary/20 transition-colors">
                <x-tallui-icon name="heroicon-o-pencil-square" class="w-5 h-5 text-primary" />
            </div>
            <div>
                <div class="font-semibold text-sm">Journal Entry</div>
                <div class="text-xs text-base-content/50">Record transaction</div>
            </div>
        </a>
    </x-tallui-card>

    <x-tallui-card class="hover:shadow-md transition-all cursor-pointer group" padding="compact">
        <a href="{{ route('accounting.invoices') }}" class="flex items-center gap-3 p-1">
            <div class="w-10 h-10 rounded-xl bg-success/10 flex items-center justify-center group-hover:bg-success/20 transition-colors">
                <x-tallui-icon name="heroicon-o-document-text" class="w-5 h-5 text-success" />
            </div>
            <div>
                <div class="font-semibold text-sm">Invoices</div>
                <div class="text-xs text-base-content/50">Manage invoices</div>
            </div>
        </a>
    </x-tallui-card>

    <x-tallui-card class="hover:shadow-md transition-all cursor-pointer group" padding="compact">
        <a href="{{ route('accounting.bills') }}" class="flex items-center gap-3 p-1">
            <div class="w-10 h-10 rounded-xl bg-warning/10 flex items-center justify-center group-hover:bg-warning/20 transition-colors">
                <x-tallui-icon name="heroicon-o-shopping-cart" class="w-5 h-5 text-warning" />
            </div>
            <div>
                <div class="font-semibold text-sm">Bills</div>
                <div class="text-xs text-base-content/50">Vendor bills</div>
            </div>
        </a>
    </x-tallui-card>

    <x-tallui-card class="hover:shadow-md transition-all cursor-pointer group" padding="compact">
        <a href="{{ route('accounting.reports') }}" class="flex items-center gap-3 p-1">
            <div class="w-10 h-10 rounded-xl bg-secondary/10 flex items-center justify-center group-hover:bg-secondary/20 transition-colors">
                <x-tallui-icon name="heroicon-o-chart-pie" class="w-5 h-5 text-secondary" />
            </div>
            <div>
                <div class="font-semibold text-sm">Reports</div>
                <div class="text-xs text-base-content/50">Financial reports</div>
            </div>
        </a>
    </x-tallui-card>
</div>

{{-- Recent Journal Entries --}}
<x-tallui-card title="Recent Journal Entries" icon="heroicon-o-clock">
    <x-slot:actions>
        <a href="{{ route('accounting.journal') }}" class="text-sm text-primary hover:underline">View All</a>
    </x-slot:actions>

    @if($recentEntries->isEmpty())
        <x-tallui-empty-state title="No journal entries yet" description="Create your first journal entry to get started" icon="heroicon-o-document-text" />
    @else
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="text-xs text-base-content/50 uppercase">
                        <th>Entry #</th>
                        <th>Date</th>
                        <th>Description</th>
                        <th class="text-right">Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentEntries as $entry)
                        <tr class="hover:bg-base-50">
                            <td class="font-mono text-sm text-primary font-medium">{{ $entry->entry_number }}</td>
                            <td class="text-sm text-base-content/60">{{ $entry->date->format('M d, Y') }}</td>
                            <td class="text-sm max-w-xs truncate">{{ $entry->description }}</td>
                            <td class="text-right text-sm font-medium">
                                {{ number_format($entry->lines->where('type', 'debit')->sum('amount'), 2) }}
                            </td>
                            <td>
                                <x-tallui-badge :type="match($entry->status->value ?? $entry->status) {
                                    'posted' => 'success',
                                    'void'   => 'error',
                                    default  => 'warning',
                                }">
                                    {{ ucfirst($entry->status->value ?? $entry->status) }}
                                </x-tallui-badge>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-tallui-card>
</x-layouts.app>
