<div class="space-y-6">
<x-tallui-notification />

{{-- ── Page Header ──────────────────────────────────────────────────────── --}}
<x-tallui-page-header
    title="Accounting Dashboard"
    subtitle="Financial overview · {{ \Carbon\Carbon::parse($startDate)->format('M d') }} – {{ \Carbon\Carbon::parse($endDate)->format('M d, Y') }}"
    icon="o-chart-bar-square"
    :separator="true"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[['label' => 'Accounting'], ['label' => 'Dashboard']]" />
    </x-slot:breadcrumbs>

    <x-slot:actions>
        <select wire:model.live="dateRange" class="select select-bordered select-sm w-40">
            <option value="today">Today</option>
            <option value="this_week">This Week</option>
            <option value="this_month">This Month</option>
            <option value="this_quarter">This Quarter</option>
            <option value="this_year">This Year</option>
        </select>
        <a href="{{ route('accounting.invoices') }}" wire:navigate class="btn btn-primary btn-sm gap-1">
            <x-tallui-icon name="o-plus" size="w-4 h-4" /> New Invoice
        </a>
        <a href="{{ route('accounting.journal') }}" wire:navigate class="btn btn-ghost btn-sm gap-1">
            <x-tallui-icon name="o-pencil-square" size="w-4 h-4" /> Journal
        </a>
        <a href="{{ route('accounting.ledger') }}" wire:navigate class="btn btn-ghost btn-sm gap-1">
            <x-tallui-icon name="o-book-open" size="w-4 h-4" /> Ledger
        </a>
    </x-slot:actions>
</x-tallui-page-header>

<div class="px-4 md:px-6 space-y-6">

{{-- ── Alerts ───────────────────────────────────────────────────────────── --}}
@if($openPeriod && \Carbon\Carbon::parse($openPeriod->end_date)->isPast())
    <x-tallui-alert type="warning" title="Fiscal Period Overdue for Closing" :dismissible="true">
        Period <strong>{{ $openPeriod->name }}</strong> ended
        {{ \Carbon\Carbon::parse($openPeriod->end_date)->format('M d, Y') }} and has not been closed.
        <a href="{{ route('accounting.period-close') }}" wire:navigate class="link link-warning font-semibold ml-1">Close now →</a>
    </x-tallui-alert>
@endif

@if($invoiceStats['overdue_count'] > 0 || $billStats['overdue_count'] > 0)
    <div class="flex flex-col sm:flex-row gap-3">
        @if($invoiceStats['overdue_count'] > 0)
            <x-tallui-alert type="error" title="{{ $invoiceStats['overdue_count'] }} Invoice{{ $invoiceStats['overdue_count'] > 1 ? 's' : '' }} Overdue" :dismissible="true">
                {{ $currency }} {{ number_format($invoiceStats['overdue_total'], 2) }} outstanding.
                <a href="{{ route('accounting.invoices') }}" wire:navigate class="link link-error font-semibold ml-1">Review →</a>
            </x-tallui-alert>
        @endif
        @if($billStats['overdue_count'] > 0)
            <x-tallui-alert type="warning" title="{{ $billStats['overdue_count'] }} Bill{{ $billStats['overdue_count'] > 1 ? 's' : '' }} Overdue" :dismissible="true">
                {{ $currency }} {{ number_format($billStats['overdue_total'], 2) }} due.
                <a href="{{ route('accounting.bills') }}" wire:navigate class="link link-warning font-semibold ml-1">Review →</a>
            </x-tallui-alert>
        @endif
    </div>
@endif

{{-- ── Quick Actions ─────────────────────────────────────────────────────── --}}
<div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3">
    @foreach([
        ['label' => 'Journal',    'sub' => 'New entry',     'icon' => 'o-pencil-square',       'route' => 'accounting.journal'],
        ['label' => 'Ledger',     'sub' => 'View balances', 'icon' => 'o-book-open',            'route' => 'accounting.ledger'],
        ['label' => 'Invoices',   'sub' => 'Manage AR',     'icon' => 'o-document-text',        'route' => 'accounting.invoices'],
        ['label' => 'Bills',      'sub' => 'Manage AP',     'icon' => 'o-shopping-cart',        'route' => 'accounting.bills'],
        ['label' => 'Customers',  'sub' => 'Manage',        'icon' => 'o-users',                'route' => 'accounting.customers'],
        ['label' => 'Vendors',    'sub' => 'Manage',        'icon' => 'o-building-storefront',  'route' => 'accounting.vendors'],
        ['label' => 'Accounts',   'sub' => 'Chart',         'icon' => 'o-list-bullet',          'route' => 'accounting.accounts'],
        ['label' => 'Reports',    'sub' => 'Financial',     'icon' => 'o-chart-pie',            'route' => 'accounting.reports'],
    ] as $action)
        <a href="{{ route($action['route']) }}" wire:navigate
            class="flex flex-col items-center gap-2 p-3 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 hover:shadow-sm transition-all text-center">
            <x-tallui-icon :name="$action['icon']" class="w-6 h-6 text-primary" />
            <div>
                <div class="text-xs font-semibold leading-tight">{{ $action['label'] }}</div>
                <div class="text-xs text-base-content/40 leading-tight">{{ $action['sub'] }}</div>
            </div>
        </a>
    @endforeach
