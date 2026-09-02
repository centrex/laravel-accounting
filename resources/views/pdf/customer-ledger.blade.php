<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Ledger — {{ $customer->name }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1f2937;
            font-size: 11px;
            margin: 24px;
        }
        h1 {
            margin: 0 0 6px;
            font-size: 22px;
        }
        h2 {
            margin: 0 0 4px;
            font-size: 15px;
        }
        .meta {
            margin-bottom: 14px;
            color: #6b7280;
            font-size: 10px;
        }
        .info {
            margin: 12px 0 18px;
            padding: 10px 12px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }
        .info span {
            display: inline-block;
            margin-right: 18px;
        }
        .summary {
            margin: 8px 0 16px;
            text-align: right;
        }
        .summary .label {
            color: #6b7280;
            font-size: 10px;
            text-transform: uppercase;
        }
        .summary .value {
            font-size: 16px;
            font-weight: bold;
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
    <h1>Customer Ledger — Statement of Account</h1>
    @if($customer->organization_name)
        <h2>{{ $customer->organization_name }}</h2>
    @endif
    <div class="meta">
        {{ $customer->name }} ({{ $customer->code }}) &middot; Generated {{ $generatedAt->format('F d, Y h:i A') }}
    </div>

    <div class="info">
        <span><strong>Email:</strong> {{ $customer->email ?? '—' }}</span>
        <span><strong>Phone:</strong> {{ $customer->phone ?? '—' }}</span>
        <span><strong>Period:</strong> {{ $startDate ?: 'Beginning' }} to {{ $endDate ?: 'Today' }}</span>
        <span><strong>Currency:</strong> {{ $customer->currency }}</span>
    </div>

    <div class="summary">
        <div class="label">Closing Balance</div>
        <div class="value">{{ $customer->currency }} {{ number_format($ledger['closing'], 2) }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 10%;">Date</th>
                <th style="width: 16%;">Reference</th>
                <th>Description</th>
                <th style="width: 14%;" class="text-right">Invoiced</th>
                <th style="width: 14%;" class="text-right">Received</th>
                <th style="width: 14%;" class="text-right">Balance</th>
            </tr>
        </thead>
        <tbody>
            @if($startDate !== '')
                <tr>
                    <td colspan="5"><strong>Opening Balance</strong></td>
                    <td class="text-right mono">{{ number_format($ledger['opening'], 2) }}</td>
                </tr>
            @endif
            @forelse($ledger['entries'] as $entry)
                <tr>
                    <td>{{ $entry['date']->format('M d, Y') }}</td>
                    <td class="mono">{{ $entry['reference'] }}</td>
                    <td>{{ $entry['description'] }}</td>
                    <td class="text-right mono">{{ $entry['debit'] > 0 ? number_format($entry['debit'], 2) : '—' }}</td>
                    <td class="text-right mono">{{ $entry['credit'] > 0 ? number_format($entry['credit'], 2) : '—' }}</td>
                    <td class="text-right mono">{{ number_format($entry['balance'], 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="empty">No transactions found in this period.</td>
                </tr>
            @endforelse
        </tbody>
        @if(count($ledger['entries']) > 0)
            <tfoot>
                <tr class="totals">
                    <td colspan="3">Period Totals</td>
                    <td class="text-right mono">{{ number_format($ledger['total_debit'], 2) }}</td>
                    <td class="text-right mono">{{ number_format($ledger['total_credit'], 2) }}</td>
                    <td class="text-right mono">{{ number_format($ledger['closing'], 2) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
</body>
</html>
