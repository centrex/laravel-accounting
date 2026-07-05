<div class="space-y-6">
<x-tallui-notification />

{{-- ── Page Header ──────────────────────────────────────────────────────── --}}
<x-tallui-page-header
    title="Accounting"
    subtitle="Financial overview · {{ \Carbon\Carbon::parse($startDate)->format('M d') }} – {{ \Carbon\Carbon::parse($endDate)->format('M d, Y') }}"
    icon="o-chart-bar-square"
>
    <x-slot:actions>
        <select wire:model.live="dateRange" class="select select-bordered select-sm w-36">
            <option value="today">Today</option>
            <option value="this_week">This Week</option>
            <option value="this_month">This Month</option>
            <option value="this_quarter">This Quarter</option>
            <option value="this_year">This Year</option>
        </select>
        @can('accounting.requisitions.view')
        <x-tallui-button label="Requisitions" icon="o-clipboard-document" :link="route('accounting.requisitions')" class="btn-outline btn-sm" />
        @endcan
        @can('accounting.invoice.view')
        <x-tallui-button label="Invoice" icon="o-document-text" :link="route('accounting.invoices')" class="btn-outline btn-sm" />
        @endcan
        @can('accounting.bill.view')
        <x-tallui-button label="Bill" icon="o-inbox-arrow-down" :link="route('accounting.bills')" class="btn-outline btn-sm" />
        @endcan
        @can('accounting.expense.view')
        <x-tallui-button label="Expense" icon="o-credit-card" :link="route('accounting.expenses')" class="btn-outline btn-sm" />
        @endcan
        @can('accounting.journal.view')
        <x-tallui-button label="Journal" icon="o-pencil-square" :link="route('accounting.journal')" class="btn-outline btn-sm" />
        @endcan
        @can('accounting.ledger.view')
        <x-tallui-button label="Ledger" icon="o-book-open" :link="route('accounting.ledger')" class="btn-outline btn-sm" />
        @endcan
        @can('accounting.reports.view')
        <x-tallui-button label="Reports" icon="o-chart-pie" :link="route('accounting.reports')" class="btn-outline btn-sm" />
        @endcan
        @can('accounting.fiscal-year.close')
        <x-tallui-button label="Period Close" icon="o-lock-closed" :link="route('accounting.period-close')" class="btn-primary btn-sm" />
        @endcan
    </x-slot:actions>
</x-tallui-page-header>

{{-- ── Alerts ───────────────────────────────────────────────────────────── --}}
@if($openPeriod && \Carbon\Carbon::parse($openPeriod->end_date)->isPast())
    <x-tallui-alert type="warning" title="Fiscal Period Overdue for Closing" :dismissible="true">
        Period <strong>{{ $openPeriod->name }}</strong> ended
        {{ \Carbon\Carbon::parse($openPeriod->end_date)->format('M d, Y') }} and has not been closed.
        @can('accounting.fiscal-year.close')
        <a href="{{ route('accounting.period-close') }}" wire:navigate class="link link-warning font-semibold ml-1">Close now →</a>
        @endcan
    </x-tallui-alert>
@endif

@if($invoiceStats['overdue_count'] > 0 || $billStats['overdue_count'] > 0)
    <div class="flex flex-col sm:flex-row gap-3">
        @if($invoiceStats['overdue_count'] > 0)
            <x-tallui-alert type="error" title="{{ $invoiceStats['overdue_count'] }} Invoice{{ $invoiceStats['overdue_count'] > 1 ? 's' : '' }} Overdue" :dismissible="true">
                {{ $currency }} {{ number_format($invoiceStats['overdue_total'], 2) }} outstanding.
                @can('accounting.invoice.view')
                <a href="{{ route('accounting.invoices') }}" wire:navigate class="link link-error font-semibold ml-1">Review →</a>
                @endcan
            </x-tallui-alert>
        @endif
        @if($billStats['overdue_count'] > 0)
            <x-tallui-alert type="warning" title="{{ $billStats['overdue_count'] }} Bill{{ $billStats['overdue_count'] > 1 ? 's' : '' }} Overdue" :dismissible="true">
                {{ $currency }} {{ number_format($billStats['overdue_total'], 2) }} due.
                @can('accounting.bill.view')
                <a href="{{ route('accounting.bills') }}" wire:navigate class="link link-warning font-semibold ml-1">Review →</a>
                @endcan
            </x-tallui-alert>
        @endif
    </div>
