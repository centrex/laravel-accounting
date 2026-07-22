# Laravel Accounting — User Guide

A comprehensive double-entry bookkeeping system with financial reporting, multi-currency support, automated journal entries, and QuickBooks Online integration.

## Features

### Core Accounting

- **Double-Entry Bookkeeping** — every transaction maintains debit = credit balance
- **Chart of Accounts** — hierarchical account structure with 5 main types
- **Journal Entries** — manual and automated entry creation with two-step approval workflow
- **Account Balances** — real-time balance calculations and historical tracking
- **Fiscal Periods** — year and monthly period management with closing procedures

### Financial Reporting

- **Trial Balance** — verify accounting equation integrity
- **Balance Sheet** — assets, liabilities, and equity snapshot
- **Income Statement (P&L)** — revenue and expenses with net income
- **Cash Flow Statement** — operating, investing, and financing activities
- **General Ledger** — complete transaction history by account
- **A/R Aging** — receivables aging in QBO-compatible buckets (current, 1–30, 31–60, 61–90, 91+)
- **A/P Aging** — payables aging with the same buckets

### Business Operations

- **Invoicing** — create customer invoices with automatic AR journal entries
- **Bill Management** — record vendor bills with AP automation
- **Payment Processing** — track payments and auto-update invoice/bill status
- **Customer & Vendor Ledgers** — per-entity statement of account
- **Budget Management** — create, approve, and track actual vs. budget variance
- **Expenses** — direct expense recording with cash or credit posting
- **Loan Facilities** — register lenders (term loans, working capital, director, inter-company, equipment, overdraft, bridge) and track drawdowns, monthly interest accrual, interest payments, and principal repayments per facility
- **Owner's Equity** — record capital contributions and owner drawings against the standard Capital / Owner Drawings accounts
- **Tax Rates** — managed, reportable tax rates selectable per invoice/bill line item (the free-typed percentage still works as a fallback), plus a Sales Tax Liability report netting output vs. input tax
- **Bank Reconciliation** — import a bank statement, match lines against posted GL activity, resolve unmatched lines with an adjusting entry, and complete the session under an enforced balance check

### QuickBooks Online (QBO)

- **Two-way sync** — push your Chart of Accounts, Customers, Vendors, Invoices, Bills, and Journal Entries to QBO
- **Pull reports** — fetch QBO-native reports (P&L, Balance Sheet, etc.) directly into your app
- **QBO-formatted reports** — render any local report in QBO section structure for reconciliation

---

## Installation

```bash
composer require centrex/laravel-accounting
php artisan vendor:publish --tag="laravel-accounting-config"
php artisan migrate
```

Seed the standard chart of accounts (idempotent):

```php
use Centrex\Accounting\Facades\Accounting;

Accounting::initializeChartOfAccounts();
```

The package auto-registers its service provider. No manual configuration in `config/app.php` is needed with Laravel's package auto-discovery.

---

## Usage Examples

### Creating Journal Entries

Use the `Accounting` facade — it is the single entry point for all operations.

```php
use Centrex\Accounting\Facades\Accounting;

$entry = Accounting::createJournalEntry([
    'date'        => today(),
    'reference'   => 'INV-001',
    'description' => 'Sale to customer',
    'lines' => [
        ['account_id' => $arAccountId,      'type' => 'debit',  'amount' => 1000.00],
        ['account_id' => $revenueAccountId, 'type' => 'credit', 'amount' => 1000.00],
    ],
]);

// Submit for approval, then post
$entry->submit();
$entry->post();

// Or post directly (if user has accounting.journal.post gate)
$entry->post();
```

### Recording an Invoice

