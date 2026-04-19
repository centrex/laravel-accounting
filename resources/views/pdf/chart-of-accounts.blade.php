<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Chart of Accounts</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1f2937;
            font-size: 12px;
            margin: 28px;
        }
        h1 {
            margin: 0 0 6px;
            font-size: 24px;
        }
        .meta {
            margin-bottom: 18px;
            color: #6b7280;
            font-size: 11px;
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
        }
        th, td {
            border-bottom: 1px solid #e5e7eb;
            padding: 8px 10px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background: #f3f4f6;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #4b5563;
        }
        .mono {
            font-family: DejaVu Sans Mono, monospace;
        }
        .muted {
            color: #6b7280;
        }
    </style>
</head>
<body>
    <h1>Chart of Accounts</h1>
    <div class="meta">
        Generated {{ $generatedAt->format('F d, Y h:i A') }}
    </div>

    <div class="filters">
        <span><strong>Search:</strong> {{ $search ?: 'All accounts' }}</span>
        <span><strong>Type:</strong> {{ $typeFilter ? ucfirst($typeFilter) : 'All types' }}</span>
        <span><strong>Total:</strong> {{ $accounts->count() }} account(s)</span>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 12%;">Code</th>
                <th style="width: 26%;">Name</th>
                <th style="width: 14%;">Type</th>
                <th style="width: 18%;">Subtype</th>
                <th style="width: 10%;">Currency</th>
                <th style="width: 10%;">Status</th>
                <th style="width: 10%;">Parent</th>
            </tr>
        </thead>
        <tbody>
            @forelse($accounts as $account)
                <tr>
                    <td class="mono">{{ $account->code }}</td>
                    <td>
                        <div>{{ $account->name }}</div>
                        @if($account->description)
                            <div class="muted">{{ $account->description }}</div>
                        @endif
                    </td>
                    <td>{{ ucfirst($account->type->value ?? $account->type) }}</td>
                    <td>{{ $account->subtype ? ucwords(str_replace('_', ' ', $account->subtype->value ?? $account->subtype)) : '—' }}</td>
                    <td>{{ $account->currency }}</td>
                    <td>{{ $account->is_active ? 'Active' : 'Inactive' }}</td>
                    <td class="mono">{{ $account->parent?->code ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="muted">No accounts matched the current filters.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
