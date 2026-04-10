<div class="space-y-6">
<x-tallui-notification />

<x-tallui-page-header
    title="Financial Reports"
    subtitle="Generate trial balance, balance sheet, P&L and cash flow statements"
    icon="o-chart-bar-square"
/>

{{-- ── Report Configuration ─────────────────────────────────────────────── --}}
<x-tallui-card padding="compact">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 p-1">
        <x-tallui-form-group label="Report Type">
            <x-tallui-select wire:model.live="reportType" class="select-sm">
                <option value="trial_balance">Trial Balance</option>
                <option value="balance_sheet">Balance Sheet</option>
                <option value="income_statement">Income Statement (P&L)</option>
                <option value="cash_flow">Cash Flow Statement</option>
            </x-tallui-select>
        </x-tallui-form-group>

        <x-tallui-form-group label="Start Date">
            <x-tallui-input type="date" wire:model="startDate" class="input-sm" />
        </x-tallui-form-group>

        <x-tallui-form-group label="End Date">
            <x-tallui-input type="date" wire:model="endDate" class="input-sm" />
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
                @if($reportType === 'trial_balance')    Trial Balance
                @elseif($reportType === 'balance_sheet') Balance Sheet
                @elseif($reportType === 'income_statement') Income Statement
                @elseif($reportType === 'cash_flow')   Cash Flow Statement
                @endif
            </h3>
            <p class="text-sm text-base-content/50 mt-0.5">
                @if($reportType === 'balance_sheet')
                    As of {{ \Carbon\Carbon::parse($endDate)->format('F d, Y') }}
                @else
                    {{ \Carbon\Carbon::parse($startDate)->format('M d, Y') }} – {{ \Carbon\Carbon::parse($endDate)->format('M d, Y') }}
                @endif
            </p>
        </div>

        {{-- ── Trial Balance ─────────────────────────────────────────────── --}}
        @if($reportType === 'trial_balance' && isset($reportData['accounts']))
            <div class="overflow-x-auto">
                <table class="table table-sm w-full">
                    <thead>
                        <tr class="text-xs text-base-content/50 uppercase border-b border-base-200">
                            <th class="py-3">Code</th>
                            <th>Account Name</th>
                            <th class="text-right">Debit</th>
                            <th class="text-right">Credit</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-base-200">
                        @foreach($reportData['accounts'] as $item)
                            <tr class="hover:bg-base-200/50">
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

                {{-- Expenses --}}
                <div>
                    <div class="flex items-center gap-2 border-b border-base-300 pb-2 mb-3">
                        <span class="text-xs font-bold tracking-widest uppercase text-error">EXPENSES</span>
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
                            <span class="text-sm">Total Expenses</span>
                            <span class="text-sm font-mono">{{ $currency }} {{ number_format($reportData['expenses']['total'], 2) }}</span>
                        </div>
                    </div>
                </div>

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
                @foreach([
                    ['key' => 'operating_activities', 'label' => 'Operating Activities',  'icon' => 'o-cog-6-tooth',    'color' => 'info'],
                    ['key' => 'investing_activities',  'label' => 'Investing Activities',  'icon' => 'o-building-office', 'color' => 'secondary'],
                    ['key' => 'financing_activities',  'label' => 'Financing Activities',  'icon' => 'o-banknotes',       'color' => 'accent'],
                ] as $row)
                    @php $val = $reportData[$row['key']] ?? 0; @endphp
                    <div class="flex items-center justify-between p-4 rounded-xl bg-base-200/50 hover:bg-base-200 transition-colors">
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