```php
use Centrex\Accounting\Facades\Accounting;
use Centrex\Accounting\Models\{Invoice, Customer};

$customer = Customer::create(['name' => 'Acme Corp', 'email' => 'acme@example.com']);

$invoice = Invoice::create([
    'customer_id'  => $customer->id,
    'invoice_date' => today(),
    'due_date'     => today()->addDays(30),
    'subtotal'     => 1000.00,
    'tax_amount'   => 80.00,
    'total'        => 1080.00,
    'currency'     => 'BDT',
]);

$invoice->items()->create([
    'description' => 'Web Development Services',
    'quantity'    => 10,
    'unit_price'  => 100.00,
    'total'       => 1000.00,
]);

// Post invoice → DR Accounts Receivable / CR Sales Revenue + Tax
Accounting::postInvoice($invoice);
```

### Recording Payment

```php
Accounting::recordInvoicePayment($invoice, [
    'date'         => today(),
    'amount'       => 1080.00,
    'method'       => 'bank_transfer',
    'account_code' => '1100',   // Bank account; defaults to 1000 (Cash)
]);
// Invoice status auto-updates: partially_settled → settled when fully paid
```

### Registering and Drawing Down a Loan

```php
use Centrex\Accounting\Facades\Accounting;

$loan = Accounting::addLoanFacility(
    lenderName:   'IDLC Finance Ltd',
    loanType:     'term_loan',      // term_loan | working_capital | inter_company | director | equipment | overdraft | bridge
    loanTerm:     'long_term',      // short_term | long_term
    monthlyRate:  0.01,             // 1% per month
    loanAmount:   10_000_000.00,
    disbursedAt:  today(),
    dueAt:        today()->addYears(5),
    tenureMonths: 60,
);
// → auto-creates principal (250x) + accrued interest (252x) sub-accounts

$entry = Accounting::drawdownLoan($loan, 10_000_000.00, today(), 'LOAN-001');
$entry->post();
// DR Bank 1100 / CR Loan Payable 250x

// Month-end
Accounting::accrueLoanInterest($loan)?->post();       // DR Interest Expense / CR Accrued Interest
Accounting::payLoanInterest($loan, 100_000.00, today(), 'LOAN-INT-001')->post();
Accounting::repayLoan($loan, 500_000.00, today(), 'LOAN-REPAY-001')->post();
```

