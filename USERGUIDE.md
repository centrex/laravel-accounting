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
```

All report methods accept an optional `sbuCode` parameter to filter by Strategic Business Unit.

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

**Trial balance not balancing** — check for entries created outside the facade, or entries still in `draft` status (only `posted` entries affect balances).

**Account balance incorrect** — verify all relevant journal entries are posted; check the `sbu_code` filter if using SBU-scoped reports.

**Cannot post to a period** — `ACCOUNTING_ENFORCE_PERIOD_LOCK=true` blocks posting to closed periods. Pass `bypassPeriodLock: true` if you have permission, or re-open the period first.

**Fiscal year won't close** — all fiscal periods for the year must be closed first, and there must be no unposted entries dated within the year.

**QBO sync failing** — check that `QBO_REALM_ID` is set and the token has not expired. Run `php artisan accounting:qbo-sync --realm=<id>` to test connectivity. Access tokens auto-refresh, but if the refresh token (100-day TTL) expires, the user must re-connect via `/accounting/qbo/connect`.
