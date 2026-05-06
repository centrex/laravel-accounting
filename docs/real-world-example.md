# Real-World Example — Trading Company Month-End Close

**Scenario:** ABC Trading Ltd imports electronics from South Korea and sells in Bangladesh. The company carries two active inventory financing facilities — BRAC Bank (৳50 lakh limit, 2%/month) and a private party Mr. Karim (৳15 lakh limit, 2%/month). April 2026 had 80 purchase transactions, 140 sales orders, and 6 stock adjustments.

See [period-closing.md](period-closing.md) for the full workflow checklist. This document walks through each step with actual code and numbers.

---

## Day 27–28: Cut-Off Procedures

Ensure all economic events before April 30 are captured:

```php
use Centrex\Accounting\Facades\Accounting;
use Centrex\Accounting\Models\FiscalPeriod;

// 1. Every goods received note (GRN) for April must be posted
//    → JE: DR Inventory 1300 ৳4,218,500 / CR Accounts Payable 2000 ৳4,218,500

// 2. All April sale fulfillments posted
//    → JE: DR COGS 5000 ৳2,940,000 / CR Inventory 1300 ৳2,940,000

// 3. All customer invoices for April shipments issued
//    → JE: DR AR 1200 ৳5,100,000 / CR Sales Revenue 4000 ৳4,500,000
//                                 / CR VAT Payable 2300    ৳  600,000

// 4. All vendor bills for April purchases posted
//    → JE: DR Inventory 1300 ৳4,200,000 / DR VAT Input ৳630,000
//         / CR Accounts Payable 2000 ৳4,830,000

// 5. Bank receipts from customers posted
Accounting::recordInvoicePayment($invoice, [
    'date' => '2026-04-28', 'amount' => 2800000, 'method' => 'bank_transfer',
]);

// 6. Vendor payments posted
Accounting::recordBillPayment($bill, [
    'date' => '2026-04-28', 'amount' => 3200000, 'method' => 'bank_transfer',
]);
```

---

## Day 29: Adjusting Entries

```php
// Accrual: April electricity bill — received but not yet invoiced
$entry = Accounting::createJournalEntry([
    'date'        => '2026-04-30',
    'reference'   => 'ACCR-UTIL-APR26',
    'type'        => 'adjustment',
    'description' => 'Accrual — April utilities bill (estimated)',
    'lines' => [
        ['account_id' => $utilitiesExpenseId, 'type' => 'debit',  'amount' => 18000],
        ['account_id' => $accruedLiabId,      'type' => 'credit', 'amount' => 18000],
    ],
]);
$entry->submit();
$entry->post();

// Depreciation: Monthly depreciation for warehouse equipment
$depEntry = Accounting::createJournalEntry([
    'date'        => '2026-04-30',
    'reference'   => 'DEP-APR26',
    'type'        => 'adjustment',
    'description' => 'Monthly depreciation — warehouse equipment',
    'lines' => [
        ['account_id' => $depreciationExpenseId, 'type' => 'debit',  'amount' => 25000],
        ['account_id' => $accumDepreciationId,   'type' => 'credit', 'amount' => 25000],
    ],
]);
$depEntry->submit();
$depEntry->post();

// Inventory financing interest — all active facilities
// BRAC Bank: outstanding ৳38,00,000 × 2% = ৳76,000
// Mr. Karim: outstanding ৳12,00,000 × 2% = ৳24,000
$accruals = Accounting::accrueAllFinancingInterest(date: '2026-04-30');
foreach ($accruals as $je) {
    if ($je) { $je->submit(); $je->post(); }
}
// Total booked: ৳1,00,000
// DR Interest Expense — Inv. Financing 6710  ৳1,00,000
// CR Accrued Interest — BRAC Bank 2171         ৳76,000
// CR Accrued Interest — Mr. Abdul Karim 2172   ৳24,000
```

---

## Day 30: Pre-Close Checks

```php
$period = FiscalPeriod::where('name', 'April 2026')->first();

$checks = Accounting::getPeriodCloseChecks($period);
// unposted_journals: 0   ✓  no blockers
// open_invoices:     3   (3 customers haven't paid — will carry forward)
// open_bills:        1   (1 bill due May 15 — will carry forward)
// has_blockers:      false  ✓  ready to close
```