Manage all of this from the UI at `/accounting/loans` — see [README.md § Organizational Loans](README.md#organizational-loans--sbu-wise-tracking).

### Recording a Capital Contribution or Owner Drawing

There's no dedicated facade method for equity — post directly against the standard accounts seeded by `initializeChartOfAccounts()` (Capital `3000`, Owner Drawings `3200`):

```php
use Centrex\Accounting\Models\Account;

$bank    = Account::where('code', '1100')->first();
$capital = Account::where('code', config('accounting.accounts.capital', '3000'))->first();

$entry = Accounting::createJournalEntry([
    'date'        => today(),
    'reference'   => 'CAP-001',
    'description' => 'Capital injection — opening equity',
    'lines' => [
        ['account_id' => $bank->id,    'type' => 'debit',  'amount' => 500_000.00],
        ['account_id' => $capital->id, 'type' => 'credit', 'amount' => 500_000.00],
    ],
]);
$entry->post();
```

Manage this from the UI at `/accounting/equity` — see [README.md § Owner's Equity](README.md#owners-equity).

### Generating Reports

```php
use Centrex\Accounting\Facades\Accounting;

// Trial Balance
$tb = Accounting::getTrialBalance(startDate: '2026-01-01', endDate: '2026-12-31');
// ['accounts' => [...], 'total_debits' => x, 'total_credits' => x, 'is_balanced' => true]

// Balance Sheet
$bs = Accounting::getBalanceSheet(date: '2026-12-31');
// ['assets' => [...], 'liabilities' => [...], 'equity' => [...], 'is_balanced' => true]

// Income Statement
$pl = Accounting::getIncomeStatement(startDate: '2026-01-01', endDate: '2026-12-31');
// ['revenue' => [...], 'expenses' => [...], 'gross_profit' => x, 'net_income' => x]

// Cash Flow
$cf = Accounting::getCashFlowStatement(startDate: '2026-01-01', endDate: '2026-12-31');
// ['operating_activities' => x, 'investing_activities' => x, 'financing_activities' => x, 'net_change' => x]

// A/R Aging (QBO-compatible buckets)
$arAging = Accounting::getArAging(asOfDate: '2026-12-31');
// ['as_of_date' => '...', 'rows' => [...], 'totals' => ['current' => x, '1_30' => x, ...]]

// A/P Aging
$apAging = Accounting::getApAging(asOfDate: '2026-12-31');

// Sales Tax Liability — output tax collected (invoices) vs input tax paid (bills), by rate
$taxLiability = Accounting::getSalesTaxLiabilityReport('2026-01-01', '2026-12-31');
// ['rows' => [['name' => 'VAT Standard', 'code' => 'VAT', 'rate' => 15.00,
//              'collected' => x, 'paid' => x, 'net_payable' => x], ...],
//  'total_collected' => x, 'total_paid' => x, 'total_net_payable' => x]
```

All report methods accept an optional `sbuCode` parameter to filter by Strategic Business Unit.

### Using Tax Rates on Invoice/Bill Line Items

```php
use Centrex\Accounting\Models\{InvoiceItem, TaxRate};

$vat = TaxRate::create(['name' => 'VAT Standard', 'code' => 'VAT', 'rate' => 15.00, 'is_active' => true]);

// tax_rate_id snapshots the current rate into tax_rate/tax_amount at save time — a later
// edit to TaxRate::rate never changes an already-saved line.
InvoiceItem::create([
    'invoice_id'  => $invoice->id,
    'description' => 'Web Development Services',
    'quantity'    => 10,
    'unit_price'  => 100.00,
    'tax_rate_id' => $vat->id,   // tax_rate = 15.00, tax_amount computed automatically
]);

// Free-typed percentage still works — just omit tax_rate_id:
InvoiceItem::create([
    'invoice_id'  => $invoice->id,
    'description' => 'One-off item',
    'quantity'    => 1,
    'unit_price'  => 50.00,
    'tax_rate'    => 5,
]);
```

Manage rates from the UI at `/accounting/tax-rates`. Full write-up: [README.md § Tax Rates & Sales Tax Liability](README.md#tax-rates--sales-tax-liability).

### Working with Accounts

```php
use Centrex\Accounting\Models\Account;

// Find by code
$bankAccount = Account::where('code', '1100')->first();

// Create a new account
Account::create([
    'code'    => '6550',
    'name'    => 'Software Subscriptions',
    'type'    => 'expense',
    'subtype' => 'operating_expense',
    'currency'=> 'BDT',
]);

// Current balance
$balance = $bankAccount->getCurrentBalance(); // float
```

### Closing a Fiscal Year

```php
use Centrex\Accounting\Facades\Accounting;
use Centrex\Accounting\Models\FiscalYear;

$fy = FiscalYear::where('name', 'FY 2026')->first();

// Transfers net income → Retained Earnings (3100) via closing journal entry
Accounting::closeFiscalYear($fy);
```

### Adjustments & Reconciliation

```php
// Cash count variance — post against a dedicated variance account (create once)
$cashOverShort = Account::firstOrCreate(['code' => '6900'], [
    'name' => 'Cash Over/Short', 'type' => 'expense', 'subtype' => 'other_expense',
]);
$cash = Account::where('code', '1000')->first();

$entry = Accounting::createJournalEntry([
    'date' => today(), 'type' => 'adjustment', 'description' => 'Cash count shortage',
    'lines' => [
        ['account_id' => $cashOverShort->id, 'type' => 'debit',  'amount' => 500],
        ['account_id' => $cash->id,          'type' => 'credit', 'amount' => 500],
    ],
]);
$entry->post();

// Quick daily check — tie GL activity to the bank statement without a formal session
$bank = Account::where('code', '1100')->first();
$gl   = Accounting::getGeneralLedger($bank->id, today()->toDateString(), today()->toDateString());
$closingBalance = $gl['accounts'][0]['closing_balance'] ?? 0.0; // compare to the statement

// Formal reconciliation — import a statement, match lines, resolve the rest, then complete
$reconciliation = Accounting::createBankReconciliation([
    'account_id'               => $bank->id,
    'statement_date'           => today()->toDateString(),
    'opening_balance'          => $bank->getCurrentBalance(),
    'statement_ending_balance' => 1_487_650.00,
]);
Accounting::importBankStatementLines($reconciliation, [
    ['transaction_date' => today()->toDateString(), 'description' => 'Wire in', 'amount' => 220000.00, 'type' => 'debit'],
]);
Accounting::matchStatementLine($reconciliation->statementLines->first(), $unmatchedGlLine);
Accounting::completeBankReconciliation($reconciliation); // throws if unmatched lines remain or the balance doesn't tie
```

Full write-up — including inventory adjustments, monthly reconciliation, the distinction between monthly period-close and the year-end closing journal, and a step-by-step guide for balance sheet discrepancies — lives in [README.md § Adjustments, Reconciliation & Closing](README.md#adjustments-reconciliation--closing). The formal bank reconciliation workflow (statement import, matching, adjusting entries for unmatched lines) is documented in full at [README.md § Bank Reconciliation](README.md#bank-reconciliation).

---

## QuickBooks Online Integration

### Setup

Add to `.env`:

```env
QBO_CLIENT_ID=your_client_id
QBO_CLIENT_SECRET=your_client_secret
QBO_REDIRECT_URI=https://yourapp.com/accounting/qbo/callback
QBO_ENVIRONMENT=sandbox    # sandbox | production
QBO_REALM_ID=              # filled after first OAuth connect
```

### Connect

Direct the user to `/accounting/qbo/connect` to start the OAuth2 flow. After approval, tokens are stored automatically.

### Push Data to QBO

```php
use Centrex\Accounting\QuickBooks\QuickBooksSyncService;

$sync    = app(QuickBooksSyncService::class);
$realmId = config('accounting.quickbooks.default_realm_id');

$sync->syncAccounts($realmId);
$sync->syncCustomers($realmId);
$sync->syncVendors($realmId);
$sync->syncInvoices($realmId, since: '2026-05-01');
$sync->syncBills($realmId);
$sync->syncJournalEntries($realmId);
// Each returns: ['created' => int, 'updated' => int, 'skipped' => int, 'errors' => []]
```

Or via artisan:

```bash
php artisan accounting:qbo-sync
php artisan accounting:qbo-sync --entity=invoices --since=2026-05-01
```

### Format Local Reports for QBO

```php
use Centrex\Accounting\Facades\Accounting;
use Centrex\Accounting\QuickBooks\QuickBooksReportFormatter;

$pl     = Accounting::getIncomeStatement('2026-01-01', '2026-12-31');
$qboFmt = app(QuickBooksReportFormatter::class)->profitAndLoss($pl);
// ['income' => [...], 'cost_of_goods_sold' => [...], 'gross_profit' => x, 'expenses' => [...], 'net_income' => x]
```

All REST API report endpoints accept `?format=qbo` to return the QBO-structured variant.

See [docs/quickbooks.md](docs/quickbooks.md) for the complete integration guide.

---

## Understanding Double-Entry Bookkeeping

### Account Types and Normal Balances

| Account Type | Normal Balance | Increases With | Decreases With |
| --- | --- | --- | --- |
| Asset | Debit | Debit | Credit |
| Liability | Credit | Credit | Debit |
| Equity | Credit | Credit | Debit |
| Revenue | Credit | Credit | Debit |
| Expense | Debit | Debit | Credit |

### The Accounting Equation

**Assets = Liabilities + Equity**

Every transaction must maintain this balance.

### Journal Entry Rules

1. Every entry must have at least one debit and one credit line
2. Total debits must equal total credits (within `ACCOUNTING_ROUNDING_TOLERANCE`)
3. Only `posted` entries affect account balances
4. Posted entries cannot be edited — only voided

### Example Transactions

**Sale on credit:**
```
DR Accounts Receivable  1,000
  CR Sales Revenue          1,000
```

**Receive payment:**
```
DR Bank                  1,000
  CR Accounts Receivable    1,000
```

**Pay rent:**
```
DR Rent Expense          2,000
  CR Cash                   2,000
```

---

## Chart of Accounts Code Ranges

| Range | Category |
| --- | --- |
| 1000–1499 | Current Assets (Cash, Bank, AR, Inventory) |
| 1500–1999 | Fixed Assets |
| 2000–2499 | Current Liabilities (AP, Tax Payable) |
| 2500–2999 | Long-term Liabilities |
| 3000–3999 | Equity (Share Capital, Retained Earnings) |
| 4000–4799 | Operating Revenue |
| 4800–4999 | Non-operating / Other Income |
| 5000–5999 | Cost of Goods Sold |
| 6000–6999 | Operating Expenses |
| 6700–6799 | Financing Expenses (Interest) |

---

## Best Practices

**Always use the Accounting facade** — direct model creation bypasses validation and double-entry enforcement:

```php
// Correct
Accounting::createJournalEntry($data);

// Avoid — bypasses balance check
JournalEntry::create($data);
```

**Check balance before posting manually:**

```php
if ($entry->isBalanced()) {
    $entry->post();
}
```

**Wrap multi-step operations in a DB transaction:**

```php
DB::transaction(function () use ($invoice, $paymentData) {
    Accounting::postInvoice($invoice);
    Accounting::recordInvoicePayment($invoice, $paymentData);
});
```

**Reconcile regularly:**

```php
$tb = Accounting::getTrialBalance('2026-01-01', '2026-12-31');
if (!$tb['is_balanced']) {
    // Investigate discrepancy
}
```

---

## Troubleshooting

**Trial balance not balancing** — check for entries created outside the facade, or entries still in `draft` status (only `posted` entries affect balances). `Accounting::createJournalEntry()` refuses to save an unbalanced entry, so this only happens via a direct `JournalEntry::create()` call.

**Account balance incorrect** — verify all relevant journal entries are posted; check the `sbu_code` filter if using SBU-scoped reports.

**Balance sheet doesn't balance (but trial balance does)** — almost always a deactivated account (`is_active = false`) still carrying a posted balance (reports exclude it; `Account::getCurrentBalance()` does not), or an account filed under the wrong `type` (Assets/Expenses are debit-normal, Liabilities/Equity/Revenue are credit-normal — see `Account::isDebitAccount()`). Full diagnostic walkthrough: [README.md § Solving Balance Sheet Discrepancies](README.md#solving-balance-sheet-discrepancies).

**Cannot post to a period** — `ACCOUNTING_ENFORCE_PERIOD_LOCK=true` blocks posting to closed periods. Pass `bypassPeriodLock: true` if you have permission, or re-open the period first.

**Fiscal year won't close** — all fiscal periods for the year must be closed first, and there must be no unposted entries dated within the year.

**Bank reconciliation won't complete** — `completeBankReconciliation()` throws if any imported statement line is still unmatched (match it or resolve it with an adjusting entry first), or if `opening_balance + reconciled debits − reconciled credits` doesn't equal `statement_ending_balance` within `ACCOUNTING_ROUNDING_TOLERANCE` — the exception message reports the exact variance to help you find it.

**QBO sync failing** — check that `QBO_REALM_ID` is set and the token has not expired. Run `php artisan accounting:qbo-sync --realm=<id>` to test connectivity. Access tokens auto-refresh, but if the refresh token (100-day TTL) expires, the user must re-connect via `/accounting/qbo/connect`.