@endif

{{-- ── Quick Actions (collapsible, preference saved to localStorage) ──────── --}}
<div x-data="{
    open: localStorage.getItem('acct_quick_actions') === 'true',
    toggle() { this.open = !this.open; localStorage.setItem('acct_quick_actions', this.open ? 'true' : 'false'); }
}">
    <div class="flex items-center justify-between mb-3">
        <span class="text-xs font-semibold text-base-content/40 uppercase tracking-widest">Quick Actions</span>
        <button @click="toggle()" class="btn btn-ghost btn-xs gap-1 text-base-content/50 hover:text-base-content">
            <span x-text="open ? 'Hide' : 'Show'"></span>
            <x-heroicon-o-chevron-up x-show="open" class="w-3 h-3" x-cloak />
            <x-heroicon-o-chevron-down x-show="!open" class="w-3 h-3" x-cloak />
        </button>
    </div>
    <div x-show="open" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-1">
<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3">
    @can('accounting.journal.view')
    <a href="{{ route('accounting.journal') }}" wire:navigate
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-pencil-square class="w-7 h-7 text-primary" />
        <span class="text-sm font-medium">Journal</span>
    </a>
    @endcan
    @can('accounting.ledger.view')
    <a href="{{ route('accounting.ledger') }}" wire:navigate
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-book-open class="w-7 h-7 text-info" />
        <span class="text-sm font-medium">Ledger</span>
    </a>
    @endcan
    @can('accounting.invoice.view')
    <a href="{{ route('accounting.invoices') }}" wire:navigate
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-document-text class="w-7 h-7 text-success" />
        <span class="text-sm font-medium">Invoices</span>
    </a>
    @endcan
    @can('accounting.bill.view')
    <a href="{{ route('accounting.bills') }}" wire:navigate
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-inbox-arrow-down class="w-7 h-7 text-warning" />
        <span class="text-sm font-medium">Bills</span>
    </a>
    @endcan
    @can('accounting.expense.view')
    <a href="{{ route('accounting.expenses') }}" wire:navigate
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-credit-card class="w-7 h-7 text-error" />
        <span class="text-sm font-medium">Expenses</span>
    </a>
    @endcan
    @can('accounting.requisitions.view')
    <a href="{{ route('accounting.requisitions') }}" wire:navigate
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-clipboard-document class="w-7 h-7 text-secondary" />
        <span class="text-sm font-medium">Requisitions</span>
    </a>
    @endcan
    @can('accounting.customers.view')
    <a href="{{ route('accounting.customers') }}" wire:navigate
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-users class="w-7 h-7 text-primary" />
        <span class="text-sm font-medium">Customers</span>
    </a>
    @endcan
    @can('accounting.vendors.view')
    <a href="{{ route('accounting.vendors') }}" wire:navigate
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-building-storefront class="w-7 h-7 text-secondary" />
        <span class="text-sm font-medium">Vendors</span>
    </a>
    @endcan
    @can('accounting.accounts.view')
    <a href="{{ route('accounting.accounts') }}" wire:navigate
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-list-bullet class="w-7 h-7 text-accent" />
        <span class="text-sm font-medium">Accounts</span>
    </a>
    @endcan
    @can('accounting.budget.view')
    <a href="{{ route('accounting.budgets') }}" wire:navigate
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-calculator class="w-7 h-7 text-info" />
        <span class="text-sm font-medium">Budgets</span>
    </a>
    @endcan
    @can('accounting.fiscal-year.close')
    <a href="{{ route('accounting.period-close') }}" wire:navigate
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-lock-closed class="w-7 h-7 text-warning" />
        <span class="text-sm font-medium">Period Close</span>
    </a>
    @endcan
    @can('accounting.reports.view')
    <a href="{{ route('accounting.reports') }}" wire:navigate
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-chart-pie class="w-7 h-7 text-success" />
        <span class="text-sm font-medium">Reports</span>
    </a>
    @endcan