---

## Day 30: Review Income Statement

```php
$pl = Accounting::getIncomeStatement('2026-04-01', '2026-04-30');

// Revenue
//   Sales Revenue (4000):        ৳ 51,00,000
//   Total Revenue:               ৳ 51,00,000

// Expenses
//   COGS (5000):                 ৳ 29,40,000
//   Office Rent (5100):          ৳    75,000
//   Salaries (5200):             ৳  3,00,000
//   Marketing (5600):            ৳    50,000
//   Utilities (5700):            ৳    18,000
//   Depreciation (5800):         ৳    25,000
//   Interest Expense (6710):     ৳  1,00,000
//   Total Expenses:              ৳ 35,08,000

// Net Profit for April 2026:     ৳ 15,92,000  ✓
```

---

## Day 30: Physical Inventory Count

Warehouse team counts all items. A 3-unit shrinkage of Samsung TV 55" is found (WAC ৳12,500 each):

```php
$shrinkageEntry = Accounting::createJournalEntry([
    'date'        => '2026-04-30',
    'reference'   => 'SHRINK-APR26-TV',
    'type'        => 'adjustment',
    'description' => 'Inventory shrinkage — 3× Samsung TV 55" (physical count variance)',
    'lines' => [
        ['account_id' => $inventoryShrinkageId, 'type' => 'debit',  'amount' => 37500],
        ['account_id' => $inventoryAssetId,     'type' => 'credit', 'amount' => 37500],
    ],
]);
$shrinkageEntry->submit();
$shrinkageEntry->post();
```

---

## Day 30: Close the Period + Inventory Snapshot

```php
$result = Accounting::closeFiscalPeriod($period, snapshotInventory: true);

// Inventory reconciliation output:
// Snapshot lines:    427 (product × warehouse combinations)
// Physical value:    ৳ 4,21,85,000.00
// GL balance (1300): ৳ 4,21,85,000.00
// Variance:          ৳          0.00  ✓ Reconciled

// Period is now LOCKED — no more entries can be posted with April dates
// (use bypassPeriodLock: true for late corrections if absolutely necessary)
```

---

## Final Balance Sheet — April 30, 2026

```php
$bs = Accounting::getBalanceSheet('2026-04-30');

// ASSETS
//   Cash (1000):                      ৳    45,00,000
//   Bank (1100):                      ৳    82,50,000
//   Accounts Receivable (1200):       ৳    24,60,000   (3 open invoices)
//   Inventory (1300):                 ৳  4,21,85,000   (snapshot-verified)
//   Total Assets:                     ৳  5,73,95,000

// LIABILITIES
//   Accounts Payable (2000):          ৳    38,50,000   (1 open bill)
//   Inv. Financing — BRAC (2151):     ৳    38,00,000
//   Inv. Financing — Karim (2152):    ৳    12,00,000
//   Accrued Interest — BRAC (2171):   ৳       76,000
//   Accrued Interest — Karim (2172):  ৳       24,000
//   VAT Payable (2300):               ৳     6,00,000
//   Accrued Liabilities:              ৳       18,000
//   Total Liabilities:                ৳    95,68,000

// EQUITY
//   Share Capital (3000):             ৳  4,96,85,000
//   Retained Earnings (3100):         ৳    15,00,000   (prior periods)
//   Current Period Income:            ৳    15,92,000   (April net profit)
//   Total Equity:                     ৳  5,27,77,000

// BALANCE CHECK: ৳5,73,95,000  ✓  (assets = liabilities + equity)
```

May 1 — a new period opens automatically. The April numbers are frozen.

---

## May 5: Post-Close — Pay Accrued Financing Interest

```php
// Pay BRAC Bank April interest
Accounting::payFinancingInterest($brac, 76_000.00, '2026-05-05', 'BRAC-INT-APR-2026');
// DR Accrued Interest — BRAC Bank 2171  ৳76,000
// CR Bank Account 1100                  ৳76,000

// Pay Mr. Karim April interest
Accounting::payFinancingInterest($karim, 24_000.00, '2026-05-05', 'KARIM-INT-APR-2026');
```

After payment, `getFinancingSummary()` shows `accrued_interest: 0.0` for both facilities.