</div>

{{-- ── Primary KPI Stats ─────────────────────────────────────────────── --}}
<div class="stats stats-vertical lg:stats-horizontal shadow-sm w-full bg-base-100 border border-base-200 rounded-2xl overflow-x-auto">
    <x-tallui-stat
        title="Revenue"
        :value="$currency . ' ' . number_format($metrics['revenue'], 2)"
        icon="o-arrow-trending-up"
        icon-color="text-success"
        :desc="\Carbon\Carbon::parse($startDate)->format('M d') . ' – ' . \Carbon\Carbon::parse($endDate)->format('M d')"
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

{{-- ── AR / AP / Entity Summary Cards ──────────────────────────────────── --}}
<div class="grid grid-cols-2 lg:grid-cols-5 gap-4">

    <a href="{{ route('accounting.invoices') }}" wire:navigate class="group">
        <div class="card bg-base-100 border border-base-200 shadow-sm hover:shadow-md hover:border-success/40 transition-all rounded-2xl h-full">
            <div class="card-body p-4 gap-1">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-base-content/50 uppercase tracking-wide">Receivables</span>
                    <div class="w-8 h-8 rounded-lg bg-success/10 flex items-center justify-center">
                        <x-tallui-icon name="o-inbox-arrow-down" class="w-4 h-4 text-success" />
                    </div>
                </div>
                <div class="text-xl font-bold mt-1">{{ $currency }} {{ number_format($invoiceStats['outstanding_ar'], 2) }}</div>
                <div class="flex items-center gap-1.5 flex-wrap mt-1">
                    @if($invoiceStats['overdue_count'] > 0)
                        <x-tallui-badge type="error" size="sm">{{ $invoiceStats['overdue_count'] }} overdue</x-tallui-badge>
                    @endif
                    @if($invoiceStats['sent_count'] > 0)
                        <x-tallui-badge type="info" size="sm">{{ $invoiceStats['sent_count'] }} sent</x-tallui-badge>
                    @endif
                    @if($invoiceStats['partial_count'] > 0)
                        <x-tallui-badge type="warning" size="sm">{{ $invoiceStats['partial_count'] }} partial</x-tallui-badge>
                    @endif
                    @if(!$invoiceStats['overdue_count'] && !$invoiceStats['sent_count'] && !$invoiceStats['partial_count'])
                        <span class="text-xs text-base-content/40">All clear</span>
                    @endif
                </div>
            </div>
        </div>
    </a>

    <a href="{{ route('accounting.bills') }}" wire:navigate class="group">
        <div class="card bg-base-100 border border-base-200 shadow-sm hover:shadow-md hover:border-warning/40 transition-all rounded-2xl h-full">
            <div class="card-body p-4 gap-1">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-base-content/50 uppercase tracking-wide">Payables</span>
                    <div class="w-8 h-8 rounded-lg bg-warning/10 flex items-center justify-center">
                        <x-tallui-icon name="o-archive-box-arrow-down" class="w-4 h-4 text-warning" />
                    </div>
                </div>
                <div class="text-xl font-bold mt-1">{{ $currency }} {{ number_format($billStats['outstanding_ap'], 2) }}</div>
                <div class="flex items-center gap-1.5 flex-wrap mt-1">
                    @if($billStats['overdue_count'] > 0)
                        <x-tallui-badge type="error" size="sm">{{ $billStats['overdue_count'] }} overdue</x-tallui-badge>
                    @endif
                    @if($billStats['sent_count'] > 0)
                        <x-tallui-badge type="info" size="sm">{{ $billStats['sent_count'] }} sent</x-tallui-badge>
                    @endif
                    @if($billStats['partial_count'] > 0)
                        <x-tallui-badge type="warning" size="sm">{{ $billStats['partial_count'] }} partial</x-tallui-badge>
                    @endif
                    @if(!$billStats['overdue_count'] && !$billStats['sent_count'] && !$billStats['partial_count'])
                        <span class="text-xs text-base-content/40">All clear</span>
                    @endif
                </div>
            </div>
        </div>
    </a>

    <a href="{{ route('accounting.customers') }}" wire:navigate class="group">
        <div class="card bg-base-100 border border-base-200 shadow-sm hover:shadow-md hover:border-primary/40 transition-all rounded-2xl h-full">
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

    <a href="{{ route('accounting.vendors') }}" wire:navigate class="group">
        <div class="card bg-base-100 border border-base-200 shadow-sm hover:shadow-md hover:border-secondary/40 transition-all rounded-2xl h-full">
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

    <a href="{{ route('accounting.journal') }}" wire:navigate class="group col-span-2 lg:col-span-1">
        <div class="card bg-base-100 border border-base-200 shadow-sm hover:shadow-md hover:border-accent/40 transition-all rounded-2xl h-full">
            <div class="card-body p-4 gap-1">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-base-content/50 uppercase tracking-wide">Journal</span>
                    <div class="w-8 h-8 rounded-lg bg-accent/10 flex items-center justify-center">
                        <x-tallui-icon name="o-book-open" class="w-4 h-4 text-accent" />
                    </div>
                </div>
                <div class="text-xl font-bold mt-1">{{ number_format($ledgerStats['posted_count']) }}</div>
                <div class="text-xs text-base-content/50 mt-1">Posted entries in period</div>
                <div class="flex items-center gap-1.5 flex-wrap mt-1">
                    @if($ledgerStats['submitted_count'] > 0)
                        <x-tallui-badge type="info" size="sm">{{ $ledgerStats['submitted_count'] }} pending</x-tallui-badge>
                    @endif
                    @if($ledgerStats['draft_count'] > 0)
                        <x-tallui-badge type="warning" size="sm">{{ $ledgerStats['draft_count'] }} draft</x-tallui-badge>
                    @endif
                </div>
            </div>
        </div>
    </a>
