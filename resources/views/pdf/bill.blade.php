<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Bill {{ $bill->bill_number }}</title>
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
        .meta {
            margin-bottom: 14px;
            color: #6b7280;
            font-size: 10px;
        }
        .header {
            display: table;
            width: 100%;
            margin-bottom: 18px;
        }
        .header .col {
            display: table-cell;
            vertical-align: top;
        }
        .header .col.right {
            text-align: right;
        }
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 10px;
            background: #f3f4f6;
            color: #374151;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .section-title {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #6b7280;
            margin-bottom: 2px;
        }
        .info-grid {
            display: table;
            width: 100%;
            margin-bottom: 18px;
        }
        .info-grid .col {
            display: table-cell;
            width: 25%;
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
        .totals {
            width: 45%;
            margin-left: auto;
        }
        .totals td {
            border-bottom: none;
            padding: 4px 8px;
        }
        .totals .grand td {
            border-top: 1px solid #1f2937;
            font-weight: bold;
            padding-top: 8px;
        }
        .notes {
            margin-top: 18px;
            padding: 10px 12px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }
        .empty {
            padding: 18px 8px;
            color: #6b7280;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="col">
            <h1>Bill</h1>
            <div class="meta">Generated {{ $generatedAt->format('F d, Y h:i A') }}</div>
        </div>
        <div class="col right">
            <div class="mono" style="font-size: 14px;">{{ $bill->bill_number }}</div>
            <div class="badge">{{ str($bill->status->value ?? $bill->status)->replace('_', ' ')->title() }}</div>
        </div>
    </div>

    <div class="info-grid">
        <div class="col">
            <div class="section-title">Vendor</div>
            <div><strong>{{ $bill->vendor?->name ?? 'Unknown vendor' }}</strong></div>
            @if($bill->vendor?->email)
                <div class="muted">{{ $bill->vendor->email }}</div>
            @endif
            @if($bill->vendor?->phone)
                <div class="muted">{{ $bill->vendor->phone }}</div>
            @endif
            @if($bill->vendor?->address)
                <div class="muted">{{ $bill->vendor->address }}</div>
            @endif
        </div>
        <div class="col">
            <div class="section-title">Bill Date</div>
            <div>{{ $bill->bill_date?->format('M d, Y') }}</div>
        </div>
        <div class="col">
            <div class="section-title">Due Date</div>
            <div>{{ $bill->due_date?->format('M d, Y') }}</div>
        </div>
        <div class="col">
            <div class="section-title">Currency</div>
            <div>{{ $bill->currency }} @if((float) $bill->exchange_rate !== 1.0) <span class="muted">(rate {{ number_format((float) $bill->exchange_rate, 4) }})</span> @endif</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="text-right" style="width: 10%;">Qty</th>
                <th class="text-right" style="width: 16%;">Unit Price</th>
                <th class="text-right" style="width: 14%;">Tax</th>
                <th class="text-right" style="width: 16%;">Line Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse($bill->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="text-right mono">{{ number_format((float) $item->quantity, 2) }}</td>
                    <td class="text-right mono">{{ $bill->base_currency }} {{ number_format($bill->convertToBase($item->unit_price), 2) }}</td>
                    <td class="text-right mono">{{ $bill->base_currency }} {{ number_format($bill->convertToBase($item->tax_amount), 2) }}</td>
                    <td class="text-right mono">{{ $bill->base_currency }} {{ number_format($bill->convertToBase($item->total), 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="empty">No line items recorded.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>Subtotal</td>
            <td class="text-right mono">{{ $bill->base_currency }} {{ number_format((float) $bill->base_subtotal, 2) }}</td>
        </tr>
        <tr>
            <td>Tax</td>
            <td class="text-right mono">{{ $bill->base_currency }} {{ number_format((float) $bill->base_tax_amount, 2) }}</td>
        </tr>
        @if((float) $bill->discount_amount > 0)
            <tr>
                <td>Discount</td>
                <td class="text-right mono">-{{ $bill->base_currency }} {{ number_format((float) $bill->base_discount_amount, 2) }}</td>
            </tr>
        @endif
        @if((float) $bill->shipping_amount > 0)
            <tr>
                <td>Shipping</td>
                <td class="text-right mono">{{ $bill->base_currency }} {{ number_format((float) $bill->base_shipping_amount, 2) }}</td>
            </tr>
        @endif
        @if((float) $bill->other_charges_amount > 0)
            <tr>
                <td>Other Charges</td>
                <td class="text-right mono">{{ $bill->base_currency }} {{ number_format((float) $bill->base_other_charges_amount, 2) }}</td>
            </tr>
        @endif
        <tr class="grand">
            <td>Total</td>
            <td class="text-right mono">{{ $bill->base_currency }} {{ number_format((float) $bill->base_total, 2) }}</td>
        </tr>
        <tr>
            <td>Paid</td>
            <td class="text-right mono">{{ $bill->base_currency }} {{ number_format((float) $bill->base_paid_amount, 2) }}</td>
        </tr>
        <tr>
            <td><strong>Balance Due</strong></td>
            <td class="text-right mono"><strong>{{ $bill->base_currency }} {{ number_format((float) $bill->base_balance, 2) }}</strong></td>
        </tr>
    </table>

    @if($bill->notes)
        <div class="notes">
            <div class="section-title">Notes</div>
            <div>{{ $bill->notes }}</div>
        </div>
    @endif
</body>
</html>
