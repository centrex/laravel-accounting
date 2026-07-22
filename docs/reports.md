# Financial Reports

All report methods are on the `Accounting` facade. All accept an optional `?string $sbuCode` parameter to filter by Strategic Business Unit.

```php
use Centrex\Accounting\Facades\Accounting;
```

---

## Trial Balance

Verifies that the accounting equation is in balance (total debits = total credits for all posted entries).

```php
$tb = Accounting::getTrialBalance(
    startDate: '2026-01-01',
    endDate:   '2026-04-30',
    sbuCode:   null,
);

// [
//   'accounts' => [
//     ['account' => Account, 'debit' => 125000.0, 'credit' => 0.0, 'balance' => 125000.0],
//     ...
//   ],
//   'total_debits'  => 1250000.00,
//   'total_credits' => 1250000.00,
//   'is_balanced'   => true,
// ]
```

---

## Balance Sheet

Point-in-time snapshot of assets, liabilities, and equity.

```php
$bs = Accounting::getBalanceSheet(
    date:    '2026-04-30',
    sbuCode: null,
);

// [
//   'assets'      => ['accounts' => [...], 'total' => 8500000.00],
//   'liabilities' => ['accounts' => [...], 'total' => 2300000.00],
//   'equity'      => [
//     'accounts'            => [...],
//     'total'               => 5870000.00,
//     'total_with_income'   => 6200000.00,  // includes current period net income
//   ],
//   'is_balanced' => true,
// ]
```

---

## Income Statement (P&L)

Revenue, expenses, and net income for a period.

```php
$pl = Accounting::getIncomeStatement(
    startDate: '2026-04-01',
    endDate:   '2026-04-30',
    sbuCode:   null,
);

// [
//   'revenue'      => ['accounts' => [...], 'total' => 5100000.00],
//   'expenses'     => ['accounts' => [...], 'total' => 3408000.00],
//   'gross_profit' => 1692000.00,
//   'net_income'   => 1692000.00,
// ]
```

---

## Cash Flow Statement

Categorises cash movements into operating, investing, and financing activities.

```php
$cf = Accounting::getCashFlowStatement(
    startDate: '2026-04-01',
    endDate:   '2026-04-30',
    sbuCode:   null,
);

// [
//   'operating_activities'  => 2800000.00,
//   'investing_activities'  => -500000.00,
//   'financing_activities'  => 0.00,
//   'net_change'            => 2300000.00,
// ]
```

---

## General Ledger

Per-account transaction list with opening balance, running balance per line, and closing balance.

```php
$gl = Accounting::getGeneralLedger(
    accountId: $bankAccountId,   // null = all accounts
    startDate: '2026-04-01',
    endDate:   '2026-04-30',
    sbuCode:   null,
);

foreach ($gl['accounts'] as $section) {
    $section['account'];           // Account model
    $section['opening_balance'];   // balance before startDate
    $section['period_debits'];
    $section['period_credits'];
    $section['closing_balance'];
    foreach ($section['entries'] as $line) {
        $line['line_id'];
        $line['entry_number'];
        $line['date'];
        $line['description'];
        $line['debit'];
        $line['credit'];
        $line['running_balance'];
    }
}
```

---

## SBU (Cost Centre) filtering

Tag journal entries with an SBU code and filter any report by that code:

```php
// Manual SBU on a journal entry
Accounting::createJournalEntry([
    'sbu_code' => 'NORTH',
    ...
]);

// SBU-filtered reports
$pl_north = Accounting::getIncomeStatement('2026-04-01', '2026-04-30', sbuCode: 'NORTH');
$bs_south = Accounting::getBalanceSheet('2026-04-30', sbuCode: 'SOUTH');
$tb_all   = Accounting::getTrialBalance('2026-04-01', '2026-04-30');
```

The SBU is auto-applied by the ERP bridge when inventory financing/loan entries are generated (each facility can carry its own `sbu_code`).

---

## A/R Aging

Receivables aging using QBO-compatible buckets: **current** (not yet due), **1–30**, **31–60**, **61–90**, **91+** days past due.

