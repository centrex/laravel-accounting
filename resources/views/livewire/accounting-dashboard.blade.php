<div class="space-y-6">
<x-tallui-notification />

{{-- ── Page Header ──────────────────────────────────────────────────────── --}}
<x-tallui-page-header
    title="Accounting Dashboard"
    subtitle="Financial overview · {{ \Carbon\Carbon::parse($startDate)->format('M d') }} – {{ \Carbon\Carbon::parse($endDate)->format('M d, Y') }}"
    icon="o-chart-bar-square"
>
    <x-slot:actions>
        <x-tallui-select wire:model.live="dateRange" class="select-sm w-36">
            <option value="today">Today</option>
            <option value="this_week">This Week</option>
            <option value="this_month">This Month</option>
            <option value="this_quarter">This Quarter</option>
            <option value="this_year">This Year</option>
        </x-tallui-select>
        <x-tallui-button
            label="New Invoice"
            icon="o-plus"
            :link="route('accounting.invoices')"
            class="btn-primary btn-sm"
        />
        <x-tallui-button
            label="New Entry"
            icon="o-pencil-square"
            :link="route('accounting.journal')"
            class="btn-ghost btn-sm"
        />
    </x-slot:actions>
</x-tallui-page-header>

{{-- ── Primary KPI Stats ────────────────────────────────────────────────── --}}
<div class="stats stats-vertical lg:stats-horizontal shadow-sm w-full bg-base-100 border border-base-200 rounded-2xl overflow-hidden">
    <x-tallui-stat
        title="Revenue"
        :value="$currency . ' ' . number_format($metrics['revenue'], 2)"
        icon="o-arrow-trending-up"
        icon-color="text-success"
        :desc="'Period: ' . \Carbon\Carbon::parse($startDate)->format('M d') . ' – ' . \Carbon\Carbon::parse($endDate)->format('M d')"
    />
    <x-tallui-stat
        title="Expenses"
        :value="$currency . ' ' . number_format($metrics['expenses'], 2)"
        icon="o-arrow-trending-down"
        icon-color="text-error"
        desc="Total costs for period"
    />
    <x-tallui-stat
        :title="$metrics['net_income'] >= 0 ? 'Net Profit' : 'Net Loss'"
        :value="$currency . ' ' . number_format(abs($metrics['net_income']), 2)"
        :icon="$metrics['net_income'] >= 0 ? 'o-face-smile' : 'o-face-frown'"
        :icon-color="$metrics['net_income'] >= 0 ? 'text-primary' : 'text-error'"
        :desc="$metrics['net_income'] >= 0 ? 'Profitable period' : 'Loss period'"
    />
    <x-tallui-stat
        title="Total Assets"
        :value="$currency . ' ' . number_format($metrics['total_assets'], 2)"
        icon="o-building-library"
        icon-color="text-info"
        desc="Current asset base"
    />
    <x-tallui-stat
        title="Liabilities"
        :value="$currency . ' ' . number_format($metrics['total_liabilities'], 2)"
        icon="o-credit-card"
        icon-color="text-warning"
        desc="Total obligations"
    />
    <x-tallui-stat
        title="Equity"
        :value="$currency . ' ' . number_format($metrics['total_equity'], 2)"
        icon="o-scale"
        icon-color="text-secondary"
        desc="Owner's equity"
    />
</div>

