# Organizational Loans

Organizational loans track term loans, working capital facilities, director loans, equipment finance, and other multi-month or multi-year borrowings. Each lender is a **loan facility** with its own dedicated GL sub-accounts. Short-term and long-term loans use separate parent accounts and expense codes.

## Multi-currency facilities

A facility is denominated in its own `currency` (e.g. a USD-denominated loan from a foreign lender), which need not be the accounting base currency. `exchange_rate` (base-currency units per 1 unit of `currency`) converts every drawdown, interest accrual, interest payment, and repayment to the base currency before it hits the GL — the same mechanism `Invoice`/`Bill` use. Interest is calculated on the outstanding principal **in the facility's own currency**, not its base-currency equivalent, because that's what the lender actually charges against.

`LoanFacility::outstandingPrincipal()` / `accruedInterest()` read the GL and are always in base currency. The `outstandingPrincipalLocal()` / `accruedInterestLocal()` counterparts divide by `exchange_rate` to give the lender's-currency amounts. `monthlyInterestAmount()` is in the facility's currency.

If `currency`/`exchange_rate` are omitted, the facility defaults to the accounting base currency at a 1:1 rate — existing single-currency facilities behave exactly as before.

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

// Short-term working capital from BRAC Bank (base-currency facility — BDT)
$brac = Accounting::addLoanFacility(
    lenderName:    'BRAC Bank Ltd',
    loanType:      'working_capital',   // term_loan | working_capital | inter_company
                                        // director | equipment | overdraft | bridge
    loanTerm:      'short_term',        // short_term | long_term
    monthlyRate:   0.015,               // 1.5% per month, applied to the local-currency principal
    sbuCode:       null,                // optional SBU tagging
    loanAmount:    3_000_000.00,        // sanctioned amount, in `currency`
    disbursedAt:   '2026-01-15',
    dueAt:         '2026-07-15',
    tenureMonths:  6,
    contact:       'Mr. Hossain, 01700-111111',
    // currency / exchangeRate omitted → defaults to base currency (BDT) at 1:1
);
// → creates 240x (principal) + 242x (accrued interest) sub-accounts

// Foreign-currency term loan — USD facility from an offshore lender
$offshore = Accounting::addLoanFacility(
    lenderName:    'Standard Chartered (Offshore)',
    loanType:      'term_loan',
    loanTerm:      'long_term',
    monthlyRate:   0.01,                // 1%/month, charged on the USD outstanding principal
    loanAmount:    100_000.00,          // USD 100,000 sanctioned
    disbursedAt:   '2026-01-01',
    dueAt:         '2029-01-01',
    tenureMonths:  36,
    currency:      'USD',
    exchangeRate:  110.50,              // 1 USD = 110.50 BDT — converts every posting to base currency
);
// → creates 250x (principal) + 252x (accrued interest) sub-accounts, all GL balances in BDT

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

`amount` is always in the facility's own currency — `drawdownLoan()` converts it to the base currency using the facility's `exchange_rate` before posting.

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

// USD facility — amount is in USD, journal lines post in BDT
Accounting::drawdownLoan($offshore, 40_000.00, '2026-01-01', 'SCB-USD-2026-T1')->post();
// DR Bank Account 1100  ৳44,20,000  (40,000 × 110.50)
// CR Term Loan Payable 250x  ৳44,20,000
```

Throws `RuntimeException` if the facility is inactive.

## Month-end interest accrual

Interest is calculated on the outstanding principal in the facility's own currency (`outstandingPrincipalLocal()` × `monthly_rate`), then converted to base currency for the journal entry.

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

// USD facility — 1% of the USD 40,000 outstanding, i.e. $400, posted at the facility's rate
$je = Accounting::accrueLoanInterest($offshore, date: '2026-04-30');
// DR Interest Expense — Long-term Loans 6730  ৳44,200  (400 × 110.50)
// CR Accrued Interest — Standard Chartered 252x  ৳44,200
```

## Pay the interest

`amount` is in the facility's own currency.

```php
Accounting::payLoanInterest($brac, 45_000.00, '2026-05-05', 'BRAC-INT-APR-2026');
// DR Accrued Interest — BRAC Bank 242x  ৳45,000
// CR Bank Account 1100                  ৳45,000
```

## Repay principal

`amount` is in the facility's own currency, validated against `outstandingPrincipalLocal()`.

```php
Accounting::repayLoan($brac, 500_000.00, '2026-05-10', 'BRAC-REPAY-2026-001');
// DR Working Capital Payable 240x  ৳5,00,000
// CR Bank Account 1100             ৳5,00,000
// Validates: amount ≤ outstanding principal (in the facility's currency)
```

Throws `RuntimeException` if the repayment amount exceeds outstanding principal.

## Portfolio summary

```php
$summary = Accounting::getLoanSummary();          // all facilities
$summary = Accounting::getLoanSummary('NORTH');   // SBU-filtered

// Per facility:
// [
//   'id'                          => 1,
//   'lender_name'                 => 'BRAC Bank Ltd',
//   'loan_type'                   => 'working_capital',
//   'loan_term'                   => 'short_term',
//   'sbu_code'                    => null,
//   'is_active'                   => true,
//   'monthly_rate'                => 0.015,
//   'loan_amount'                 => 3000000.0,
//   'currency'                    => 'BDT',
//   'exchange_rate'               => 1.0,
//   'disbursed_at'                => '2026-01-15',
//   'due_at'                      => '2026-07-15',
//   'months_remaining'            => 2,
//   'outstanding_principal'       => 2500000.0,   // base currency (GL)
//   'outstanding_principal_local' => 2500000.0,   // facility's own currency
//   'accrued_interest'            => 45000.0,     // base currency (GL)
//   'accrued_interest_local'      => 45000.0,     // facility's own currency
//   'monthly_interest'            => 37500.0,     // facility's own currency
//   'principal_account'           => '2401 Working Capital Payable — BRAC Bank Ltd',
//   'interest_account'            => '2421 Accrued Interest — BRAC Bank Ltd',
// ]

// For the USD facility, outstanding_principal is the BDT GL balance while
// outstanding_principal_local is that balance divided by exchange_rate — the actual
// USD amount still owed to the lender.
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