Included invoice statuses: `sent`, `issued`, `partially_settled`, `overdue`.

```php
$aging = Accounting::getArAging(
    asOfDate: '2026-04-30',  // null = today
    sbuCode:  null,
);

// [
//   'as_of_date' => '2026-04-30',
//   'sbu_code'   => null,
//   'rows' => [
//     [
//       'name'    => 'Acme Corp',
//       'current' => 5000.00,
//       '1_30'    => 1200.00,
//       '31_60'   => 0.00,
//       '61_90'   => 800.00,
//       'over_90' => 0.00,
//       'total'   => 7000.00,
//     ],
//   ],
//   'totals' => ['current' => 5000.00, '1_30' => 1200.00, '31_60' => 0.00, '61_90' => 800.00, 'over_90' => 0.00, 'total' => 7000.00],
// ]
```

Get the QBO-structured variant:

```php
use Centrex\Accounting\QuickBooks\QuickBooksReportFormatter;

$qboAging = app(QuickBooksReportFormatter::class)->arAging($aging);
```

Via REST API (`?format=qbo` for QBO structure):

```http
GET /api/accounting/reports/ar-aging?as_of_date=2026-04-30
GET /api/accounting/reports/ar-aging?as_of_date=2026-04-30&format=qbo&sbu_code=NORTH
```

---

## A/P Aging

Payables aging with the same buckets. Included bill statuses: `issued`, `partially_settled`, `overdue`.

```php
$aging = Accounting::getApAging(
    asOfDate: '2026-04-30',
    sbuCode:  null,
);
// Same structure as A/R Aging above (rows keyed by vendor name)

$qboAging = app(QuickBooksReportFormatter::class)->apAging($aging);
```

Via REST API:

```http
GET /api/accounting/reports/ap-aging?as_of_date=2026-04-30
GET /api/accounting/reports/ap-aging?as_of_date=2026-04-30&format=qbo
```

---

## Sales Tax Liability

Groups output tax collected on invoices against input tax paid on bills, by `TaxRate`, over a period. Lines with no linked `TaxRate` (the free-typed percentage fallback) are grouped into an "Unassigned / Ad-hoc" bucket. Draft and void invoices/bills are excluded.

```php
$report = Accounting::getSalesTaxLiabilityReport(
    startDate: '2026-04-01',
    endDate:   '2026-04-30',
    sbuCode:   null,
);

// [
//   'period' => ['start' => '2026-04-01', 'end' => '2026-04-30'],
//   'rows' => [
//     ['tax_rate_id' => 1, 'name' => 'VAT Standard', 'code' => 'VAT', 'rate' => 15.00,
//      'collected' => 33750.00, 'paid' => 4500.00, 'net_payable' => 29250.00],
//     ['tax_rate_id' => null, 'name' => 'Unassigned / Ad-hoc', 'code' => null, 'rate' => null,
//      'collected' => 75.00, 'paid' => 0.00, 'net_payable' => 75.00],
//   ],
//   'total_collected'   => 33825.00,
//   'total_paid'        => 4500.00,
//   'total_net_payable' => 29325.00,
//   'sbu_code'          => null,
// ]
```

Via REST API:

```http
GET /api/accounting/reports/sales-tax-liability?start_date=2026-04-01&end_date=2026-04-30
```

Via Artisan:

```bash
php artisan accounting:report sales-tax-liability --start=2026-04-01 --end=2026-04-30
```

See [invoices-bills.md § Using managed tax rates](invoices-bills.md#using-managed-tax-rates) for how line items get linked to a `TaxRate`.

---

## Chart of Accounts queries

```php
use Centrex\Accounting\Models\Account;

// Find by code
$cash = Account::where('code', '1000')->first();

// Current balance
$balance = $cash->getCurrentBalance(); // float

// Create a custom account
Account::create([
    'code'      => '1310',
    'name'      => 'Raw Materials Inventory',
    'type'      => 'asset',
    'subtype'   => 'current_asset',
    'currency'  => 'BDT',
    'parent_id' => null,
]);
```