{{-- ── AR / AP / Customer / Vendor cards ───────────────────────────────── --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4">

    {{-- Outstanding AR --}}
    <a href="{{ route('accounting.invoices') }}" class="group">
        <div class="card bg-base-100 border border-base-200 shadow-sm hover:shadow-md hover:border-success/40 transition-all rounded-2xl">
            <div class="card-body p-4 gap-1">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-base-content/50 uppercase tracking-wide">Receivables</span>
                    <div class="w-8 h-8 rounded-lg bg-success/10 flex items-center justify-center">
                        <x-tallui-icon name="o-inbox-arrow-down" class="w-4 h-4 text-success" />
                    </div>
                </div>
                <div class="text-xl font-bold mt-1">{{ $currency }} {{ number_format($invoiceStats['outstanding_ar'], 2) }}</div>
                <div class="flex items-center gap-2 flex-wrap mt-1">
                    @if($invoiceStats['overdue_count'] > 0)
                        <x-tallui-badge type="error" size="sm">{{ $invoiceStats['overdue_count'] }} overdue</x-tallui-badge>
                    @endif
                    @if($invoiceStats['sent_count'] > 0)
                        <x-tallui-badge type="info" size="sm">{{ $invoiceStats['sent_count'] }} sent</x-tallui-badge>
                    @endif
                    @if($invoiceStats['partial_count'] > 0)
                        <x-tallui-badge type="warning" size="sm">{{ $invoiceStats['partial_count'] }} partial</x-tallui-badge>
                    @endif
                </div>
            </div>
        </div>
    </a>

    {{-- Outstanding AP --}}
    <a href="{{ route('accounting.bills') }}" class="group">
        <div class="card bg-base-100 border border-base-200 shadow-sm hover:shadow-md hover:border-warning/40 transition-all rounded-2xl">
            <div class="card-body p-4 gap-1">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-base-content/50 uppercase tracking-wide">Payables</span>
                    <div class="w-8 h-8 rounded-lg bg-warning/10 flex items-center justify-center">
                        <x-tallui-icon name="o-archive-box-arrow-down" class="w-4 h-4 text-warning" />
                    </div>
                </div>
                <div class="text-xl font-bold mt-1">{{ $currency }} {{ number_format($billStats['outstanding_ap'], 2) }}</div>
                <div class="flex items-center gap-2 flex-wrap mt-1">
                    @if($billStats['overdue_count'] > 0)
                        <x-tallui-badge type="error" size="sm">{{ $billStats['overdue_count'] }} overdue</x-tallui-badge>
                    @endif
                    @if($billStats['sent_count'] > 0)
                        <x-tallui-badge type="info" size="sm">{{ $billStats['sent_count'] }} sent</x-tallui-badge>
                    @endif
                    @if($billStats['partial_count'] > 0)
                        <x-tallui-badge type="warning" size="sm">{{ $billStats['partial_count'] }} partial</x-tallui-badge>
                    @endif
                </div>
            </div>
        </div>
    </a>

    {{-- Customers --}}
    <a href="{{ route('accounting.customers') }}" class="group">
        <div class="card bg-base-100 border border-base-200 shadow-sm hover:shadow-md hover:border-primary/40 transition-all rounded-2xl">
            <div class="card-body p-4 gap-1">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-base-content/50 uppercase tracking-wide">Customers</span>
                    <div class="w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center">
                        <x-tallui-icon name="o-users" class="w-4 h-4 text-primary" />
                    </div>
                </div>
                <div class="text-xl font-bold mt-1">{{ number_format($customerCount) }}</div>
                <div class="text-xs text-base-content/50 mt-1">Active customers</div>
            </div>
        </div>
    </a>

    {{-- Vendors --}}
    <a href="{{ route('accounting.vendors') }}" class="group">
        <div class="card bg-base-100 border border-base-200 shadow-sm hover:shadow-md hover:border-secondary/40 transition-all rounded-2xl">
            <div class="card-body p-4 gap-1">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-base-content/50 uppercase tracking-wide">Vendors</span>
                    <div class="w-8 h-8 rounded-lg bg-secondary/10 flex items-center justify-center">
                        <x-tallui-icon name="o-building-storefront" class="w-4 h-4 text-secondary" />
                    </div>
                </div>
                <div class="text-xl font-bold mt-1">{{ number_format($vendorCount) }}</div>
                <div class="text-xs text-base-content/50 mt-1">Active vendors</div>
            </div>
        </div>
    </a>
</div>

{{-- ── Charts ──────────────────────────────────────────────────────────── --}}
<div class="grid grid-cols-1 xl:grid-cols-3 gap-4">

    {{-- Revenue vs Expenses — bar chart (2/3 width) --}}
    <x-tallui-card
        title="Revenue vs Expenses"
        subtitle="{{ now()->year }} monthly breakdown"
        icon="o-chart-bar"
        class="xl:col-span-2"
    >
        <x-slot:actions>
            <x-tallui-button
                label="P&L Report"
                icon="o-arrow-top-right-on-square"
                :link="route('accounting.reports')"
                class="btn-ghost btn-xs"
            />
        </x-slot:actions>

        @if(count($chartData['categories']) > 0)
            <div
                wire:ignore
                x-data="{
                    chart: null,
                    init() {
                        this.chart = new ApexCharts(this.$el.querySelector('[data-chart]'), {
                            chart:  { type: 'bar', height: 260, toolbar: { show: false }, fontFamily: 'inherit', background: 'transparent' },
                            series: [
                                { name: 'Revenue',  data: {{ json_encode($chartData['revenue']) }} },
                                { name: 'Expenses', data: {{ json_encode($chartData['expenses']) }} }
                            ],
                            xaxis:  { categories: {{ json_encode($chartData['categories']) }}, axisBorder: { show: false }, axisTicks: { show: false } },
                            yaxis:  { labels: { formatter: v => '{{ $currency }} ' + (v >= 1000 ? (v/1000).toFixed(1)+'k' : v.toFixed(0)) } },
                            colors: ['#36d399', '#f87272'],
                            plotOptions: { bar: { borderRadius: 4, columnWidth: '60%' } },
                            dataLabels: { enabled: false },
                            legend: { position: 'top', horizontalAlign: 'right' },
                            grid: { borderColor: 'oklch(var(--b3))', strokeDashArray: 4 },
                            tooltip: { y: { formatter: v => '{{ $currency }} ' + v.toLocaleString(undefined, {minimumFractionDigits: 2}) } },
                            theme: { mode: document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light' }
                        });
                        this.chart.render();
                    }
                }"
            >
                <div data-chart class="min-h-[260px]"></div>
            </div>
        @else
            <x-tallui-empty-state
                title="No data yet"
                description="Post journal entries to see your monthly breakdown."
                icon="o-chart-bar"
                size="sm"
            />
        @endif
    </x-tallui-card>

    {{-- Financial snapshot — donut (1/3 width) --}}
    <x-tallui-card title="Balance Snapshot" icon="o-chart-pie">
        <x-slot:actions>
            <x-tallui-button
                label="Balance Sheet"
                icon="o-arrow-top-right-on-square"
                :link="route('accounting.reports')"
                class="btn-ghost btn-xs"
            />
        </x-slot:actions>

        @if($metrics['total_assets'] > 0)
            <div
                wire:ignore
                x-data="{
                    chart: null,
                    init() {
                        this.chart = new ApexCharts(this.$el.querySelector('[data-chart]'), {
                            chart:  { type: 'donut', height: 230, fontFamily: 'inherit', background: 'transparent' },
                            series: [
                                {{ max(0, $metrics['total_assets']) }},
                                {{ max(0, $metrics['total_liabilities']) }},
                                {{ max(0, $metrics['total_equity']) }}
                            ],
                            labels: ['Assets', 'Liabilities', 'Equity'],
                            colors: ['#38bdf8', '#fb923c', '#a78bfa'],
                            dataLabels: { enabled: false },
                            legend: { position: 'bottom' },
                            plotOptions: { pie: { donut: { size: '65%' } } },
                            tooltip: { y: { formatter: v => '{{ $currency }} ' + v.toLocaleString(undefined, {minimumFractionDigits: 2}) } },
                            theme: { mode: document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light' }
                        });
                        this.chart.render();
                    }
                }"
            >
                <div data-chart class="min-h-[230px]"></div>
            </div>
            <div class="divide-y divide-base-200 mt-2">
                <div class="flex justify-between py-2 text-sm">
                    <span class="text-base-content/60 flex items-center gap-1.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-info inline-block"></span>Assets
                    </span>
                    <span class="font-semibold">{{ $currency }} {{ number_format($metrics['total_assets'], 2) }}</span>
                </div>
                <div class="flex justify-between py-2 text-sm">
                    <span class="text-base-content/60 flex items-center gap-1.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-warning inline-block"></span>Liabilities
                    </span>
                    <span class="font-semibold">{{ $currency }} {{ number_format($metrics['total_liabilities'], 2) }}</span>
                </div>
                <div class="flex justify-between py-2 text-sm">
                    <span class="text-base-content/60 flex items-center gap-1.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-secondary inline-block"></span>Equity
                    </span>
                    <span class="font-semibold">{{ $currency }} {{ number_format($metrics['total_equity'], 2) }}</span>
                </div>
            </div>
        @else
            <x-tallui-empty-state title="No balance data" icon="o-chart-pie" size="sm" />
        @endif
    </x-tallui-card>
</div>

{{-- ── Quick Actions ────────────────────────────────────────────────────── --}}
<div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3">
    @foreach([
        ['label' => 'Journal',    'sub' => 'New entry',     'icon' => 'o-pencil-square',      'color' => 'primary',   'route' => 'accounting.journal'],
        ['label' => 'Invoices',   'sub' => 'Manage AR',     'icon' => 'o-document-text',       'color' => 'success',   'route' => 'accounting.invoices'],
        ['label' => 'Bills',      'sub' => 'Manage AP',     'icon' => 'o-shopping-cart',       'color' => 'warning',   'route' => 'accounting.bills'],
        ['label' => 'Expenses',   'sub' => 'Track costs',   'icon' => 'o-banknotes',           'color' => 'error',     'route' => 'accounting.expenses'],
        ['label' => 'Customers',  'sub' => 'Manage',        'icon' => 'o-users',               'color' => 'info',      'route' => 'accounting.customers'],
        ['label' => 'Vendors',    'sub' => 'Manage',        'icon' => 'o-building-storefront', 'color' => 'secondary', 'route' => 'accounting.vendors'],
        ['label' => 'Accounts',   'sub' => 'Chart',         'icon' => 'o-list-bullet',         'color' => 'accent',    'route' => 'accounting.accounts'],
        ['label' => 'Reports',    'sub' => 'Financial',     'icon' => 'o-chart-pie',           'color' => 'neutral',   'route' => 'accounting.reports'],
    ] as $action)
        <a href="{{ route($action['route']) }}" class="group">
            <div class="card bg-base-100 border border-base-200 shadow-sm hover:shadow-md hover:border-{{ $action['color'] }}/30 transition-all rounded-2xl">
                <div class="card-body items-center text-center p-3 gap-2">
                    <div class="w-10 h-10 rounded-xl bg-{{ $action['color'] }}/10 flex items-center justify-center group-hover:bg-{{ $action['color'] }}/20 transition-colors">
                        <x-tallui-icon :name="$action['icon']" class="w-5 h-5 text-{{ $action['color'] }}" />
                    </div>
                    <div>
                        <div class="font-semibold text-xs leading-tight">{{ $action['label'] }}</div>
                        <div class="text-xs text-base-content/40 leading-tight">{{ $action['sub'] }}</div>
                    </div>
                </div>
            </div>
        </a>
    @endforeach
</div>

{{-- ── Recent Invoices & Bills ─────────────────────────────────────────── --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

    {{-- Recent Invoices --}}
    <x-tallui-card title="Recent Invoices" icon="o-document-text">
        <x-slot:actions>
            <x-tallui-button label="View All" icon="o-arrow-right" :link="route('accounting.invoices')" class="btn-ghost btn-xs" />
        </x-slot:actions>

        @if($recentInvoices->isEmpty())
            <x-tallui-empty-state title="No invoices yet" icon="o-document-text" size="sm" />
        @else
            <div class="overflow-x-auto -mx-4 px-4">
                <table class="table table-sm w-full">
                    <thead>
                        <tr class="text-xs text-base-content/40 uppercase">
                            <th>Invoice</th>
                            <th>Customer</th>
                            <th class="text-right">Total</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentInvoices as $invoice)
                            <tr class="hover:bg-base-200/50">
                                <td class="font-mono text-xs text-primary font-medium">{{ $invoice->invoice_number }}</td>
                                <td class="text-sm max-w-[120px] truncate">{{ $invoice->customer?->name ?? '—' }}</td>
                                <td class="text-right text-sm font-medium whitespace-nowrap">
                                    {{ $invoice->currency }} {{ number_format($invoice->total, 2) }}
                                </td>
                                <td>
                                    <x-tallui-badge size="sm" :type="match($invoice->status->value ?? '') {
                                        'settled'            => 'success',
                                        'partially_settled'  => 'warning',
                                        'overdue'            => 'error',
                                        'sent', 'issued'     => 'info',
                                        default              => 'neutral',
                                    }">
                                        {{ ucfirst(str_replace('_', ' ', $invoice->status->value ?? $invoice->status)) }}
                                    </x-tallui-badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <x-slot:footer>
            @if($invoiceStats['overdue_count'] > 0)
                <div class="flex items-center gap-2 text-xs text-error">
                    <x-tallui-icon name="o-exclamation-triangle" class="w-3.5 h-3.5" />
                    {{ $invoiceStats['overdue_count'] }} invoice(s) overdue totalling {{ $currency }} {{ number_format($invoiceStats['overdue_total'], 2) }}
                </div>
            @else
                <div class="text-xs text-base-content/40">No overdue invoices</div>
            @endif
        </x-slot:footer>
    </x-tallui-card>

    {{-- Recent Bills --}}
    <x-tallui-card title="Recent Bills" icon="o-shopping-cart">
        <x-slot:actions>
            <x-tallui-button label="View All" icon="o-arrow-right" :link="route('accounting.bills')" class="btn-ghost btn-xs" />
        </x-slot:actions>

        @if($recentBills->isEmpty())
            <x-tallui-empty-state title="No bills yet" icon="o-shopping-cart" size="sm" />
        @else
            <div class="overflow-x-auto -mx-4 px-4">
                <table class="table table-sm w-full">
                    <thead>
                        <tr class="text-xs text-base-content/40 uppercase">
                            <th>Bill</th>
                            <th>Vendor</th>
                            <th class="text-right">Total</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentBills as $bill)
                            <tr class="hover:bg-base-200/50">
                                <td class="font-mono text-xs text-primary font-medium">{{ $bill->bill_number }}</td>
                                <td class="text-sm max-w-[120px] truncate">{{ $bill->vendor?->name ?? '—' }}</td>
                                <td class="text-right text-sm font-medium whitespace-nowrap">
                                    {{ $bill->currency }} {{ number_format($bill->total, 2) }}
                                </td>
                                <td>
                                    <x-tallui-badge size="sm" :type="match($bill->status->value ?? '') {
                                        'settled'            => 'success',
                                        'partially_settled'  => 'warning',
                                        'overdue'            => 'error',
                                        'sent', 'issued'     => 'info',
                                        default              => 'neutral',
                                    }">
                                        {{ ucfirst(str_replace('_', ' ', $bill->status->value ?? $bill->status)) }}
                                    </x-tallui-badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <x-slot:footer>
            @if($billStats['overdue_count'] > 0)
                <div class="flex items-center gap-2 text-xs text-error">
                    <x-tallui-icon name="o-exclamation-triangle" class="w-3.5 h-3.5" />
                    {{ $billStats['overdue_count'] }} bill(s) overdue totalling {{ $currency }} {{ number_format($billStats['overdue_total'], 2) }}
                </div>
            @else
                <div class="text-xs text-base-content/40">No overdue bills</div>
            @endif
        </x-slot:footer>
    </x-tallui-card>
