# Organizational Loans

Organizational loans track term loans, working capital facilities, director loans, equipment finance, and other multi-month or multi-year borrowings. Each lender is a **loan facility** with its own dedicated GL sub-accounts. Short-term and long-term loans use separate parent accounts and expense codes.

## Account structure

`initializeChartOfAccounts()` seeds the parent accounts automatically. Sub-accounts are created when you call `addLoanFacility()`:

| Code range | Name | Term |
| --- | --- | --- |
| `2400` | Short-term Loans Payable (parent) | short |
| `2401`–`2419` | Per-lender short-term principal | short |
| `2420` | Accrued Interest — Short-term Loans (parent) | short |
| `2421`–`2439` | Per-lender short-term accrued interest | short |
| `2500` | Long-term Loans Payable (parent) | long |
| `2501`–`2519` | Per-lender long-term principal | long |
| `2520` | Accrued Interest — Long-term Loans (parent) | long |
| `2521`–`2539` | Per-lender long-term accrued interest | long |
| `6720` | Interest Expense — Short-term Loans | — |
| `6730` | Interest Expense — Long-term Loans | — |

## Register lenders

```php
use Centrex\Accounting\Facades\Accounting;

// Short-term working capital from BRAC Bank
$brac = Accounting::addLoanFacility(
    lenderName:    'BRAC Bank Ltd',
    loanType:      'working_capital',   // term_loan | working_capital | inter_company
                                        // director | equipment | overdraft | bridge
    loanTerm:      'short_term',        // short_term | long_term
    monthlyRate:   0.015,               // 1.5% per month
    sbuCode:       null,                // optional SBU tagging
    loanAmount:    3_000_000.00,        // sanctioned amount (informational)
    disbursedAt:   '2026-01-15',
    dueAt:         '2026-07-15',
    tenureMonths:  6,
    contact:       'Mr. Hossain, 01700-111111',
);
// → creates 240x (principal) + 242x (accrued interest) sub-accounts

// Long-term equipment loan from IDLC Finance
$idlc = Accounting::addLoanFacility(
    lenderName:   'IDLC Finance Ltd',
    loanType:     'equipment',
    loanTerm:     'long_term',
    monthlyRate:  0.012,
    loanAmount:   10_000_000.00,
    disbursedAt:  '2026-01-01',
    dueAt:        '2029-01-01',
    tenureMonths: 36,
);
// → creates 250x (principal) + 252x (accrued interest) sub-accounts

// Director loan (inter-company / owner advance)
$director = Accounting::addLoanFacility(
    lenderName:  'Mr. Karim (Director)',
    loanType:    'director',
    loanTerm:    'short_term',
    monthlyRate: 0.00,              // interest-free
    loanAmount:  500_000.00,
);
```

## Draw down funds (receive disbursement)

```php
$entry = Accounting::drawdownLoan(
    facility:    $brac,
    amount:      3_000_000.00,
    date:        '2026-01-15',
    reference:   'BRAC-LOAN-2026-001',
    description: 'Working capital drawdown — Q1 2026',
);
$entry->submit();
$entry->post();
// DR Bank Account 1100          ৳30,00,000
// CR Working Capital Payable 240x ৳30,00,000
```

Throws `RuntimeException` if the facility is inactive.

## Month-end interest accrual

```php
// All active loan facilities at once
$results = Accounting::accrueAllLoanInterest(date: '2026-04-30');
foreach ($results as $facilityId => $je) {
    if ($je) { $je->submit(); $je->post(); }
}

// Single facility
$je = Accounting::accrueLoanInterest($brac, date: '2026-04-30');
// Short-term: DR Interest Expense — Short-term Loans 6720  ৳x
//             CR Accrued Interest — BRAC Bank 242x          ৳x
// Long-term:  DR Interest Expense — Long-term Loans 6730   ৳x
//             CR Accrued Interest — IDLC Finance 252x       ৳x
// Returns null and skips cleanly if outstanding principal is zero
```

## Pay the interest

```php
Accounting::payLoanInterest($brac, 45_000.00, '2026-05-05', 'BRAC-INT-APR-2026');
// DR Accrued Interest — BRAC Bank 242x  ৳45,000
// CR Bank Account 1100                  ৳45,000
```

## Repay principal

```php
Accounting::repayLoan($brac, 500_000.00, '2026-05-10', 'BRAC-REPAY-2026-001');
// DR Working Capital Payable 240x  ৳5,00,000
// CR Bank Account 1100             ৳5,00,000
// Validates: amount ≤ outstanding principal
```

Throws `RuntimeException` if the repayment amount exceeds outstanding principal.

## Portfolio summary

```php
$summary = Accounting::getLoanSummary();          // all facilities
$summary = Accounting::getLoanSummary('NORTH');   // SBU-filtered

// Per facility:
// [
//   'id'                    => 1,
//   'lender_name'           => 'BRAC Bank Ltd',
//   'loan_type'             => 'working_capital',
//   'loan_term'             => 'short_term',
//   'sbu_code'              => null,
//   'is_active'             => true,
//   'monthly_rate'          => 0.015,
//   'loan_amount'           => 3000000.0,
//   'disbursed_at'          => '2026-01-15',
//   'due_at'                => '2026-07-15',
//   'months_remaining'      => 2,
//   'outstanding_principal' => 2500000.0,
//   'accrued_interest'      => 45000.0,
//   'monthly_interest'      => 37500.0,
//   'principal_account'     => '2401 Working Capital Payable — BRAC Bank Ltd',
//   'interest_account'      => '2421 Accrued Interest — BRAC Bank Ltd',
// ]
```

Results are ordered by `loan_term` then `lender_name`.

## Journal flow summary

| Event | DR | CR |
| --- | --- | --- |
| Disbursement received | Bank `1100` | Loan Payable `240x` / `250x` |
| Monthly accrual (short) | Interest Expense `6720` | Accrued Interest `242x` |
| Monthly accrual (long) | Interest Expense `6730` | Accrued Interest `252x` |
| Pay interest | Accrued Interest `242x` / `252x` | Bank `1100` |
| Repay principal | Loan Payable `240x` / `250x` | Bank `1100` |
