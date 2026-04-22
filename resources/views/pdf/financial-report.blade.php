<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $reportTitle }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1f2937;
            font-size: 11px;
            margin: 24px;
        }
        h1 {
            margin: 0 0 6px;
            font-size: 24px;
        }
        h2 {
            margin: 20px 0 8px;
            font-size: 15px;
        }
        .meta {
            margin-bottom: 14px;
            color: #6b7280;
            font-size: 10px;
        }
        .filters {
            margin: 12px 0 18px;
            padding: 10px 12px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }
        .filters span {
            margin-right: 18px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }
        th, td {
            border-bottom: 1px solid #e5e7eb;
            padding: 6px 8px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background: #f3f4f6;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #4b5563;
        }
        .mono {
            font-family: DejaVu Sans Mono, monospace;
        }
        .text-right {
            text-align: right;
        }
        .totals td {
            font-weight: bold;
            background: #f9fafb;
        }
        .section-total {
            margin: 6px 0 18px;
            text-align: right;
            font-weight: bold;
        }
        .status {
            margin-top: 6px;
            font-size: 10px;
            color: #6b7280;
        }
        .empty {
            padding: 18px 8px;
            color: #6b7280;
            text-align: center;
        }
    </style>
</head>
<body>
    <h1>{{ $reportTitle }}</h1>
    <div class="meta">
        Generated {{ $generatedAt->format('F d, Y h:i A') }}
    </div>

    <div class="filters">
        @if($reportType === 'balance_sheet')
            <span><strong>Date:</strong> {{ \Carbon\Carbon::parse($endDate)->format('F d, Y') }}</span>
        @else
            <span><strong>Start:</strong> {{ \Carbon\Carbon::parse($startDate)->format('F d, Y') }}</span>
            <span><strong>End:</strong> {{ \Carbon\Carbon::parse($endDate)->format('F d, Y') }}</span>
        @endif
        <span><strong>SBU:</strong> {{ $sbuCode ?: 'All SBUs' }}</span>
    </div>

    @if($reportType === 'trial_balance' && isset($reportData['accounts']))
        <table>
            <thead>
                <tr>
                    <th style="width: 14%;">Code</th>
                    <th>Account Name</th>
                    <th style="width: 18%;" class="text-right">Debit</th>
                    <th style="width: 18%;" class="text-right">Credit</th>
                </tr>
            </thead>
            <tbody>
                @forelse($reportData['accounts'] as $item)
                    <tr>
                        <td class="mono">{{ $item['account']->code }}</td>
                        <td>{{ $item['account']->name }}</td>
                        <td class="text-right mono">{{ $item['debit'] > 0 ? number_format($item['debit'], 2) : '—' }}</td>
                        <td class="text-right mono">{{ $item['credit'] > 0 ? number_format($item['credit'], 2) : '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="empty">No trial balance rows matched the selected filters.</td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="totals">
                    <td colspan="2">Total</td>
                    <td class="text-right mono">{{ number_format($reportData['total_debits'] ?? 0, 2) }}</td>
                    <td class="text-right mono">{{ number_format($reportData['total_credits'] ?? 0, 2) }}</td>
                </tr>
            </tfoot>
        </table>
        <div class="status">Status: {{ ($reportData['is_balanced'] ?? false) ? 'Balanced' : 'Not Balanced' }}</div>
    @endif

    @if($reportType === 'balance_sheet' && isset($reportData['assets']))
        @foreach([
            ['key' => 'assets', 'label' => 'Assets', 'total_key' => 'total'],
            ['key' => 'liabilities', 'label' => 'Liabilities', 'total_key' => 'total'],
            ['key' => 'equity', 'label' => 'Equity', 'total_key' => 'total_with_income'],
        ] as $section)
            <h2>{{ $section['label'] }}</h2>
            <table>
                <thead>
                    <tr>
                        <th style="width: 14%;">Code</th>
                        <th>Account Name</th>
                        <th style="width: 18%;" class="text-right">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($reportData[$section['key']]['accounts'] as $item)
                        <tr>
                            <td class="mono">{{ $item['account']->code }}</td>
                            <td>{{ $item['account']->name }}</td>
                            <td class="text-right mono">{{ number_format($item['balance'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="empty">No {{ strtolower($section['label']) }} rows matched the selected filters.</td>
                        </tr>
                    @endforelse

                    @if($section['key'] === 'equity' && isset($reportData['equity']['net_income']))
                        <tr>
                            <td class="mono">—</td>
                            <td>Net Income (Current Period)</td>
                            <td class="text-right mono">{{ number_format($reportData['equity']['net_income'], 2) }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>
            <div class="section-total">
                Total {{ $section['label'] }}: {{ $currency }} {{ number_format($reportData[$section['key']][$section['total_key']] ?? 0, 2) }}
            </div>
        @endforeach

        <div class="status">
            Status: {{ ($reportData['is_balanced'] ?? false) ? 'Balanced' : 'Not Balanced' }}
        </div>
    @endif

    @if($reportType === 'income_statement' && isset($reportData['revenue']))
        @foreach([
            ['key' => 'revenue', 'label' => 'Revenue'],
            ['key' => 'expenses', 'label' => 'Expenses'],
        ] as $section)
            <h2>{{ $section['label'] }}</h2>
            <table>
                <thead>
                    <tr>
                        <th style="width: 14%;">Code</th>
                        <th>Account Name</th>
                        <th style="width: 18%;" class="text-right">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($reportData[$section['key']]['accounts'] as $item)
                        <tr>
                            <td class="mono">{{ $item['account']->code }}</td>
                            <td>{{ $item['account']->name }}</td>
                            <td class="text-right mono">{{ number_format($item['balance'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="empty">No {{ strtolower($section['label']) }} rows matched the selected filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="section-total">
                Total {{ $section['label'] }}: {{ $currency }} {{ number_format($reportData[$section['key']]['total'] ?? 0, 2) }}
            </div>
        @endforeach

        <div class="section-total">
            Net {{ ($reportData['net_income'] ?? 0) >= 0 ? 'Income' : 'Loss' }}:
            {{ $currency }} {{ number_format(abs($reportData['net_income'] ?? 0), 2) }}
        </div>
    @endif

    @if($reportType === 'cash_flow' && isset($reportData['net_change']))
        <table>
            <thead>
                <tr>
                    <th>Section</th>
                    <th style="width: 22%;" class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Operating Activities</td>
                    <td class="text-right mono">{{ number_format($reportData['operating_activities'] ?? 0, 2) }}</td>
                </tr>
                <tr>
                    <td>Investing Activities</td>
                    <td class="text-right mono">{{ number_format($reportData['investing_activities'] ?? 0, 2) }}</td>
                </tr>
                <tr>
                    <td>Financing Activities</td>
                    <td class="text-right mono">{{ number_format($reportData['financing_activities'] ?? 0, 2) }}</td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="totals">
                    <td>Net Change</td>
                    <td class="text-right mono">{{ number_format($reportData['net_change'] ?? 0, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    @endif
</body>
</html>