</div>

{{-- ── Recent Journal Entries ───────────────────────────────────────────── --}}
<x-tallui-card title="Recent Journal Entries" icon="o-clock">
    <x-slot:actions>
        <x-tallui-button label="View All" icon="o-arrow-right" :link="route('accounting.journal')" class="btn-ghost btn-xs" />
        <x-tallui-button label="New Entry" icon="o-plus" :link="route('accounting.journal')" class="btn-primary btn-xs" />
    </x-slot:actions>

    @if($recentEntries->isEmpty())
        <x-tallui-empty-state
            title="No journal entries yet"
            description="Create your first journal entry to get started."
            icon="o-document-text"
            size="sm"
        />
    @else
        <div class="overflow-x-auto -mx-4 px-4">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="text-xs text-base-content/40 uppercase">
                        <th>Entry #</th>
                        <th>Date</th>
                        <th>Description</th>
                        <th>Type</th>
                        <th class="text-right">Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentEntries as $entry)
                        <tr class="hover:bg-base-200/50">
                            <td class="font-mono text-xs text-primary font-medium whitespace-nowrap">{{ $entry->entry_number }}</td>
                            <td class="text-xs text-base-content/60 whitespace-nowrap">{{ $entry->date->format('M d, Y') }}</td>
                            <td class="text-sm max-w-[220px] truncate">{{ $entry->description ?? '—' }}</td>
                            <td class="text-xs capitalize text-base-content/60">{{ $entry->type ?? 'general' }}</td>
                            <td class="text-right text-sm font-medium whitespace-nowrap">
                                {{ number_format($entry->lines->where('type', 'debit')->sum('amount'), 2) }}
                            </td>
                            <td>
                                <x-tallui-badge size="sm" :type="match($entry->status->value ?? $entry->status) {
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

</div>