</div>

{{-- ── Pending Approvals + Period Status ───────────────────────────────── --}}
@if($ledgerStats['submitted_count'] > 0 || $openPeriod)
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

    @if($ledgerStats['submitted_count'] > 0)
    <x-tallui-card padding="none">
        <div class="px-5 py-4 border-b border-base-200 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <x-tallui-icon name="o-clock" class="w-5 h-5 text-info" />
                <span class="font-semibold text-sm">Pending Approval</span>
                <x-tallui-badge type="info" size="sm">{{ $ledgerStats['submitted_count'] }}</x-tallui-badge>
            </div>
            <a href="{{ route('accounting.journal') }}?statusFilter=submitted" wire:navigate
                class="btn btn-ghost btn-xs">Review All</a>
        </div>
        <div class="divide-y divide-base-200">
            @foreach($pendingJournals as $pj)
                <a href="{{ route('accounting.journal') }}" wire:navigate
                    class="flex items-center justify-between px-5 py-3 hover:bg-base-200/50 transition-colors">
                    <div class="min-w-0">
                        <div class="text-sm font-mono font-semibold text-primary">{{ $pj->entry_number }}</div>
                        <div class="text-xs text-base-content/60 truncate max-w-[240px]">{{ $pj->description ?? '—' }}</div>
                        <div class="text-xs text-base-content/40 mt-0.5">
                            {{ $pj->submitter?->name ?? 'Unknown' }}
                            · {{ $pj->submitted_at?->diffForHumans() ?? '' }}
                        </div>
                    </div>
                    <div class="flex items-center gap-3 shrink-0">
                        <span class="font-mono text-sm font-semibold">
                            {{ $currency }} {{ number_format($pj->lines->where('type', 'debit')->sum('amount'), 2) }}
                        </span>
                        <span class="btn btn-success btn-xs pointer-events-none">Approve</span>
                    </div>
                </a>
            @endforeach
        </div>
    </x-tallui-card>
    @endif

    @if($openPeriod)
    <x-tallui-card>
        <div class="flex items-center gap-3 mb-4">
            <div class="w-9 h-9 rounded-xl bg-secondary/10 flex items-center justify-center">
                <x-tallui-icon name="o-calendar-days" class="w-5 h-5 text-secondary" />
            </div>
            <div>
                <h3 class="font-semibold text-sm">Current Accounting Period</h3>
                <p class="text-xs text-base-content/50">{{ $openPeriod->name }}</p>
            </div>
        </div>
        <div class="space-y-2.5">
            <div class="flex justify-between text-sm">
                <span class="text-base-content/60">From</span>
                <span class="font-mono text-sm">{{ \Carbon\Carbon::parse($openPeriod->start_date)->format('M d, Y') }}</span>
            </div>
            <div class="flex justify-between text-sm">
                <span class="text-base-content/60">Ends</span>
                <span class="font-mono text-sm {{ now()->gt($openPeriod->end_date) ? 'text-error font-bold' : '' }}">
                    {{ \Carbon\Carbon::parse($openPeriod->end_date)->format('M d, Y') }}
                    @if(now()->gt($openPeriod->end_date))
                        <x-tallui-badge type="error" size="sm" class="ml-1">Overdue</x-tallui-badge>
                    @else
                        <span class="text-xs text-base-content/40 ml-1">({{ \Carbon\Carbon::parse($openPeriod->end_date)->diffForHumans() }})</span>
                    @endif
                </span>
            </div>
            <div class="flex justify-between text-sm">
                <span class="text-base-content/60">Status</span>
                <x-tallui-badge type="success">Open</x-tallui-badge>
            </div>
        </div>
        <div class="mt-4 pt-3 border-t border-base-200">
            <a href="{{ route('accounting.period-close') }}" wire:navigate
                class="btn btn-outline btn-sm w-full gap-2">
                <x-tallui-icon name="o-lock-closed" size="w-4 h-4" />
                Close Period
            </a>
        </div>
    </x-tallui-card>
    @endif

