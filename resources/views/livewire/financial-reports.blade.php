<div class="financial-reports">
    <div class="mb-6">
        <h2 class="text-3xl font-bold text-gray-800">Financial Reports</h2>
    </div>

    @if (session()->has('error'))
        <div class="alert alert-error mb-4">{{ session('error') }}</div>
    @endif

    <!-- Report Configuration -->
    <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Report Type</label>
                <select wire:model="reportType" class="w-full px-3 py-2 border border-gray-300 rounded-md">
                    <option value="trial_balance">Trial Balance</option>
                    <option value="balance_sheet">Balance Sheet</option>
                    <option value="income_statement">Income Statement (P&L)</option>
                    <option value="cash_flow">Cash Flow Statement</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Start Date</label>
                <input type="date" wire:model="startDate" class="w-full px-3 py-2 border border-gray-300 rounded-md">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">End Date</label>
                <input type="date" wire:model="endDate" class="w-full px-3 py-2 border border-gray-300 rounded-md">
            </div>
            <div class="flex items-end">
                <button wire:click="generateReport" class="btn btn-primary w-full">
                    Generate Report
                </button>
            </div>
        </div>
    </div>

    <!-- Report Display -->
    @if($reportData)
        <div class="bg-white rounded-lg shadow-sm p-6">
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h3 class="text-2xl font-bold">
                        @if($reportType === 'trial_balance') Trial Balance
                        @elseif($reportType === 'balance_sheet') Balance Sheet
                        @elseif($reportType === 'income_statement') Income Statement
                        @elseif($reportType === 'cash_flow') Cash Flow Statement
                        @endif
                    </h3>
                    <p class="text-sm text-gray-600">
                        @if($reportType === 'balance_sheet')
                            As of {{ \Carbon\Carbon::parse($endDate)->format('F d, Y') }}
                        @else
                            From {{ \Carbon\Carbon::parse($startDate)->format('M d, Y') }} to {{ \Carbon\Carbon::parse($endDate)->format('M d, Y') }}
                        @endif
                    </p>
                </div>
                <button wire:click="exportPdf" class="btn btn-secondary">
                    <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Export PDF
                </button>
            </div>

            <!-- Trial Balance Report -->
            @if($reportType === 'trial_balance')
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Account Code</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Account Name</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Debit</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Credit</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($reportData['accounts'] as $item)
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">{{ $item['account']->code }}</td>
                                <td class="px-6 py-4 text-sm">{{ $item['account']->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                    @if($item['debit'] > 0) ${{ number_format($item['debit'], 2) }} @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                    @if($item['credit'] > 0) ${{ number_format($item['credit'], 2) }} @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-gray-50 font-bold">
                        <tr>
                            <td colspan="2" class="px-6 py-4 text-sm">TOTAL</td>
                            <td class="px-6 py-4 text-sm text-right">${{ number_format($reportData['total_debits'], 2) }}</td>
                            <td class="px-6 py-4 text-sm text-right">${{ number_format($reportData['total_credits'], 2) }}</td>
                        </tr>
                        <tr class="{{ $reportData['is_balanced'] ? 'text-green-600' : 'text-red-600' }}">
                            <td colspan="4" class="px-6 py-2 text-sm text-center">
                                {{ $reportData['is_balanced'] ? '✓ Balanced' : '✗ Not Balanced' }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            @endif

            <!-- Balance Sheet Report -->
            @if($reportType === 'balance_sheet')
                <div class="space-y-6">
                    <!-- Assets -->
                    <div>
                        <h4 class="text-lg font-bold text-gray-700 mb-3 border-b pb-2">ASSETS</h4>
                        @foreach($reportData['assets']['accounts'] as $item)
                            <div class="flex justify-between py-2 px-4">
                                <span class="text-sm">{{ $item['account']->code }} - {{ $item['account']->name }}</span>
                                <span class="text-sm font-medium">${{ number_format($item['balance'], 2) }}</span>
                            </div>
                        @endforeach
                        <div class="flex justify-between py-2 px-4 bg-gray-50 font-bold mt-2">
                            <span>Total Assets</span>
                            <span>${{ number_format($reportData['assets']['total'], 2) }}</span>
                        </div>
                    </div>

                    <!-- Liabilities -->
                    <div>
                        <h4 class="text-lg font-bold text-gray-700 mb-3 border-b pb-2">LIABILITIES</h4>
                        @foreach($reportData['liabilities']['accounts'] as $item)
                            <div class="flex justify-between py-2 px-4">
                                <span class="text-sm">{{ $item['account']->code }} - {{ $item['account']->name }}</span>
                                <span class="text-sm font-medium">${{ number_format($item['balance'], 2) }}</span>
                            </div>
                        @endforeach
                        <div class="flex justify-between py-2 px-4 bg-gray-50 font-bold mt-2">
                            <span>Total Liabilities</span>
                            <span>${{ number_format($reportData['liabilities']['total'], 2) }}</span>
                        </div>
                    </div>

                    <!-- Equity -->
                    <div>
                        <h4 class="text-lg font-bold text-gray-700 mb-3 border-b pb-2">EQUITY</h4>
                        @foreach($reportData['equity']['accounts'] as $item)
                            <div class="flex justify-between py-2 px-4">
                                <span class="text-sm">{{ $item['account']->code }} - {{ $item['account']->name }}</span>
                                <span class="text-sm font-medium">${{ number_format($item['balance'], 2) }}</span>
                            </div>
                        @endforeach
                        <div class="flex justify-between py-2 px-4">
                            <span class="text-sm">Net Income (Current Period)</span>
                            <span class="text-sm font-medium">${{ number_format($reportData['equity']['net_income'], 2) }}</span>
                        </div>
                        <div class="flex justify-between py-2 px-4 bg-gray-50 font-bold mt-2">
                            <span>Total Equity</span>
                            <span>${{ number_format($reportData['equity']['total_with_income'], 2) }}</span>
                        </div>
                    </div>

                    <!-- Totals -->
                    <div class="border-t-2 border-gray-800 pt-4">
                        <div class="flex justify-between py-2 px-4 bg-blue-50 font-bold text-lg">
                            <span>Total Liabilities & Equity</span>
                            <span>${{ number_format($reportData['liabilities']['total'] + $reportData['equity']['total_with_income'], 2) }}</span>
                        </div>
                        <div class="text-center mt-2 {{ $reportData['is_balanced'] ? 'text-green-600' : 'text-red-600' }}">
                            {{ $reportData['is_balanced'] ? '✓ Balance Sheet is Balanced' : '✗ Balance Sheet is Not Balanced' }}
                        </div>
                    </div>
                </div>
            @endif

            <!-- Income Statement Report -->
            @if($reportType === 'income_statement')
                <div class="space-y-6">
                    <!-- Revenue -->
                    <div>
                        <h4 class="text-lg font-bold text-gray-700 mb-3 border-b pb-2">REVENUE</h4>
                        @foreach($reportData['revenue']['accounts'] as $item)
                            <div class="flex justify-between py-2 px-4">
                                <span class="text-sm">{{ $item['account']->code }} - {{ $item['account']->name }}</span>
                                <span class="text-sm font-medium">${{ number_format($item['balance'], 2) }}</span>
                            </div>
                        @endforeach
                        <div class="flex justify-between py-2 px-4 bg-gray-50 font-bold mt-2">
                            <span>Total Revenue</span>
                            <span>${{ number_format($reportData['revenue']['total'], 2) }}</span>
                        </div>
                    </div>

                    <!-- Expenses -->
                    <div>
                        <h4 class="text-lg font-bold text-gray-700 mb-3 border-b pb-2">EXPENSES</h4>
                        @foreach($reportData['expenses']['accounts'] as $item)
                            <div class="flex justify-between py-2 px-4">
                                <span class="text-sm">{{ $item['account']->code }} - {{ $item['account']->name }}</span>
                                <span class="text-sm font-medium">${{ number_format($item['balance'], 2) }}</span>
                            </div>
                        @endforeach
                        <div class="flex justify-between py-2 px-4 bg-gray-50 font-bold mt-2">
                            <span>Total Expenses</span>
                            <span>${{ number_format($reportData['expenses']['total'], 2) }}</span>
                        </div>
                    </div>

                    <!-- Net Income -->
                    <div class="border-t-2 border-gray-800 pt-4">
                        <div class="flex justify-between py-3 px-4 bg-{{ $reportData['net_income'] >= 0 ? 'green' : 'red' }}-50 font-bold text-lg">
                            <span>NET {{ $reportData['net_income'] >= 0 ? 'INCOME' : 'LOSS' }}</span>
                            <span>${{ number_format(abs($reportData['net_income']), 2) }}</span>
                        </div>
                    </div>
                </div>
            @endif

            <!-- Cash Flow Statement Report -->
            @if($reportType === 'cash_flow')
                <div class="space-y-6">
                    <div class="flex justify-between py-3 px-4 bg-gray-100">
                        <span class="font-bold">Operating Activities</span>
                        <span class="font-bold">${{ number_format($reportData['operating_activities'], 2) }}</span>
                    </div>
                    
                    <div class="flex justify-between py-3 px-4 bg-gray-100">
                        <span class="font-bold">Investing Activities</span>
                        <span class="font-bold">${{ number_format($reportData['investing_activities'], 2) }}</span>
                    </div>
                    
                    <div class="flex justify-between py-3 px-4 bg-gray-100">
                        <span class="font-bold">Financing Activities</span>
                        <span class="font-bold">${{ number_format($reportData['financing_activities'], 2) }}</span>
                    </div>

                    <div class="border-t-2 border-gray-800 pt-4">
                        <div class="flex justify-between py-3 px-4 bg-blue-50 font-bold text-lg">
                            <span>Net Change in Cash</span>
                            <span>${{ number_format($reportData['net_change'], 2) }}</span>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @else
        <div class="bg-white rounded-lg shadow-sm p-12 text-center text-gray-500">
            <svg class="mx-auto h-16 w-16 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <p class="text-lg">Select report type and date range, then click "Generate Report"</p>
        </div>
    @endif
</div>

<style>
    .btn { padding: 0.5rem 1rem; border-radius: 0.375rem; font-weight: 500; transition: all 0.2s; display: inline-flex; align-items: center; }
    .btn-primary { background-color: #3b82f6; color: white; }
    .btn-primary:hover { background-color: #2563eb; }
    .btn-secondary { background-color: #6b7280; color: white; }
    .btn-secondary:hover { background-color: #4b5563; }
    .alert-error { padding: 1rem; border-radius: 0.375rem; background-color: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
</style>