</div>
    </div>
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

    @can('accounting.invoice.view')
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
    @endcan

    @can('accounting.bill.view')
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
    @endcan

    @can('accounting.customers.view')
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
    @endcan

    @can('accounting.vendors.view')
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
    @endcan

    @can('accounting.journal.view')
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
    @endcan
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
            @can('accounting.journal.view')
            <a href="{{ route('accounting.journal') }}?statusFilter=submitted" wire:navigate
                class="btn btn-ghost btn-xs">Review All</a>
            @endcan
        </div>
        <div class="divide-y divide-base-200">
            @can('accounting.journal.view')
            @foreach($pendingJournals as $pj)
                <a href="{{ route('accounting.journal', ['view' => $pj->id]) }}" wire:navigate
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
            @endcan
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
            @can('accounting.fiscal-year.close')
            <a href="{{ route('accounting.period-close') }}" wire:navigate
                class="btn btn-outline btn-sm w-full gap-2">
                <x-tallui-icon name="o-lock-closed" size="w-4 h-4" />
                Close Period
            </a>
            @endcan
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
            @can('accounting.reports.view')
            <a href="{{ route('accounting.reports') }}" wire:navigate class="btn btn-ghost btn-xs gap-1">
                P&amp;L Report <x-tallui-icon name="o-arrow-top-right-on-square" size="w-3 h-3" />
            </a>
            @endcan
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
            @can('accounting.reports.view')
            <a href="{{ route('accounting.reports') }}" wire:navigate class="btn btn-ghost btn-xs gap-1">
                Balance Sheet <x-tallui-icon name="o-arrow-top-right-on-square" size="w-3 h-3" />
            </a>
            @endcan
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
            @can('accounting.invoice.view')
            <a href="{{ route('accounting.invoices') }}" wire:navigate class="btn btn-ghost btn-xs">View All</a>
            @endcan
        </x-slot:actions>
        @if($recentInvoices->isEmpty())
            <div class="p-6">
                <x-tallui-empty-state title="No invoices yet" icon="o-document-text" size="sm">
                    @can('accounting.invoice.create')
                    <a href="{{ route('accounting.invoices') }}" wire:navigate class="btn btn-primary btn-sm">Create Invoice</a>
                    @endcan
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
                        @can('accounting.invoice.view')
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
                        @endcan
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

    <x-tallui-card title="Recent Bills" icon="o-inbox-arrow-down" padding="none">
        <x-slot:actions>
            @can('accounting.bill.view')
            <a href="{{ route('accounting.bills') }}" wire:navigate class="btn btn-ghost btn-xs">View All</a>
            @endcan
        </x-slot:actions>
        @if($recentBills->isEmpty())
            <div class="p-6">
                <x-tallui-empty-state title="No bills yet" icon="o-inbox-arrow-down" size="sm">
                    @can('accounting.bill.create')
                    <a href="{{ route('accounting.bills') }}" wire:navigate class="btn btn-primary btn-sm">Add Bill</a>
                    @endcan
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
                        @can('accounting.bill.view')
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
                        @endcan
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
        @can('accounting.ledger.view')
        <a href="{{ route('accounting.ledger') }}" wire:navigate class="btn btn-ghost btn-xs">Ledger</a>
        @endcan
        @can('accounting.journal.view')
        <a href="{{ route('accounting.journal') }}" wire:navigate class="btn btn-ghost btn-xs">View All</a>
        @endcan
        @can('accounting.journal.create')
        <a href="{{ route('accounting.journal') }}" wire:navigate class="btn btn-primary btn-xs gap-1">
            <x-tallui-icon name="o-plus" size="w-3 h-3" /> New Entry
        </a>
        @endcan
    </x-slot:actions>

    @if($recentEntries->isEmpty())
        <div class="p-6">
            <x-tallui-empty-state
                title="No journal entries yet"
                description="Create your first journal entry to get started."
                icon="o-document-text"
                size="sm"
            >
                @can('accounting.journal.create')
                <a href="{{ route('accounting.journal') }}" wire:navigate class="btn btn-primary btn-sm">New Entry</a>
                @endcan
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
                    @can('accounting.journal.view')
                    @foreach($recentEntries as $entry)
                        <tr class="hover:bg-base-200/50">
                            <td class="pl-5 font-mono text-xs font-semibold whitespace-nowrap">
                                <a href="{{ route('accounting.journal', ['view' => $entry->id]) }}" wire:navigate class="text-primary hover:underline">
                                    {{ $entry->entry_number }}
                                </a>
                            </td>
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
                    @endcan
                </tbody>
            </table>
        </div>
    @endif
</x-tallui-card>

</div>