</div>
@endif

{{-- ── Charts ────────────────────────────────────────────────────────────── --}}
<div class="grid grid-cols-1 xl:grid-cols-3 gap-4">

    <x-tallui-card
        title="Revenue vs Expenses"
        subtitle="{{ now()->year }} monthly breakdown"
        icon="o-chart-bar"
        class="xl:col-span-2"
    >
        <x-slot:actions>
            <a href="{{ route('accounting.reports') }}" wire:navigate class="btn btn-ghost btn-xs gap-1">
                P&amp;L Report <x-tallui-icon name="o-arrow-top-right-on-square" size="w-3 h-3" />
            </a>
        </x-slot:actions>
        @if(!empty($revenueExpensesChart['categories']))
            <livewire:tallui-bar-chart
                :series="$revenueExpensesChart['series']"
                :categories="$revenueExpensesChart['categories']"
                :height="260"
            />
        @else
            <x-tallui-empty-state title="No data yet" description="Post journal entries to see monthly trends." size="sm" />
        @endif
    </x-tallui-card>

    <x-tallui-card title="Balance Snapshot" icon="o-chart-pie">
        <x-slot:actions>
            <a href="{{ route('accounting.reports') }}" wire:navigate class="btn btn-ghost btn-xs gap-1">
                Balance Sheet <x-tallui-icon name="o-arrow-top-right-on-square" size="w-3 h-3" />
            </a>
        </x-slot:actions>
        @if($balanceChart['series'][0] > 0 || $balanceChart['series'][1] > 0 || $balanceChart['series'][2] > 0)
            <livewire:tallui-pie-chart
                :series="$balanceChart['series']"
                :categories="$balanceChart['categories']"
                :height="200"
                :donut="true"
            />
        @endif
        <div class="divide-y divide-base-200 mt-2">
            @foreach([
                ['label' => 'Assets',      'key' => 'total_assets',      'color' => 'bg-info'],
                ['label' => 'Liabilities', 'key' => 'total_liabilities', 'color' => 'bg-warning'],
                ['label' => 'Equity',      'key' => 'total_equity',      'color' => 'bg-secondary'],
            ] as $row)
            <div class="flex justify-between items-center py-2 text-sm">
                <span class="text-base-content/60 flex items-center gap-1.5">
                    <span class="w-2.5 h-2.5 rounded-full {{ $row['color'] }} inline-block"></span>
                    {{ $row['label'] }}
                </span>
                <span class="font-semibold font-mono text-xs">{{ $currency }} {{ number_format($metrics[$row['key']], 2) }}</span>
            </div>
            @endforeach
        </div>
    </x-tallui-card>

    {{-- ── Current Assets ────────────────────────────────────────────────── --}}
    <x-tallui-card title="Current Assets" icon="o-banknotes" subtitle="Liquid assets available" class="xl:col-span-1">
        @if($currentAssets->isEmpty())
            <x-tallui-empty-state title="No accounts" icon="o-banknotes" size="sm" />
        @else
            <div class="divide-y divide-base-200">
                @foreach($currentAssets as $item)
                <div class="flex justify-between items-center py-2 text-sm">
                    <span class="text-base-content/70 flex items-center gap-1.5">
                        <span class="font-mono text-primary text-xs">{{ $item['account']->code }}</span>
                        {{ $item['account']->name }}
                    </span>
                    <span class="font-semibold font-mono text-xs">{{ $currency }} {{ number_format($item['balance'], 2) }}</span>
                </div>
                @endforeach
                <div class="flex justify-between items-center py-2.5 mt-1 bg-info/10 rounded-lg px-2 font-bold text-sm">
                    <span>Total</span>
                    <span class="font-mono">{{ $currency }} {{ number_format($currentAssetTotal, 2) }}</span>
                </div>
            </div>
        @endif
    </x-tallui-card>
