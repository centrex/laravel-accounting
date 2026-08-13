<div class="space-y-6">
<x-tallui-notification />

<x-tallui-page-header
    title="Financial Reports"
    subtitle="Generate trial balance, balance sheet, P&L and cash flow statements"
    icon="o-chart-bar-square"
>
    <x-slot:actions>
        <x-tallui-button
            wire:click="exportAllExcel"
            spinner="exportAllExcel"
            label="Export All (Excel)"
            icon="o-arrow-down-tray"
            class="btn-ghost btn-sm"
        />
    </x-slot:actions>
</x-tallui-page-header>

{{-- ── Report Configuration ─────────────────────────────────────────────── --}}
<x-tallui-card padding="compact">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 p-1">
        <x-tallui-form-group label="Report Type">
            <x-tallui-select wire:model.live="reportType" class="select-sm">
                <option value="trial_balance">Trial Balance</option>
                <option value="balance_sheet">Balance Sheet</option>
                <option value="income_statement">Income Statement (P&L)</option>
                <option value="cash_flow">Cash Flow Statement</option>
                <option value="cash_flow_forecast">Cash Flow Forecast</option>
                <option value="cash_book">Cash Book</option>
                <option value="sales_tax_liability">Sales Tax Liability</option>
            </x-tallui-select>
        </x-tallui-form-group>

        <x-tallui-form-group label="Start Date">
            <x-tallui-input type="date" wire:model="startDate" class="input-sm" />
        </x-tallui-form-group>

        <x-tallui-form-group label="End Date">
            <x-tallui-input type="date" wire:model="endDate" class="input-sm" />
        </x-tallui-form-group>

        <x-tallui-form-group label="SBU Code">
            <x-tallui-input
                wire:model.live.debounce.300ms="sbuCode"
                class="input-sm"
                placeholder="All SBUs or e.g. OCT"
            />
        </x-tallui-form-group>

        <div class="flex items-end">
            <x-tallui-button
                wire:click="generateReport"
                spinner="generateReport"
                label="Generate"
                icon="o-arrow-path"
                class="btn-primary btn-sm w-full"
            />
        </div>
    </div>
</x-tallui-card>

