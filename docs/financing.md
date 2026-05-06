# Inventory Financing

Inventory financing is a revolving short-term credit facility where a lender advances funds specifically to purchase stock. The inventory itself serves as collateral. Each lender is a **facility** with its own dedicated GL sub-accounts.

## Account structure

`initializeChartOfAccounts()` seeds the parent accounts automatically. Sub-accounts are created when you call `addFinancingFacility()`:

| Code | Name | Created by |
| --- | --- | --- |
| `2150` | Inventory Financing Payable (parent) | `initializeChartOfAccounts()` |
| `2151`, `2152`, … | Per-lender payable | `addFinancingFacility()` (sequential) |
| `2170` | Accrued Interest — Inv. Financing (parent) | `initializeChartOfAccounts()` |
| `2171`, `2172`, … | Per-lender accrued interest | `addFinancingFacility()` (sequential) |
| `6710` | Interest Expense — Inventory Financing | `initializeChartOfAccounts()` |

## Register lenders

```php
use Centrex\Accounting\Facades\Accounting;

// Lender 1 → gets 2151 + 2171
$brac = Accounting::addFinancingFacility(
    lenderName:  'BRAC Bank Ltd',
    lenderType:  'bank',           // bank | private | ngo | mfi | other
    monthlyRate: 0.02,             // 2% per month
    creditLimit: 5_000_000.00,
    contact:     'Md. Hasan, 01700-000001',
);

// Lender 2 → gets 2152 + 2172
$karim = Accounting::addFinancingFacility(
    lenderName:  'Mr. Abdul Karim',
    lenderType:  'private',
    monthlyRate: 0.02,
    creditLimit: 1_500_000.00,
);
```

## Draw down funds (buy inventory)

```php
$entry = Accounting::drawdownFinancing(
    facility:    $brac,
    amount:      2_000_000.00,
    date:        '2026-04-05',
    reference:   'BRAC-DD-2026-001',
    description: 'Samsung Galaxy A-series batch — PO-2026-047',
);
$entry->submit();
$entry->post();
// DR Inventory 1300  ৳20,00,000
// CR BRAC Bank Payable 2151  ৳20,00,000
```

Exceeding the `credit_limit` throws `RuntimeException`.

## Month-end interest accrual

```php
// All active facilities at once
$results = Accounting::accrueAllFinancingInterest(date: '2026-04-30');
foreach ($results as $facilityId => $je) {
    if ($je) { $je->submit(); $je->post(); }
}

// Single facility
$je = Accounting::accrueFinancingInterest($brac, date: '2026-04-30');
// DR Interest Expense — Inv. Financing 6710  ৳x
// CR Accrued Interest — BRAC Bank 2171       ৳x
// Returns null and skips cleanly if outstanding principal is zero
```

## Pay the interest

```php
Accounting::payFinancingInterest($brac, 50_000.00, '2026-05-05', 'BRAC-INT-APR-2026');
// DR Accrued Interest — BRAC Bank 2171  ৳50,000
// CR Bank Account 1100                  ৳50,000
```

## Repay principal

```php
Accounting::repayFinancing($brac, 1_000_000.00, '2026-05-10', 'BRAC-REPAY-2026-001');
// DR BRAC Bank Payable 2151  ৳10,00,000
// CR Bank Account 1100       ৳10,00,000
// Validates: amount ≤ outstanding principal
```

## Portfolio summary

```php
$summary = Accounting::getFinancingSummary();
// Per facility:
// [
//   'lender_name'           => 'BRAC Bank Ltd',
//   'lender_type'           => 'bank',
//   'is_active'             => true,
//   'monthly_rate'          => 0.02,
//   'credit_limit'          => 5000000.0,
//   'outstanding_principal' => 1500000.0,
//   'accrued_interest'      => 0.0,
//   'monthly_interest'      => 30000.0,
//   'principal_account'     => '2151 Inv. Financing Payable — BRAC Bank Ltd',
//   'interest_account'      => '2171 Accrued Interest — BRAC Bank Ltd',
// ]
```

## Journal flow summary

| Event | DR | CR |
| --- | --- | --- |
| Draw down | Inventory `1300` | Lender Payable `215x` |
| Monthly accrual | Interest Expense `6710` | Accrued Interest `217x` |
| Pay interest | Accrued Interest `217x` | Bank `1100` |
| Repay principal | Lender Payable `215x` | Bank `1100` |