</div>

{{-- ── Recent Invoices & Bills ──────────────────────────────────────────── --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

    <x-tallui-card title="Recent Invoices" icon="o-document-text" padding="none">
        <x-slot:actions>
            <a href="{{ route('accounting.invoices') }}" wire:navigate class="btn btn-ghost btn-xs">View All</a>
        </x-slot:actions>
        @if($recentInvoices->isEmpty())
            <div class="p-6">
                <x-tallui-empty-state title="No invoices yet" icon="o-document-text" size="sm">
                    <a href="{{ route('accounting.invoices') }}" wire:navigate class="btn btn-primary btn-sm">Create Invoice</a>
                </x-tallui-empty-state>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="table table-sm w-full">
                    <thead>
                        <tr class="text-xs text-base-content/40 uppercase">
                            <th class="pl-5">Invoice</th>
                            <th>Customer</th>
                            <th class="text-right">Total</th>
                            <th class="pr-5">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentInvoices as $invoice)
                            <tr class="hover:bg-base-200/50 cursor-pointer"
                                onclick="window.location='{{ route('accounting.invoices.show', $invoice->id) }}'">
                                <td class="pl-5 font-mono text-xs font-semibold text-primary whitespace-nowrap">
                                    {{ $invoice->invoice_number }}
                                </td>
                                <td class="text-sm max-w-[120px] truncate">{{ $invoice->customer?->name ?? '—' }}</td>
                                <td class="text-right text-sm font-medium whitespace-nowrap">
                                    {{ $invoice->base_currency }} {{ number_format($invoice->base_total, 2) }}
                                </td>
                                <td class="pr-5">
                                    @php $s = $invoice->status->value ?? $invoice->status @endphp
                                    <x-tallui-badge size="sm" :type="match($s) {
                                        'settled'           => 'success',
                                        'partially_settled' => 'warning',
                                        'overdue'           => 'error',
                                        'sent', 'issued'    => 'info',
                                        default             => 'neutral',
                                    }">{{ ucfirst(str_replace('_', ' ', $s)) }}</x-tallui-badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <x-slot:footer>
            @if($invoiceStats['overdue_count'] > 0)
                <div class="flex items-center gap-2 text-xs text-error font-medium">
                    <x-tallui-icon name="o-exclamation-triangle" class="w-3.5 h-3.5 shrink-0" />
                    {{ $invoiceStats['overdue_count'] }} overdue — {{ $currency }} {{ number_format($invoiceStats['overdue_total'], 2) }}
                </div>
            @else
                <span class="text-xs text-base-content/40">No overdue invoices</span>
            @endif
        </x-slot:footer>
    </x-tallui-card>

    <x-tallui-card title="Recent Bills" icon="o-shopping-cart" padding="none">
        <x-slot:actions>
            <a href="{{ route('accounting.bills') }}" wire:navigate class="btn btn-ghost btn-xs">View All</a>
        </x-slot:actions>
        @if($recentBills->isEmpty())
            <div class="p-6">
                <x-tallui-empty-state title="No bills yet" icon="o-shopping-cart" size="sm">
                    <a href="{{ route('accounting.bills') }}" wire:navigate class="btn btn-primary btn-sm">Add Bill</a>
                </x-tallui-empty-state>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="table table-sm w-full">
                    <thead>
                        <tr class="text-xs text-base-content/40 uppercase">
                            <th class="pl-5">Bill</th>
                            <th>Vendor</th>
                            <th class="text-right">Total</th>
                            <th class="pr-5">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentBills as $bill)
                            <tr class="hover:bg-base-200/50 cursor-pointer"
                                onclick="window.location='{{ route('accounting.bills.show', $bill->id) }}'">
                                <td class="pl-5 font-mono text-xs font-semibold text-primary whitespace-nowrap">
                                    {{ $bill->bill_number }}
                                </td>
                                <td class="text-sm max-w-[120px] truncate">{{ $bill->vendor?->name ?? '—' }}</td>
                                <td class="text-right text-sm font-medium whitespace-nowrap">
                                    {{ $bill->base_currency }} {{ number_format($bill->base_total, 2) }}
                                </td>
                                <td class="pr-5">
                                    @php $s = $bill->status->value ?? $bill->status @endphp
                                    <x-tallui-badge size="sm" :type="match($s) {
                                        'settled'           => 'success',
                                        'partially_settled' => 'warning',
                                        'overdue'           => 'error',
                                        'sent', 'issued'    => 'info',
                                        default             => 'neutral',
                                    }">{{ ucfirst(str_replace('_', ' ', $s)) }}</x-tallui-badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <x-slot:footer>
            @if($billStats['overdue_count'] > 0)
                <div class="flex items-center gap-2 text-xs text-error font-medium">
                    <x-tallui-icon name="o-exclamation-triangle" class="w-3.5 h-3.5 shrink-0" />
                    {{ $billStats['overdue_count'] }} overdue — {{ $currency }} {{ number_format($billStats['overdue_total'], 2) }}
                </div>
            @else
                <span class="text-xs text-base-content/40">No overdue bills</span>
            @endif
        </x-slot:footer>
    </x-tallui-card>