{{-- ── Report Output ────────────────────────────────────────────────────── --}}
@if($reportData)
    <x-tallui-card>
        <x-slot:actions>
            <x-tallui-button
                wire:click="exportExcel"
                spinner="exportExcel"
                label="Export Excel"
                icon="o-arrow-down-tray"
                class="btn-ghost btn-sm"
            />
            <x-tallui-button
                wire:click="exportPdf"
                spinner="exportPdf"
                label="Export PDF"
                icon="o-arrow-down-tray"
                class="btn-ghost btn-sm"
            />
        </x-slot:actions>

        {{-- Report title & period --}}
        <div class="mb-6">
            <h3 class="text-xl font-bold">
                @if($reportType === 'trial_balance')        Trial Balance
                @elseif($reportType === 'balance_sheet')    Balance Sheet
                @elseif($reportType === 'income_statement') Income Statement
                @elseif($reportType === 'cash_flow')        Cash Flow Statement
                @elseif($reportType === 'cash_flow_forecast') Cash Flow Forecast
                @elseif($reportType === 'cash_book')        Cash Book
                @elseif($reportType === 'sales_tax_liability') Sales Tax Liability Report
                @endif
            </h3>
            <p class="text-sm text-base-content/50 mt-0.5">
                @if($reportType === 'balance_sheet')
                    As of {{ \Carbon\Carbon::parse($endDate)->format('F d, Y') }}
                @elseif($reportType === 'cash_flow_forecast')
                    As of {{ \Carbon\Carbon::parse($reportData['as_of'])->format('F d, Y') }}
                    · next {{ $reportData['forecast_weeks'] }} weeks
                @else
                    {{ \Carbon\Carbon::parse($startDate)->format('M d, Y') }} – {{ \Carbon\Carbon::parse($endDate)->format('M d, Y') }}
                @endif
                @if(($reportData['sbu_code'] ?? null) || $sbuCode !== '')
                    · SBU: {{ $reportData['sbu_code'] ?? strtoupper($sbuCode) }}
                @endif
            </p>
        </div>

        {{-- ── Trial Balance ─────────────────────────────────────────────── --}}
        @if($reportType === 'trial_balance' && isset($reportData['accounts']))
            <div class="overflow-x-auto">
                <table class="table table-sm w-full">
                    <thead>
                        <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                            <th class="py-3">Code</th>
                            <th>Account Name</th>
                            <th class="text-right">Debit</th>
                            <th class="text-right">Credit</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-base-200">
                        @foreach($reportData['accounts'] as $item)
                            <tr class="even:bg-base-200/50 hover:bg-base-200">
                                <td class="font-mono text-sm text-primary">{{ $item['account']->code }}</td>
                                <td class="text-sm">{{ $item['account']->name }}</td>
                                <td class="text-right text-sm font-mono">
                                    @if($item['debit'] > 0)
                                        {{ $currency }} {{ number_format($item['debit'], 2) }}
                                    @else
                                        <span class="text-base-content/30">—</span>
                                    @endif
                                </td>
                                <td class="text-right text-sm font-mono">
                                    @if($item['credit'] > 0)
                                        {{ $currency }} {{ number_format($item['credit'], 2) }}
                                    @else
                                        <span class="text-base-content/30">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-base-300 font-bold bg-base-200/50">
                            <td colspan="2" class="py-3 text-sm">TOTAL</td>
                            <td class="text-right text-sm font-mono">{{ $currency }} {{ number_format($reportData['total_debits'], 2) }}</td>
                            <td class="text-right text-sm font-mono">{{ $currency }} {{ number_format($reportData['total_credits'], 2) }}</td>
                        </tr>
                        <tr>
                            <td colspan="4" class="py-2 text-center text-sm">
                                @if($reportData['is_balanced'])
                                    <x-tallui-badge type="success">Balanced</x-tallui-badge>
                                @else
                                    <x-tallui-badge type="error">Not Balanced</x-tallui-badge>
                                @endif
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif

        {{-- ── Balance Sheet ──────────────────────────────────────────────── --}}
        @if($reportType === 'balance_sheet' && isset($reportData['assets']))
            <div class="space-y-6">
                @foreach([
                    ['key' => 'assets',      'label' => 'ASSETS',      'color' => 'text-success',   'total_key' => 'total'],
                    ['key' => 'liabilities', 'label' => 'LIABILITIES', 'color' => 'text-error',     'total_key' => 'total'],
                    ['key' => 'equity',      'label' => 'EQUITY',      'color' => 'text-secondary',  'total_key' => 'total_with_income'],
                ] as $section)
                    <div>
                        <div class="flex items-center gap-2 border-b border-base-300 pb-2 mb-3">
                            <span class="text-xs font-bold tracking-widest uppercase {{ $section['color'] }}">{{ $section['label'] }}</span>
                        </div>
                        <div class="divide-y divide-base-200">
                            @foreach($reportData[$section['key']]['accounts'] as $item)
                                <div class="flex justify-between py-2 px-2 hover:bg-base-200/40 rounded">
                                    <span class="text-sm text-base-content/70">
                                        <span class="font-mono text-primary text-xs mr-1">{{ $item['account']->code }}</span>
                                        {{ $item['account']->name }}
                                    </span>
                                    <span class="text-sm font-mono font-medium">{{ $currency }} {{ number_format($item['balance'], 2) }}</span>
                                </div>
                            @endforeach

                            @if($section['key'] === 'equity' && isset($reportData['equity']['net_income']))
                                <div class="flex justify-between py-2 px-2 hover:bg-base-200/40 rounded">
                                    <span class="text-sm text-base-content/70 italic">Net Income (Current Period)</span>
                                    <span class="text-sm font-mono font-medium">{{ $currency }} {{ number_format($reportData['equity']['net_income'], 2) }}</span>
                                </div>
                            @endif

                            <div class="flex justify-between py-2.5 px-2 mt-1 bg-base-200/60 rounded-lg font-bold">
                                <span class="text-sm">Total {{ ucfirst($section['key']) }}</span>
                                <span class="text-sm font-mono">{{ $currency }} {{ number_format($reportData[$section['key']][$section['total_key']], 2) }}</span>
                            </div>
                        </div>
                    </div>
                @endforeach

                <div class="border-t-2 border-base-300 pt-4">
                    <div class="flex justify-between py-3 px-4 bg-primary/10 rounded-xl font-bold text-base">
                        <span>Total Liabilities &amp; Equity</span>
                        <span class="font-mono">{{ $currency }} {{ number_format($reportData['liabilities']['total'] + $reportData['equity']['total_with_income'], 2) }}</span>
                    </div>
                    <div class="text-center mt-3">
                        @if($reportData['is_balanced'])
                            <x-tallui-badge type="success">Balance Sheet is Balanced</x-tallui-badge>
                        @else
                            <x-tallui-badge type="error">Balance Sheet is NOT Balanced</x-tallui-badge>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        {{-- ── Income Statement ───────────────────────────────────────────── --}}
        @if($reportType === 'income_statement' && isset($reportData['revenue']))
            <div class="space-y-6">
                {{-- Revenue --}}
                <div>
                    <div class="flex items-center gap-2 border-b border-base-300 pb-2 mb-3">
                        <span class="text-xs font-bold tracking-widest uppercase text-success">REVENUE</span>
                    </div>
                    <div class="divide-y divide-base-200">
                        @foreach($reportData['revenue']['accounts'] as $item)
                            <div class="flex justify-between py-2 px-2 hover:bg-base-200/40 rounded">
                                <span class="text-sm text-base-content/70">
                                    <span class="font-mono text-primary text-xs mr-1">{{ $item['account']->code }}</span>
                                    {{ $item['account']->name }}
                                </span>
                                <span class="text-sm font-mono font-medium">{{ $currency }} {{ number_format($item['balance'], 2) }}</span>
                            </div>
                        @endforeach
                        <div class="flex justify-between py-2.5 px-2 mt-1 bg-success/10 rounded-lg font-bold">
                            <span class="text-sm">Total Revenue</span>
                            <span class="text-sm font-mono">{{ $currency }} {{ number_format($reportData['revenue']['total'], 2) }}</span>
                        </div>
                    </div>
                </div>

                {{-- Cost of Goods Sold --}}
                @if(!empty($reportData['cogs']['accounts']))
                <div>
                    <div class="flex items-center gap-2 border-b border-base-300 pb-2 mb-3">
                        <span class="text-xs font-bold tracking-widest uppercase text-warning">COST OF GOODS SOLD</span>
                    </div>
                    <div class="divide-y divide-base-200">
                        @foreach($reportData['cogs']['accounts'] as $item)
                            <div class="flex justify-between py-2 px-2 hover:bg-base-200/40 rounded">
                                <span class="text-sm text-base-content/70">
                                    <span class="font-mono text-primary text-xs mr-1">{{ $item['account']->code }}</span>
                                    {{ $item['account']->name }}
                                </span>
                                <span class="text-sm font-mono font-medium">{{ $currency }} {{ number_format($item['balance'], 2) }}</span>
                            </div>
                        @endforeach
                        <div class="flex justify-between py-2.5 px-2 mt-1 bg-warning/10 rounded-lg font-bold">
                            <span class="text-sm">Total COGS</span>
                            <span class="text-sm font-mono">{{ $currency }} {{ number_format($reportData['cogs']['total'], 2) }}</span>
                        </div>
                    </div>
                </div>

                {{-- Gross Profit --}}
                <div class="border-t border-base-300 pt-2">
                    <div @class([
                        'flex justify-between py-2.5 px-4 rounded-lg font-bold',
                        'bg-success/10' => $reportData['gross_profit'] >= 0,
                        'bg-error/10'   => $reportData['gross_profit'] < 0,
                    ])>
                        <span class="text-sm">GROSS PROFIT</span>
                        <span @class([
                            'text-sm font-mono',
                            'text-success' => $reportData['gross_profit'] >= 0,
                            'text-error'   => $reportData['gross_profit'] < 0,
                        ])>{{ $currency }} {{ number_format($reportData['gross_profit'], 2) }}</span>
                    </div>
                </div>
                @endif

                {{-- Operating Expenses --}}
                @if(!empty($reportData['expenses']['accounts']))
                <div>
                    <div class="flex items-center gap-2 border-b border-base-300 pb-2 mb-3">
                        <span class="text-xs font-bold tracking-widest uppercase text-error">OPERATING EXPENSES</span>
                    </div>
                    <div class="divide-y divide-base-200">
                        @foreach($reportData['expenses']['accounts'] as $item)
                            <div class="flex justify-between py-2 px-2 hover:bg-base-200/40 rounded">
                                <span class="text-sm text-base-content/70">
                                    <span class="font-mono text-primary text-xs mr-1">{{ $item['account']->code }}</span>
                                    {{ $item['account']->name }}
                                </span>
                                <span class="text-sm font-mono font-medium">{{ $currency }} {{ number_format($item['balance'], 2) }}</span>
                            </div>
                        @endforeach
                        <div class="flex justify-between py-2.5 px-2 mt-1 bg-error/10 rounded-lg font-bold">
                            <span class="text-sm">Total Operating Expenses</span>
                            <span class="text-sm font-mono">{{ $currency }} {{ number_format($reportData['expenses']['total'], 2) }}</span>
                        </div>
                    </div>
                </div>
                @endif

                {{-- Net --}}
                <div class="border-t-2 border-base-300 pt-4">
                    <div @class([
                        'flex justify-between py-3 px-4 rounded-xl font-bold text-base',
                        'bg-success/10' => $reportData['net_income'] >= 0,
                        'bg-error/10'   => $reportData['net_income'] < 0,
                    ])>
                        <span>NET {{ $reportData['net_income'] >= 0 ? 'INCOME' : 'LOSS' }}</span>
                        <span @class([
                            'font-mono',
                            'text-success' => $reportData['net_income'] >= 0,
                            'text-error'   => $reportData['net_income'] < 0,
                        ])>
                            {{ $currency }} {{ number_format(abs($reportData['net_income']), 2) }}
                        </span>
                    </div>
                </div>
            </div>
        @endif

        {{-- ── Cash Flow Statement ────────────────────────────────────────── --}}
        @if($reportType === 'cash_flow' && isset($reportData['net_change']))
            <div class="space-y-3">
                <div class="stats shadow w-full stats-vertical sm:stats-horizontal mb-2">
                    <x-tallui-stat title="Opening Cash Balance" value="{{ $currency }} {{ number_format($reportData['opening_cash_balance'] ?? 0, 2) }}" icon="o-banknotes" />
                    <x-tallui-stat title="Closing Cash Balance" value="{{ $currency }} {{ number_format($reportData['closing_cash_balance'] ?? 0, 2) }}" icon="o-scale" />
                </div>

                @foreach([
                    ['key' => 'operating_activities', 'label' => 'Operating Activities',  'icon' => 'o-cog-6-tooth',    'color' => 'info',      'breakdown' => 'operating_breakdown'],
                    ['key' => 'investing_activities',  'label' => 'Investing Activities',  'icon' => 'o-building-office', 'color' => 'secondary', 'breakdown' => 'investing_breakdown'],
                    ['key' => 'financing_activities',  'label' => 'Financing Activities',  'icon' => 'o-banknotes',       'color' => 'accent',    'breakdown' => 'financing_breakdown'],
                ] as $row)
                    @php $val = $reportData[$row['key']] ?? 0; @endphp
                    <div class="rounded-xl bg-base-200/50 hover:bg-base-200 transition-colors">
                        <div class="flex items-center justify-between p-4">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-lg bg-{{ $row['color'] }}/10 flex items-center justify-center">
                                    <x-tallui-icon :name="$row['icon']" class="w-4 h-4 text-{{ $row['color'] }}" />
                                </div>
                                <span class="font-medium text-sm">{{ $row['label'] }}</span>
                            </div>
                            <span @class([
                                'font-mono font-bold text-sm',
                                'text-success' => $val >= 0,
                                'text-error'   => $val < 0,
                            ])>
                                {{ $val >= 0 ? '' : '-' }}{{ $currency }} {{ number_format(abs($val), 2) }}
                            </span>
                        </div>

                        @php
                            $breakdown = $row['key'] === 'operating_activities'
                                ? ($reportData['operating_breakdown']['changes_in_working_capital'] ?? [])
                                : ($reportData[$row['breakdown']] ?? []);
                            $netIncome = $row['key'] === 'operating_activities' ? ($reportData['operating_breakdown']['net_income'] ?? null) : null;
                        @endphp
                        @if($netIncome !== null || !empty($breakdown))
                            <div class="px-4 pb-4 pl-16 space-y-1">
                                @if($netIncome !== null)
                                    <div class="flex items-center justify-between text-xs text-base-content/60">
                                        <span>Net Income</span>
                                        <span class="font-mono">{{ $currency }} {{ number_format($netIncome, 2) }}</span>
                                    </div>
                                @endif
                                @foreach($breakdown as $item)
                                    <div class="flex items-center justify-between text-xs text-base-content/60">
                                        <span>{{ $item['name'] }} ({{ $item['code'] }})</span>
                                        <span class="font-mono">{{ $item['amount'] >= 0 ? '' : '-' }}{{ $currency }} {{ number_format(abs($item['amount']), 2) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach

                <div class="border-t-2 border-base-300 pt-3">
                    @php $net = $reportData['net_change'] ?? 0; @endphp
                    <div @class([
                        'flex items-center justify-between p-4 rounded-xl font-bold text-base',
                        'bg-success/10' => $net >= 0,
                        'bg-error/10'   => $net < 0,
                    ])>
                        <div class="flex items-center gap-3">
                            <x-tallui-icon
                                :name="$net >= 0 ? 'o-arrow-trending-up' : 'o-arrow-trending-down'"
                                :class="'w-5 h-5 ' . ($net >= 0 ? 'text-success' : 'text-error')"
                            />
                            <span>Net Change in Cash</span>
                        </div>
                        <span @class([
                            'font-mono',
                            'text-success' => $net >= 0,
                            'text-error'   => $net < 0,
                        ])>
                            {{ $net >= 0 ? '' : '-' }}{{ $currency }} {{ number_format(abs($net), 2) }}
                        </span>
                    </div>
                </div>
            </div>
        @endif

        {{-- ── Cash Flow Forecast ─────────────────────────────────────────── --}}
        @if($reportType === 'cash_flow_forecast' && isset($reportData['buckets']))
            <div class="space-y-4">
                <div class="stats shadow w-full stats-vertical sm:stats-horizontal">
                    <x-tallui-stat title="Starting Cash" value="{{ $currency }} {{ number_format($reportData['starting_cash_balance'], 2) }}" icon="o-banknotes" />
                    <x-tallui-stat title="Projected Balance ({{ $reportData['forecast_weeks'] }}w)" value="{{ $currency }} {{ number_format($reportData['ending_projected_balance'], 2) }}" icon="o-flag" />
                    <x-tallui-stat title="Overdue Receivables" value="{{ $currency }} {{ number_format($reportData['overdue']['ar'], 2) }}" icon="o-exclamation-triangle" icon-color="text-warning" />
                    <x-tallui-stat title="Overdue Payables" value="{{ $currency }} {{ number_format($reportData['overdue']['ap'] + $reportData['overdue']['expenses'], 2) }}" icon="o-exclamation-triangle" icon-color="text-error" desc="Bills + credit expenses" />
                </div>

                <x-tallui-alert type="info">
                    Baseline "other" cash flow of {{ $currency }} {{ number_format($reportData['run_rate']['weekly'], 2) }}/week applied to every bucket below.
                    {{ $reportData['run_rate']['basis'] }}
                </x-tallui-alert>

                @if(($reportData['beyond_horizon']['ar'] ?? 0) != 0 || ($reportData['beyond_horizon']['ap'] ?? 0) != 0 || ($reportData['beyond_horizon']['expenses'] ?? 0) != 0)
                    <p class="text-xs text-base-content/50">
                        Not shown below (due beyond the {{ $reportData['forecast_weeks'] }}-week horizon):
                        {{ $currency }} {{ number_format($reportData['beyond_horizon']['ar'], 2) }} receivable,
                        {{ $currency }} {{ number_format($reportData['beyond_horizon']['ap'], 2) }} payable,
                        {{ $currency }} {{ number_format($reportData['beyond_horizon']['expenses'], 2) }} credit expenses.
                    </p>
                @endif

                <div class="overflow-x-auto">
                    <table class="table table-sm w-full">
                        <thead>
                            <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                                <th class="py-3">Week</th>
                                <th class="text-right">Expected Inflows</th>
                                <th class="text-right">Expected Outflows</th>
                                <th class="text-right">Other (Run-Rate)</th>
                                <th class="text-right">Net</th>
                                <th class="text-right">Projected Balance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-base-200">
                            @foreach($reportData['buckets'] as $bucket)
                                <tr class="even:bg-base-200/50 hover:bg-base-200">
                                    <td class="text-sm whitespace-nowrap">{{ $bucket['label'] }}</td>
                                    <td class="text-right text-sm font-mono text-success">{{ $currency }} {{ number_format($bucket['expected_inflows'], 2) }}</td>
                                    <td class="text-right text-sm font-mono text-error">{{ $currency }} {{ number_format($bucket['expected_outflows'], 2) }}</td>
                                    <td class="text-right text-sm font-mono text-base-content/60">{{ $bucket['baseline_other'] >= 0 ? '' : '-' }}{{ $currency }} {{ number_format(abs($bucket['baseline_other']), 2) }}</td>
                                    <td @class(['text-right text-sm font-mono font-semibold', 'text-success' => $bucket['net'] >= 0, 'text-error' => $bucket['net'] < 0])>
                                        {{ $bucket['net'] >= 0 ? '' : '-' }}{{ $currency }} {{ number_format(abs($bucket['net']), 2) }}
                                    </td>
                                    <td @class(['text-right text-sm font-mono font-bold', 'text-error' => $bucket['projected_balance'] < 0])>
                                        {{ $currency }} {{ number_format($bucket['projected_balance'], 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if(!empty($reportData['ar_schedule']) || !empty($reportData['ap_schedule']) || !empty($reportData['expense_schedule']))
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                        <div>
                            <h4 class="text-sm font-semibold mb-2">Outstanding Invoices (Inflows)</h4>
                            <div class="overflow-x-auto">
                                <table class="table table-xs w-full">
                                    <thead>
                                        <tr class="text-xs text-base-content/60 uppercase">
                                            <th>Invoice</th>
                                            <th>Customer</th>
                                            <th>Due</th>
                                            <th class="text-right">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-base-200">
                                        @forelse($reportData['ar_schedule'] as $item)
                                            <tr>
                                                <td class="font-mono text-primary">{{ $item['invoice_number'] }}</td>
                                                <td>{{ $item['customer'] ?? '—' }}</td>
                                                <td @class(['whitespace-nowrap', 'text-warning' => $item['bucket'] === 'overdue'])>
                                                    {{ \Illuminate\Support\Carbon::parse($item['due_date'])->format('M j, Y') }}
                                                    @if($item['bucket'] === 'overdue') (overdue) @endif
                                                </td>
                                                <td class="text-right font-mono">{{ $currency }} {{ number_format($item['amount'], 2) }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="4" class="py-4 text-center text-base-content/40">No outstanding invoices.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div>
                            <h4 class="text-sm font-semibold mb-2">Outstanding Bills (Outflows)</h4>
                            <div class="overflow-x-auto">
                                <table class="table table-xs w-full">
                                    <thead>
                                        <tr class="text-xs text-base-content/60 uppercase">
                                            <th>Bill</th>
                                            <th>Vendor</th>
                                            <th>Due</th>
                                            <th class="text-right">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-base-200">
                                        @forelse($reportData['ap_schedule'] as $item)
                                            <tr>
                                                <td class="font-mono text-primary">{{ $item['bill_number'] }}</td>
                                                <td>{{ $item['vendor'] ?? '—' }}</td>
                                                <td @class(['whitespace-nowrap', 'text-warning' => $item['bucket'] === 'overdue'])>
                                                    {{ \Illuminate\Support\Carbon::parse($item['due_date'])->format('M j, Y') }}
                                                    @if($item['bucket'] === 'overdue') (overdue) @endif
                                                </td>
                                                <td class="text-right font-mono">{{ $currency }} {{ number_format($item['amount'], 2) }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="4" class="py-4 text-center text-base-content/40">No outstanding bills.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div>
                            <h4 class="text-sm font-semibold mb-2">Outstanding Credit Expenses (Outflows)</h4>
                            <div class="overflow-x-auto">
                                <table class="table table-xs w-full">
                                    <thead>
                                        <tr class="text-xs text-base-content/60 uppercase">
                                            <th>Expense</th>
                                            <th>Vendor</th>
                                            <th>Due</th>
                                            <th class="text-right">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-base-200">
                                        @forelse($reportData['expense_schedule'] as $item)
                                            <tr>
                                                <td class="font-mono text-primary">{{ $item['expense_number'] }}</td>
                                                <td>{{ $item['vendor'] ?? '—' }}</td>
                                                <td @class(['whitespace-nowrap', 'text-warning' => $item['bucket'] === 'overdue'])>
                                                    {{ \Illuminate\Support\Carbon::parse($item['due_date'])->format('M j, Y') }}
                                                    @if($item['bucket'] === 'overdue') (overdue) @endif
                                                </td>
                                                <td class="text-right font-mono">{{ $currency }} {{ number_format($item['amount'], 2) }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="4" class="py-4 text-center text-base-content/40">No outstanding credit expenses.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        {{-- ── Cash Book ──────────────────────────────────────────────────── --}}
        @if($reportType === 'cash_book' && isset($reportData['entries']))
            <div class="space-y-4">
                <div class="stats shadow w-full stats-vertical sm:stats-horizontal">
                    <x-tallui-stat title="Opening Balance" value="{{ $currency }} {{ number_format($reportData['opening_balance'], 2) }}" icon="o-banknotes" />
                    <x-tallui-stat title="Total Receipts" value="{{ $currency }} {{ number_format($reportData['total_receipts'], 2) }}" icon="o-arrow-down-circle" icon-color="text-success" />
                    <x-tallui-stat title="Total Payments" value="{{ $currency }} {{ number_format($reportData['total_payments'], 2) }}" icon="o-arrow-up-circle" icon-color="text-error" />
                    <x-tallui-stat title="Closing Balance" value="{{ $currency }} {{ number_format($reportData['closing_balance'], 2) }}" icon="o-scale" />
                </div>

                @if(count($reportData['accounts']) > 1)
                    <p class="text-xs text-base-content/50">
                        Combined across:
                        {{ collect($reportData['accounts'])->map(fn ($a) => $a['name'] . ' (' . $a['code'] . ')')->implode(', ') }}
                    </p>
                @endif

                <div class="overflow-x-auto">
                    <table class="table table-sm w-full">
                        <thead>
                            <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                                <th class="py-3">Reference</th>
                                <th>Description</th>
                                @if(count($reportData['accounts']) > 1)
                                    <th>Account</th>
                                @endif
                                <th class="text-right">Receipt</th>
                                <th class="text-right">Payment</th>
                                <th class="text-right">Balance</th>
                            </tr>
                        </thead>
                        @php $cashBookCols = count($reportData['accounts']) > 1 ? 6 : 5; @endphp
                        <tbody class="divide-y divide-base-200">
                            @forelse(collect($reportData['entries'])->groupBy(fn ($e) => \Illuminate\Support\Carbon::parse($e['date'])->toDateString()) as $day => $dayEntries)
                                <tr class="bg-base-300/40">
                                    <td colspan="{{ $cashBookCols }}" class="py-1.5 text-xs font-semibold text-base-content/70">
                                        {{ \Illuminate\Support\Carbon::parse($day)->format('l, M j, Y') }}
                                        <span class="font-normal text-base-content/40">— {{ $dayEntries->count() }} {{ $dayEntries->count() === 1 ? 'transaction' : 'transactions' }}</span>
                                    </td>
                                </tr>
                                @foreach($dayEntries as $entry)
                                    <tr class="even:bg-base-200/50 hover:bg-base-200">
                                        <td class="text-sm font-mono text-primary">{{ $entry['entry_number'] ?? $entry['reference'] }}</td>
                                        <td class="text-sm">{{ $entry['description'] }}</td>
                                        @if(count($reportData['accounts']) > 1)
                                            <td class="text-sm text-base-content/60">{{ $entry['account_label'] }}</td>
                                        @endif
                                        <td class="text-right text-sm font-mono">
                                            @if($entry['receipt'] > 0)
                                                <span class="text-success">{{ $currency }} {{ number_format($entry['receipt'], 2) }}</span>
                                            @else
                                                <span class="text-base-content/30">—</span>
                                            @endif
                                        </td>
                                        <td class="text-right text-sm font-mono">
                                            @if($entry['payment'] > 0)
                                                <span class="text-error">{{ $currency }} {{ number_format($entry['payment'], 2) }}</span>
                                            @else
                                                <span class="text-base-content/30">—</span>
                                            @endif
                                        </td>
                                        <td class="text-right text-sm font-mono font-semibold">{{ $currency }} {{ number_format($entry['running_balance'], 2) }}</td>
                                    </tr>
                                @endforeach
                                <tr class="border-t border-base-300 bg-base-100">
                                    <td class="text-xs text-base-content/50 italic" colspan="{{ count($reportData['accounts']) > 1 ? 3 : 2 }}">Day total</td>
                                    <td class="text-right text-xs font-mono text-success">{{ $currency }} {{ number_format($dayEntries->sum('receipt'), 2) }}</td>
                                    <td class="text-right text-xs font-mono text-error">{{ $currency }} {{ number_format($dayEntries->sum('payment'), 2) }}</td>
                                    <td class="text-right text-xs font-mono font-semibold">{{ $currency }} {{ number_format($dayEntries->last()['running_balance'], 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $cashBookCols }}" class="py-8 text-center text-sm text-base-content/40">
                                        No cash/bank transactions in this period.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- ── Sales Tax Liability ────────────────────────────────────────── --}}
        @if($reportType === 'sales_tax_liability' && isset($reportData['rows']))
            <div class="overflow-x-auto">
                <table class="table table-sm w-full">
                    <thead>
                        <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                            <th class="py-3">Tax Rate</th>
                            <th>Code</th>
                            <th class="text-right">Rate</th>
                            <th class="text-right">Collected (Output)</th>
                            <th class="text-right">Paid (Input)</th>
                            <th class="text-right">Net Payable</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-base-200">
                        @foreach($reportData['rows'] as $row)
                            <tr class="even:bg-base-200/50 hover:bg-base-200">
                                <td class="text-sm">{{ $row['name'] }}</td>
                                <td class="font-mono text-sm text-primary">{{ $row['code'] ?? '—' }}</td>
                                <td class="text-right text-sm font-mono">{{ $row['rate'] !== null ? number_format($row['rate'], 2) . '%' : '—' }}</td>
                                <td class="text-right text-sm font-mono">{{ $currency }} {{ number_format($row['collected'], 2) }}</td>
                                <td class="text-right text-sm font-mono">{{ $currency }} {{ number_format($row['paid'], 2) }}</td>
                                <td class="text-right text-sm font-mono font-semibold">{{ $currency }} {{ number_format($row['net_payable'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-base-300 font-bold bg-base-200/50">
                            <td colspan="3" class="py-3 text-sm">TOTAL</td>
                            <td class="text-right text-sm font-mono">{{ $currency }} {{ number_format($reportData['total_collected'], 2) }}</td>
                            <td class="text-right text-sm font-mono">{{ $currency }} {{ number_format($reportData['total_paid'], 2) }}</td>
                            <td class="text-right text-sm font-mono">{{ $currency }} {{ number_format($reportData['total_net_payable'], 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif

    </x-tallui-card>
@else
    <x-tallui-card>
        <x-tallui-empty-state
            title="No report generated"
            description="Select a report type and date range above, then click Generate."
            icon="o-chart-bar-square"
            size="md"
        />
    </x-tallui-card>
@endif

</div>
