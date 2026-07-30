<div class="grid grid-cols-2 gap-4 lg:grid-cols-3 xl:grid-cols-6">
    <div class="rounded-2xl border border-base-300 bg-base-100 shadow-theme-xs">
        <x-tallui-stat
            title="Revenue"
            :value="$currency . ' ' . number_format($metrics['revenue'], 2)"
            icon="o-arrow-trending-up"
            icon-color="text-success"
            :desc="\Carbon\Carbon::parse($startDate)->format('M d') . ' – ' . \Carbon\Carbon::parse($endDate)->format('M d')"
        />
    </div>
    <div class="rounded-2xl border border-base-300 bg-base-100 shadow-theme-xs">
        <x-tallui-stat
            title="Expenses"
            :value="$currency . ' ' . number_format($metrics['expenses'], 2)"
            icon="o-arrow-trending-down"
            icon-color="text-error"
            desc="Total costs for period"
        />
    </div>
    <div class="rounded-2xl border border-base-300 bg-base-100 shadow-theme-xs">
        <x-tallui-stat
            :title="$metrics['net_income'] >= 0 ? 'Net Profit' : 'Net Loss'"
            :value="$currency . ' ' . number_format(abs($metrics['net_income']), 2)"
            :icon="$metrics['net_income'] >= 0 ? 'o-face-smile' : 'o-face-frown'"
            :icon-color="$metrics['net_income'] >= 0 ? 'text-primary' : 'text-error'"
            :desc="$metrics['net_income'] >= 0 ? 'Profitable period' : 'Loss period'"
        />
    </div>
    <div class="rounded-2xl border border-base-300 bg-base-100 shadow-theme-xs">
        <x-tallui-stat
            title="Total Assets"
            :value="$currency . ' ' . number_format($metrics['total_assets'], 2)"
            icon="o-building-library"
            icon-color="text-info"
            desc="Current asset base"
        />
    </div>
    <div class="rounded-2xl border border-base-300 bg-base-100 shadow-theme-xs">
        <x-tallui-stat
            title="Liabilities"
            :value="$currency . ' ' . number_format($metrics['total_liabilities'], 2)"
            icon="o-credit-card"
            icon-color="text-warning"
            desc="Total obligations"
        />
    </div>
    <div class="rounded-2xl border border-base-300 bg-base-100 shadow-theme-xs">
        <x-tallui-stat
            title="Equity"
            :value="$currency . ' ' . number_format($metrics['total_equity'], 2)"
            icon="o-scale"
            icon-color="text-secondary"
            desc="Owner's equity"
        />
    </div>
</div>