</div>

{{-- ── Recent Journal Entries ────────────────────────────────────────────── --}}
<x-tallui-card title="Recent Journal Entries" icon="o-clock" padding="none">
    <x-slot:actions>
        <a href="{{ route('accounting.ledger') }}" wire:navigate class="btn btn-ghost btn-xs">Ledger</a>
        <a href="{{ route('accounting.journal') }}" wire:navigate class="btn btn-ghost btn-xs">View All</a>
        <a href="{{ route('accounting.journal') }}" wire:navigate class="btn btn-primary btn-xs gap-1">
            <x-tallui-icon name="o-plus" size="w-3 h-3" /> New Entry
        </a>
    </x-slot:actions>

    @if($recentEntries->isEmpty())
        <div class="p-6">
            <x-tallui-empty-state
                title="No journal entries yet"
                description="Create your first journal entry to get started."
                icon="o-document-text"
                size="sm"
            >
                <a href="{{ route('accounting.journal') }}" wire:navigate class="btn btn-primary btn-sm">New Entry</a>
            </x-tallui-empty-state>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="text-xs text-base-content/40 uppercase">
                        <th class="pl-5">Entry #</th>
                        <th>Date</th>
                        <th>Description</th>
                        <th>Type</th>
                        <th class="text-right">Amount</th>
                        <th class="pr-5">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentEntries as $entry)
                        <tr class="hover:bg-base-200/50">
                            <td class="pl-5 font-mono text-xs font-semibold text-primary whitespace-nowrap">{{ $entry->entry_number }}</td>
                            <td class="text-xs text-base-content/60 whitespace-nowrap">{{ $entry->date->format('M d, Y') }}</td>
                            <td class="text-sm max-w-[200px] truncate text-base-content/80">{{ $entry->description ?? '—' }}</td>
                            <td class="text-xs capitalize text-base-content/50">{{ $entry->type ?? 'general' }}</td>
                            <td class="text-right font-mono text-sm font-medium whitespace-nowrap">
                                {{ number_format($entry->lines->where('type', 'debit')->sum('amount'), 2) }}
                            </td>
                            <td class="pr-5">
                                @php $s = $entry->status->value ?? $entry->status @endphp
                                <x-tallui-badge size="sm" :type="match($s) {
                                    'posted'    => 'success',
                                    'submitted' => 'info',
                                    'void'      => 'error',
                                    default     => 'warning',
                                }">{{ $s === 'submitted' ? 'Pending' : ucfirst($s) }}</x-tallui-badge>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-tallui-card>

</div>{{-- /px wrapper --}}
</div>
