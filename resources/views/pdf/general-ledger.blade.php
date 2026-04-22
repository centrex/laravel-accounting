<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>General Ledger</title>
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
            margin: 22px 0 8px;
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
        .summary {
            margin: 8px 0 10px;
        }
        .summary span {
            display: inline-block;
            margin-right: 16px;
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
        .muted {
            color: #6b7280;
        }
        .totals td {
            font-weight: bold;
            background: #f9fafb;
        }
        .empty {
            padding: 18px 8px;
            color: #6b7280;
            text-align: center;
        }
    </style>
</head>
<body>
    <h1>General Ledger</h1>
    <div class="meta">
        Generated {{ $generatedAt->format('F d, Y h:i A') }}
    </div>

    <div class="filters">
        <span><strong>Account:</strong> {{ $selectedAccount ? $selectedAccount->code . ' - ' . $selectedAccount->name : 'All active accounts' }}</span>
        <span><strong>Start:</strong> {{ $ledgerData['period']['start'] ?: 'Beginning' }}</span>
        <span><strong>End:</strong> {{ $ledgerData['period']['end'] ?: 'Today' }}</span>
        <span><strong>SBU:</strong> {{ $selectedSbuCode ?: 'All SBUs' }}</span>
        <span><strong>Sections:</strong> {{ count($ledgerData['accounts'] ?? []) }}</span>
    </div>

    @forelse($ledgerData['accounts'] as $section)
        @php $account = $section['account']; @endphp
        <h2>{{ $account->code }} - {{ $account->name }}</h2>
        <div class="summary">
            <span><strong>Opening:</strong> {{ $currency }} {{ number_format($section['opening_balance'], 2) }}</span>
            <span><strong>Debits:</strong> {{ $currency }} {{ number_format($section['period_debits'], 2) }}</span>
            <span><strong>Credits:</strong> {{ $currency }} {{ number_format($section['period_credits'], 2) }}</span>
            <span><strong>Closing:</strong> {{ $currency }} {{ number_format($section['closing_balance'], 2) }}</span>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width: 12%;">Date</th>
                    <th style="width: 14%;">Entry</th>
                    <th style="width: 14%;">Reference</th>
                    <th>Description</th>
                    <th style="width: 12%;" class="text-right">Debit</th>
                    <th style="width: 12%;" class="text-right">Credit</th>
                    <th style="width: 14%;" class="text-right">Running</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td colspan="6"><strong>Opening Balance</strong></td>
                    <td class="text-right mono">{{ $currency }} {{ number_format($section['opening_balance'], 2) }}</td>
                </tr>
                @forelse($section['entries'] as $entry)
                    <tr>
                        <td>{{ \Carbon\Carbon::parse($entry['date'])->format('M d, Y') }}</td>
                        <td class="mono">{{ $entry['entry_number'] }}</td>
                        <td>{{ $entry['reference'] ?: '—' }}</td>
                        <td>{{ $entry['line_description'] ?: $entry['journal_description'] ?: '—' }}</td>
                        <td class="text-right mono">{{ $entry['debit'] > 0 ? number_format($entry['debit'], 2) : '—' }}</td>
                        <td class="text-right mono">{{ $entry['credit'] > 0 ? number_format($entry['credit'], 2) : '—' }}</td>
                        <td class="text-right mono">{{ number_format($entry['running_balance'], 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="empty">No posted ledger activity for this period.</td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="totals">
                    <td colspan="4">Period Totals</td>
                    <td class="text-right mono">{{ number_format($section['period_debits'], 2) }}</td>
                    <td class="text-right mono">{{ number_format($section['period_credits'], 2) }}</td>
                    <td class="text-right mono">{{ number_format($section['closing_balance'], 2) }}</td>
                </tr>
            </tfoot>
        </table>
    @empty
        <div class="empty">No ledger data found for the selected filters.</div>
    @endforelse
</body>
</html>
