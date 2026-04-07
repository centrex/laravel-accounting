# Laravel Accounting

[![Latest Version on Packagist](https://img.shields.io/packagist/v/centrex/laravel-accounting.svg?style=flat-square)](https://packagist.org/packages/centrex/laravel-accounting)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/centrex/laravel-accounting/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/centrex/laravel-accounting/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/centrex/laravel-accounting/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/centrex/laravel-accounting/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/centrex/laravel-accounting?style=flat-square)](https://packagist.org/packages/centrex/laravel-accounting)

Full double-entry accounting system for Laravel. Includes a chart of accounts, journal entries, invoices, bills, customer/vendor management, financial reports, and a Livewire UI — all with a REST API layer.

## Installation

```bash
composer require centrex/laravel-accounting
php artisan vendor:publish --tag="accounting-config"
php artisan migrate
```

Seed the standard chart of accounts (idempotent):

```php
use Centrex\LaravelAccounting\Facades\Accounting;

Accounting::initializeChartOfAccounts();
```

## Environment variables

```env
ACCOUNTING_CURRENCY=BDT
ACCOUNTING_DB_CONNECTION=mysql        # optional separate connection
ACCOUNTING_TABLE_PREFIX=acct_
ACCOUNTING_FISCAL_START_MONTH=1
ACCOUNTING_FISCAL_AUTO_CREATE=true
```

## Core concepts

- **Double-entry**: every `JournalEntry` has debit and credit lines that must balance before posting.
- **Account types**: `asset | liability | equity | revenue | expense` — assets/expenses have debit-normal balances.
- **Entry statuses**: `draft → posted → void` — only `posted` lines affect balances and reports.
- **Invoice/Bill statuses**: `draft → sent → partial → paid`.

## Usage

### Journal entries

```php
use Centrex\LaravelAccounting\Facades\Accounting;

$entry = Accounting::createJournalEntry([
    'date'        => today(),
    'reference'   => 'REF-001',
    'type'        => 'general',   // general | closing | adjustment
    'description' => 'Office rent',
    'currency'    => 'BDT',
    'lines' => [
        ['account_id' => $rentId, 'type' => 'debit',  'amount' => 50000],
        ['account_id' => $cashId, 'type' => 'credit', 'amount' => 50000],
    ],
]);

$entry->post();          // validates balance, records approved_by/approved_at
$entry->void();          // only callable on posted entries
$entry->isBalanced();    // bool
```

### Invoices

```php
use Centrex\LaravelAccounting\Models\{Customer, Invoice};

$customer = Customer::create(['name' => 'Acme Corp', 'email' => 'acme@example.com']);

$invoice = Invoice::create([
    'customer_id'  => $customer->id,
    'invoice_date' => today(),
    'due_date'     => today()->addDays(30),
    'subtotal'     => 10000,
    'tax_amount'   => 1500,
    'total'        => 11500,
    'currency'     => 'BDT',
]);

// Post → creates JE: DR Accounts Receivable / CR Sales Revenue + Tax
Accounting::postInvoice($invoice);
// Fires: InvoicePosted → SyncCustomerOutstanding

// Record payment → creates JE: DR Cash / CR Accounts Receivable
Accounting::recordInvoicePayment($invoice, [
    'date'   => today(),
    'amount' => 11500,
    'method' => 'bank_transfer',   // cash | bank_transfer | cheque | card | mobile_banking
]);
// Fires: PaymentRecorded → NotifyAccountingTeam
// Invoice status auto-updates: partial if part-paid, paid if fully paid
```

### Bills

```php
use Centrex\LaravelAccounting\Models\{Vendor, Bill};

$vendor = Vendor::create(['name' => 'Supplier Ltd', 'email' => 'supplier@example.com']);

$bill = Bill::create([
    'vendor_id' => $vendor->id,
    'bill_date' => today(),
    'due_date'  => today()->addDays(30),
    'subtotal'  => 8000,
    'tax_amount'=> 1200,
    'total'     => 9200,
]);

// Post → creates JE: DR Expense + Tax / CR Accounts Payable
Accounting::postBill($bill);

Accounting::recordBillPayment($bill, ['date' => today(), 'amount' => 9200, 'method' => 'bank_transfer']);
```

### Financial reports

```php
// Trial Balance
$tb = Accounting::getTrialBalance('2025-01-01', '2025-12-31');
// ['accounts' => [...], 'total_debits' => x, 'total_credits' => x, 'is_balanced' => bool]

// Balance Sheet
$bs = Accounting::getBalanceSheet(now());
// ['assets' => [...], 'liabilities' => [...], 'equity' => [...], 'is_balanced' => bool]

// Income Statement (P&L)
$pl = Accounting::getIncomeStatement('2025-01-01', '2025-12-31');
// ['revenue' => [...], 'expenses' => [...], 'gross_profit' => x, 'net_income' => x]

// Cash Flow Statement
$cf = Accounting::getCashFlowStatement('2025-01-01', '2025-12-31');
// ['operating_activities' => x, 'investing_activities' => x, 'financing_activities' => x, 'net_change' => x]
```

### Fiscal year closing

```php
use Centrex\LaravelAccounting\Models\FiscalYear;

$fy = FiscalYear::where('name', '2025')->first();
Accounting::closeFiscalYear($fy);
// Transfers net income → Retained Earnings (3100) via closing JE, marks $fy->is_closed = true
```

## Web UI

All routes are protected by `web_middleware` (default `['web', 'auth']`) under the `web_prefix` (default `accounting`):

| Route | URL | Description |
| --- | --- | --- |
| `accounting.dashboard` | `/accounting/dashboard` | Overview dashboard |
| `accounting.accounts` | `/accounting/accounts` | Chart of accounts |
| `accounting.journal` | `/accounting/journal-entries` | Journal entries |
| `accounting.invoices` | `/accounting/invoices` | Invoice management |
| `accounting.bills` | `/accounting/bills` | Bill management |
| `accounting.customers` | `/accounting/customers` | Customer list |
| `accounting.vendors` | `/accounting/vendors` | Vendor list |
| `accounting.reports` | `/accounting/reports` | Financial reports |

## REST API

Base prefix: `api/accounting`. Default middleware: `['api', 'auth:sanctum']`.

| Method | Endpoint | Action |
| --- | --- | --- |
| GET/POST | `/api/accounting/accounts` | list / create |
| GET | `/api/accounting/accounts/{id}/balance` | current balance |
| POST | `/api/accounting/journal-entries` | create |
| POST | `/api/accounting/journal-entries/{id}/post` | post |
| POST | `/api/accounting/journal-entries/{id}/void` | void |
| GET/POST | `/api/accounting/invoices` | list / create |
| POST | `/api/accounting/invoices/{id}/post` | post |
| POST | `/api/accounting/invoices/{id}/payments` | record payment |
| GET/POST | `/api/accounting/bills` | list / create |
| POST | `/api/accounting/bills/{id}/post` | post |
| POST | `/api/accounting/bills/{id}/payments` | record payment |
| GET | `/api/accounting/reports/trial-balance` | trial balance |
| GET | `/api/accounting/reports/balance-sheet` | balance sheet |
| GET | `/api/accounting/reports/income-statement` | P&L |
| GET | `/api/accounting/reports/cash-flow` | cash flow |

## Testing

```bash
composer test        # full suite
composer test:unit   # pest only
composer test:types  # phpstan
composer lint        # pint
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [centrex](https://github.com/centrex)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
