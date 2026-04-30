# Laravel Accounting

[![Latest Version on Packagist](https://img.shields.io/packagist/v/centrex/laravel-accounting.svg?style=flat-square)](https://packagist.org/packages/centrex/laravel-accounting)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/centrex/laravel-accounting/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/centrex/laravel-accounting/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/centrex/laravel-accounting/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/centrex/laravel-accounting/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/centrex/laravel-accounting?style=flat-square)](https://packagist.org/packages/centrex/laravel-accounting)

Full double-entry accounting system for Laravel. Includes a chart of accounts, journal entries with two-step approval workflow, invoices, bills, expenses, customer/vendor ledgers, financial reports, month-end period closing with inventory reconciliation, fiscal year closing, budgets, and a Livewire UI with a complete REST API layer.

---

## Table of Contents

- [Installation](#installation)
- [Environment Variables](#environment-variables)
- [Core Concepts](#core-concepts)
- [Chart of Accounts](#chart-of-accounts)
- [Journal Entries — Two-Step Workflow](#journal-entries--two-step-workflow)
- [Invoices & Payments](#invoices--payments)
- [Bills & Vendor Payments](#bills--vendor-payments)
- [Invoice & Bill Charges / Discounts](#invoice--bill-charges--discounts)
- [Expenses](#expenses)
- [Customer & Vendor Ledger](#customer--vendor-ledger)
- [General Ledger](#general-ledger)
- [Financial Reports](#financial-reports)
- [SBU (Cost Centre) Filtering](#sbu-cost-centre-filtering)
- [Budgets](#budgets)
- [Period Closing (Month-End)](#period-closing-month-end)
- [Fiscal Year Closing](#fiscal-year-closing)
- [Inventory Financing](#inventory-financing)
- [Organizational Loans & SBU-wise Tracking](#organizational-loans--sbu-wise-tracking)
- [Real-World Example — Trading Company Month-End Close](#real-world-example--trading-company-month-end-close)
- [Authorization Gates](#authorization-gates)
- [Web UI Routes](#web-ui-routes)
- [REST API](#rest-api)
- [Artisan Commands](#artisan-commands)
- [Testing](#testing)

---

## Installation

```bash
composer require centrex/laravel-accounting
php artisan vendor:publish --tag="laravel-accounting-config"
php artisan migrate
```

Seed the standard chart of accounts (idempotent, safe to re-run):

```php
use Centrex\Accounting\Facades\Accounting;

Accounting::initializeChartOfAccounts();
```

Standard accounts created: Cash (1000), Bank (1100), Accounts Receivable (1200), Inventory (1300), Accounts Payable (2000), Sales Tax Payable (2300), Share Capital (3000), Retained Earnings (3100), Sales Revenue (4000), COGS (5000), and more.

Publish views for customisation:

```bash
php artisan vendor:publish --tag="laravel-accounting-views"
```

---

## Environment Variables

```env
ACCOUNTING_CURRENCY=BDT
ACCOUNTING_DB_CONNECTION=mysql          # optional separate DB connection for multi-tenancy
ACCOUNTING_TABLE_PREFIX=acct_
ACCOUNTING_FISCAL_START_MONTH=1         # 1 = January
ACCOUNTING_FISCAL_AUTO_CREATE=true      # auto-create fiscal periods
ACCOUNTING_ENFORCE_PERIOD_LOCK=true     # block posting to closed periods
ACCOUNTING_ROUNDING_TOLERANCE=0.005     # max debit/credit mismatch allowed
```

---

## Core Concepts

### Double-Entry Bookkeeping

Every `JournalEntry` has two or more lines. Debits must equal credits within the configured `rounding_tolerance` before an entry can be posted.

| Account Type | Normal Balance | Increases with | Decreases with |
| --- | --- | --- | --- |
| Asset | Debit | Debit | Credit |
| Liability | Credit | Credit | Debit |
| Equity | Credit | Credit | Debit |
| Revenue | Credit | Credit | Debit |
| Expense | Debit | Debit | Credit |

### Journal Entry Lifecycle

```
Draft ──► Submitted ──► Posted ──► Void
           (review)     (GL impact)
```

- **Draft** — being prepared, no GL impact
- **Submitted** — sent for approval, no GL impact
- **Posted** — hits the General Ledger, affects all reports
- **Void** — cancelled; a separate reversing entry must be used if previously posted

### WAC — Weighted Average Cost

Used for inventory valuation. Recalculated on every goods-in movement:

```
New WAC = (Current Stock Value + New Purchase Value)
          ────────────────────────────────────────────
                Current Qty + New Purchase Qty
```

**Example:**

| Event | Qty | Unit Cost | Total Value | WAC |
| --- | --- | --- | --- | --- |
| Opening | 100 | ৳ 50.00 | ৳ 5,000 | ৳ 50.00 |
| Purchase | +50 | ৳ 60.00 | +৳ 3,000 | **(5,000+3,000)÷150 = ৳ 53.33** |
| Sale | -80 | ৳ 53.33 | COGS = ৳ 4,266.67 | ৳ 53.33 |
| Balance | 70 | ৳ 53.33 | ৳ 3,733.33 | ৳ 53.33 |

WAC resets on each purchase. FIFO / LIFO batching is not used.

---

## Chart of Accounts

```php
use Centrex\Accounting\Models\Account;
use Centrex\Accounting\Enums\{AccountType, AccountSubtype};

// Create a custom account
$account = Account::create([
    'code'     => '1310',
    'name'     => 'Raw Materials Inventory',
    'type'     => 'asset',
    'subtype'  => 'current_asset',
    'currency' => 'BDT',
]);

// Create a child account (sub-account)
$sub = Account::create([
    'code'      => '1311',
    'name'      => 'Finished Goods Inventory',
    'type'      => 'asset',
    'subtype'   => 'current_asset',
    'parent_id' => $account->id,
]);

// Retrieve by code
$cash = Account::where('code', '1000')->first();

// Current balance of an account
$balance = $cash->getCurrentBalance(); // float
```

---

## Journal Entries — Two-Step Workflow

### Standard Flow (with approval)

```php
use Centrex\Accounting\Facades\Accounting;
use Centrex\Accounting\Models\JournalEntry;

// Step 1: Accountant creates a draft
$entry = Accounting::createJournalEntry([
    'date'        => '2026-04-30',
    'reference'   => 'RENT-APR-2026',
    'type'        => 'general',      // general | closing | adjustment
    'description' => 'April office rent payment',
    'currency'    => 'BDT',
    'lines' => [
        ['account_id' => $rentExpenseId, 'type' => 'debit',  'amount' => 75000],
        ['account_id' => $bankId,        'type' => 'credit', 'amount' => 75000],
    ],
]);
// $entry->status === JvStatus::DRAFT

// Step 2: Accountant submits for review
$entry->submit();
// $entry->status === JvStatus::SUBMITTED
// submitted_by / submitted_at are now recorded

// Step 3a: Reviewer approves → posted to GL
$entry->post();
// $entry->status === JvStatus::POSTED
// approved_by / approved_at are recorded

// Step 3b: Reviewer rejects → back to draft with a note
$entry->returnToDraft('Wrong expense account — use 5200 for admin costs');
// $entry->status === JvStatus::DRAFT
// $entry->reviewer_note is set
```

### Admin Bypass (post directly from draft)

```php
// Users with accounting.journal.post gate can skip the submit step
$entry = Accounting::createJournalEntry([...]);
$entry->post();  // Draft → Posted in one step
```

### Multi-Line Complex Entry

```php
// Record a purchase: goods received, tax input credit, and advance payment applied
$entry = Accounting::createJournalEntry([
    'date'        => '2026-04-15',
    'reference'   => 'PO-2026-047',
    'description' => 'Purchase of electronics — Samsung batch',
    'lines' => [
        // What we received
        ['account_id' => $inventoryId,    'type' => 'debit',  'amount' => 500000, 'description' => 'Electronics inventory'],
        ['account_id' => $vatInputId,     'type' => 'debit',  'amount' => 75000,  'description' => 'VAT input credit 15%'],
        // How we paid
        ['account_id' => $advanceToSupId, 'type' => 'credit', 'amount' => 100000, 'description' => 'Advance applied'],
        ['account_id' => $apId,           'type' => 'credit', 'amount' => 475000, 'description' => 'Balance payable to supplier'],
    ],
]);

$entry->submit();
$entry->post();
```

### Void and Reverse a Posted Entry

```php
// Void (marks as cancelled — does not reverse GL)
$entry->void();

// To reverse the GL effect, post a reversing entry manually
$reversal = Accounting::createJournalEntry([
    'date'        => today(),
    'reference'   => 'REV-' . $entry->entry_number,
    'type'        => 'adjustment',
    'description' => 'Reversal of ' . $entry->description,
    'lines' => $entry->lines->map(fn ($l) => [
        'account_id' => $l->account_id,
        'type'       => $l->type === 'debit' ? 'credit' : 'debit',
        'amount'     => $l->amount,
    ])->toArray(),
]);
$reversal->post();
```

---

## Invoices & Payments

### Create and Post an Invoice

```php
use Centrex\Accounting\Models\{Customer, Invoice, InvoiceItem};
use Centrex\Accounting\Facades\Accounting;

// Create customer
$customer = Customer::create([
    'code'          => 'CUST-001',
    'name'          => 'Rahman Brothers Ltd',
    'email'         => 'accounts@rahman.com',
    'phone'         => '+880 1711-000000',
    'credit_limit'  => 500000,
    'payment_terms' => 30,
    'currency'      => 'BDT',
]);

// Create invoice
$invoice = Invoice::create([
    'customer_id'     => $customer->id,
    'invoice_date'    => '2026-04-10',
    'due_date'        => '2026-05-10',
    'currency'        => 'BDT',
    'subtotal'        => 200000,
    'tax_amount'      => 30000,
    'discount_amount' => 10000,
    'total'           => 220000,
    'notes'           => 'Payment terms: Net 30. Cheque in favour of ABC Trading.',
]);

// Add line items
$invoice->items()->createMany([
    ['description' => 'Samsung TV 55"',  'quantity' => 10, 'unit_price' => 15000, 'total' => 150000],
    ['description' => 'Samsung Fridge',  'quantity' => 5,  'unit_price' => 10000, 'total' => 50000],
]);

// Post → creates JE: DR Accounts Receivable 1200 / CR Sales Revenue 4000 + CR Sales Tax 2300
$journalEntry = Accounting::postInvoice($invoice);
// Fires: InvoicePosted → SyncCustomerOutstanding
```

### Partial Payment, then Full Settlement

```php
// First payment — partial
Accounting::recordInvoicePayment($invoice, [
    'date'      => '2026-04-25',
    'amount'    => 100000,
    'method'    => 'bank_transfer',
    'reference' => 'TT-DHAKA-20260425',
]);
// $invoice->status → 'partially_settled'
// JE: DR Bank 1100 / CR AR 1200

// Second payment — settles the balance
Accounting::recordInvoicePayment($invoice, [
    'date'      => '2026-05-08',
    'amount'    => 120000,
    'method'    => 'cheque',
    'reference' => 'CHQ-00547',
]);
// $invoice->status → 'settled'
```

### Multi-Currency Invoice

```php
$invoice = Invoice::create([
    'customer_id'   => $exportCustomer->id,
    'invoice_date'  => today(),
    'due_date'      => today()->addDays(60),
    'currency'      => 'USD',
    'exchange_rate' => 110.50,      // 1 USD = 110.50 BDT (base currency)
    'subtotal'      => 5000,        // USD
    'total'         => 5000,
]);

// Post converts to BDT using exchange_rate
// JE posts 5000 × 110.50 = ৳ 552,500 to GL
Accounting::postInvoice($invoice);
```

---

## Bills & Vendor Payments

```php
use Centrex\Accounting\Models\{Vendor, Bill};

$vendor = Vendor::create([
    'code'          => 'VEND-001',
    'name'          => 'Global Electronics Ltd',
    'email'         => 'billing@globalelec.com',
    'payment_terms' => 45,
    'currency'      => 'BDT',
    'tax_id'        => 'BIN-123456789',
]);

$bill = Bill::create([
    'vendor_id'  => $vendor->id,
    'bill_date'  => '2026-04-05',
    'due_date'   => '2026-05-20',
    'subtotal'   => 400000,
    'tax_amount' => 60000,
    'total'      => 460000,
    'notes'      => 'Invoice #GEL-2026-0312',
]);

$bill->items()->createMany([
    ['description' => 'Samsung TV 55" — 30 units',  'quantity' => 30, 'unit_price' => 12000, 'total' => 360000],
    ['description' => 'Samsung Fridge — 8 units',   'quantity' => 8,  'unit_price' => 5000,  'total' => 40000],
]);

// Post → JE: DR Expense 5000 + DR VAT Input 1250 / CR Accounts Payable 2000
Accounting::postBill($bill);

// Pay vendor by bank transfer
Accounting::recordBillPayment($bill, [
    'date'      => '2026-05-15',
    'amount'    => 460000,
    'method'    => 'bank_transfer',
    'reference' => 'TT-OUT-20260515',
]);
// $bill->status → 'settled'
```

---

## Invoice & Bill Charges / Discounts

The invoice and bill detail pages support recording additional charges and price adjustments with full double-entry journal entries. All amounts are guarded by an AR/AP balance check so payments plus discounts can never exceed the effective receivable or payable balance.

### Invoice Charges (Delivery / COD)

Records an additional charge against a posted invoice — e.g., a delivery fee or cash-on-delivery charge billed to the customer. Journal entry: DR Accounts Receivable (1200) / CR Revenue account.

| Account | Code | Use |
| --- | --- | --- |
| Delivery Charge | 4210 | Standard courier / shipping charge |
| Cash on Delivery Charge | 4220 | COD handling fee |

```php
use Centrex\Accounting\Models\{Account, Expense, Invoice};
use Centrex\Accounting\Facades\Accounting;

$invoice      = Invoice::find($id);
$arAccount    = Account::where('code', '1200')->first();
$chargeAccount = Account::where('code', '4210')->first(); // or 4220

$expense = Expense::create([
    'chargeable_type' => Invoice::class,
    'chargeable_id'   => $invoice->id,
    'account_id'      => $chargeAccount->id,
    'expense_date'    => today(),
    'subtotal'        => 500,
    'total'           => 500,
    'paid_amount'     => 500,
    'currency'        => 'BDT',
    'status'          => 'paid',
    'payment_method'  => 'cash',
    'reference'       => $invoice->invoice_number,
]);

$entry = Accounting::createJournalEntry([
    'date'        => today(),
    'reference'   => $invoice->invoice_number,
    'type'        => 'general',
    'description' => 'Delivery Charge — ' . $invoice->invoice_number,
    'currency'    => 'BDT',
    'lines' => [
        ['account_id' => $arAccount->id,     'type' => 'debit',  'amount' => 500, 'description' => 'Delivery Charge'],
        ['account_id' => $chargeAccount->id, 'type' => 'credit', 'amount' => 500, 'description' => 'Delivery Charge Revenue'],
    ],
]);
$entry->post();
$expense->update(['journal_entry_id' => $entry->id]);
```

The UI equivalent is the **Record Charge** button on the invoice detail page (`/accounting/invoices/{id}`).

### Invoice Discounts (Sales Discount)

Records a price concession granted to the customer, reducing the AR balance. Journal entry: DR Sales Discount (6130) / CR Accounts Receivable (1200).

```php
$discountAccount = Account::where('code', '6130')->first();
$arAccount       = Account::where('code', '1200')->first();

$expense = Expense::create([
    'chargeable_type' => Invoice::class,
    'chargeable_id'   => $invoice->id,
    'account_id'      => $discountAccount->id,
    'expense_date'    => today(),
    'subtotal'        => 1000,
    'total'           => 1000,
    'paid_amount'     => 1000,
    'currency'        => 'BDT',
    'status'          => 'paid',
    'payment_method'  => 'cash',
    'reference'       => $invoice->invoice_number,
]);

$entry = Accounting::createJournalEntry([
    'date'        => today(),
    'reference'   => $invoice->invoice_number,
    'type'        => 'general',
    'description' => 'Sales Discount — ' . $invoice->invoice_number,
    'currency'    => 'BDT',
    'lines' => [
        ['account_id' => $discountAccount->id, 'type' => 'debit',  'amount' => 1000, 'description' => 'Sales Discount'],
        ['account_id' => $arAccount->id,       'type' => 'credit', 'amount' => 1000, 'description' => 'Discount applied to AR'],
    ],
]);
$entry->post();
$expense->update(['journal_entry_id' => $entry->id]);
```

AR balance guard — discount amount is validated not to exceed `effective_ar`. The same guard prevents over-payment.

```text
effective_ar = invoice->total + Σcharges(4210/4220) − invoice->paid_amount − Σdiscounts(6130)
```

---

### Bill Charges (Freight / Shipping)

Records an additional vendor cost against a posted bill — e.g., a courier charge or carriage fee.

Journal entry: DR Expense account / CR Accounts Payable (2000).

| Account | Code | Use |
| --- | --- | --- |
| Courier Bill / Charge | 6310 | Standard courier fee |
| Shipping / Transfer Bill (Carriage) | 6320 | Freight / sea or land carriage |
| Hand Carry Delivery | 6330 | Hand-carried goods delivery |
| Delivery Return Charge | 6340 | Return shipment fee |

```php
use Centrex\Accounting\Models\{Account, Bill, Expense};

$bill          = Bill::find($id);
$apAccount     = Account::where('code', '2000')->first();
$chargeAccount = Account::where('code', '6310')->first(); // or 6320, 6330, 6340

$expense = Expense::create([
    'chargeable_type' => Bill::class,
    'chargeable_id'   => $bill->id,
    'account_id'      => $chargeAccount->id,
    'expense_date'    => today(),
    'subtotal'        => 300,
    'total'           => 300,
    'paid_amount'     => 0,
    'currency'        => 'BDT',
    'status'          => 'approved',
    'payment_method'  => 'credit',
    'reference'       => $bill->bill_number,
]);

$entry = Accounting::createJournalEntry([
    'date'        => today(),
    'reference'   => $bill->bill_number,
    'type'        => 'general',
    'description' => 'Courier Charge — ' . $bill->bill_number,
    'currency'    => 'BDT',
    'lines' => [
        ['account_id' => $chargeAccount->id, 'type' => 'debit',  'amount' => 300, 'description' => 'Courier Charge'],
        ['account_id' => $apAccount->id,     'type' => 'credit', 'amount' => 300, 'description' => 'Accounts Payable'],
    ],
]);
$entry->post();
$expense->update(['journal_entry_id' => $entry->id]);
```

### Bill Discounts (Purchase Discount)

Records a price reduction granted by the vendor, reducing the AP balance. Journal entry: DR Accounts Payable (2000) / CR Purchase Discount (5500).

```php
$discountAccount = Account::where('code', '5500')->first();
$apAccount       = Account::where('code', '2000')->first();

$expense = Expense::create([
    'chargeable_type' => Bill::class,
    'chargeable_id'   => $bill->id,
    'account_id'      => $discountAccount->id,
    'expense_date'    => today(),
    'subtotal'        => 2000,
    'total'           => 2000,
    'paid_amount'     => 2000,
    'currency'        => 'BDT',
    'status'          => 'paid',
    'payment_method'  => 'cash',
    'reference'       => $bill->bill_number,
]);

$entry = Accounting::createJournalEntry([
    'date'        => today(),
    'reference'   => $bill->bill_number,
    'type'        => 'general',
    'description' => 'Purchase Discount — ' . $bill->bill_number,
    'currency'    => 'BDT',
    'lines' => [
        ['account_id' => $apAccount->id,       'type' => 'debit',  'amount' => 2000, 'description' => 'Discount applied to AP'],
        ['account_id' => $discountAccount->id, 'type' => 'credit', 'amount' => 2000, 'description' => 'Purchase Discount'],
    ],
]);
$entry->post();
$expense->update(['journal_entry_id' => $entry->id]);
```

AP balance guard — discount amount is validated not to exceed `effective_ap`.

```text
effective_ap = bill->total + Σcharges(6310/6320/6330/6340) − bill->paid_amount − Σdiscounts(5500)
```

The UI equivalent is the **Record Charge** and **Record Discount** buttons on the bill detail page (`/accounting/bills/{id}`).

### Journal Flow at a Glance

| Event | DR | CR |
| --- | --- | --- |
| Invoice delivery charge | AR `1200` | Delivery Charge `4210` / COD `4220` |
| Invoice sales discount | Sales Discount `6130` | AR `1200` |
| Bill freight charge | Courier/Shipping `6310–6340` | AP `2000` |
| Bill purchase discount | AP `2000` | Purchase Discount `5500` |

---

## Expenses

```php
use Centrex\Accounting\Models\Expense;

// Cash expense — paid immediately
$expense = Expense::create([
    'account_id'     => $officeSuppliesAccountId,
    'expense_date'   => today(),
    'subtotal'       => 12000,
    'tax_amount'     => 1800,
    'total'          => 13800,
    'payment_method' => 'cash',
    'vendor_name'    => 'Bashundhara City Shop',
    'notes'          => 'Printer cartridges and paper — Q2 office supplies',
]);

$expense->items()->createMany([
    ['description' => 'HP 67 Black Ink — 3 units',  'amount' => 6000],
    ['description' => 'A4 Paper — 10 reams',         'amount' => 6000],
]);

// Post → JE: DR Office Supplies 5400 / CR Cash 1000
Accounting::postExpense($expense);

// Credit expense — pay later
$creditExpense = Expense::create([
    'account_id'     => $marketingAccountId,
    'expense_date'   => today(),
    'due_date'       => today()->addDays(15),
    'total'          => 50000,
    'payment_method' => 'credit',  // creates AP entry
    'vendor_name'    => 'DigiAds Bangladesh',
]);

Accounting::postExpense($creditExpense);
// JE: DR Marketing Expense 5600 / CR Accounts Payable 2000

// Pay when due
Accounting::recordExpensePayment($creditExpense, [
    'date'   => today()->addDays(15),
    'amount' => 50000,
    'method' => 'bank_transfer',
]);
```

---

## Customer & Vendor Ledger

### Per-Entity Ledger (Statement of Account)

```php
use Centrex\Accounting\Models\Customer;

// Navigate to the individual ledger page via web UI:
// GET /accounting/customers/{customer}/ledger?startDate=2026-01-01&endDate=2026-04-30

// Or query the data programmatically:
$customer = Customer::find($id);

// Outstanding balance attribute (active invoices only)
$outstanding = $customer->total_outstanding; // float — sum of (total - paid_amount) for issued/partial/overdue invoices
```

### Ledger Index (All Customers / Vendors)

```
GET /accounting/ledger/customers   — paginated list with outstanding per customer
GET /accounting/ledger/vendors     — paginated list with outstanding per vendor
```

Each index row shows: total invoiced, total received, and net outstanding for active (issued/partial/overdue) documents only.

---

## General Ledger

```php
use Centrex\Accounting\Facades\Accounting;

// All accounts, full year
$gl = Accounting::getGeneralLedger(
    accountId: null,
    startDate: '2026-01-01',
    endDate:   '2026-04-30',
    sbuCode:   null,
);

// Single account with SBU filter
$gl = Accounting::getGeneralLedger(
    accountId: $bankAccountId,
    startDate: '2026-04-01',
    endDate:   '2026-04-30',
    sbuCode:   'OCT',           // Strategic Business Unit code
);

// Response structure
foreach ($gl['accounts'] as $section) {
    $section['account'];           // Account model
    $section['opening_balance'];   // float — balance before start date
    $section['period_debits'];     // float
    $section['period_credits'];    // float
    $section['closing_balance'];   // float
    $section['entries'];           // array of posted GL lines with running_balance
}
```

---

## Financial Reports

```php
use Centrex\Accounting\Facades\Accounting;

// Trial Balance — checks if all posted entries balance
$tb = Accounting::getTrialBalance('2026-01-01', '2026-04-30');
// [
//   'accounts'      => [['account' => ..., 'debit' => x, 'credit' => x, 'balance' => x], ...],
//   'total_debits'  => 1250000.00,
//   'total_credits' => 1250000.00,
//   'is_balanced'   => true,
// ]

// Balance Sheet — point-in-time snapshot
$bs = Accounting::getBalanceSheet('2026-04-30');
// [
//   'assets'      => ['accounts' => [...], 'total' => 8500000.00],
//   'liabilities' => ['accounts' => [...], 'total' => 2300000.00],
//   'equity'      => ['accounts' => [...], 'total' => 5870000.00, 'total_with_income' => 6200000.00],
//   'is_balanced' => true,
// ]

// Income Statement (P&L)
$pl = Accounting::getIncomeStatement('2026-04-01', '2026-04-30');
// [
//   'revenue'      => ['accounts' => [...], 'total' => 1500000.00],
//   'expenses'     => ['accounts' => [...], 'total' => 1170000.00],
//   'gross_profit' => 330000.00,
//   'net_income'   => 330000.00,
// ]

// Cash Flow Statement
$cf = Accounting::getCashFlowStatement('2026-04-01', '2026-04-30');
// [
//   'operating_activities'  => 280000.00,
//   'investing_activities'  => -50000.00,
//   'financing_activities'  => 0.00,
//   'net_change'            => 230000.00,
// ]
```

---

## SBU (Cost Centre) Filtering

All reports and the General Ledger accept a `sbuCode` parameter to filter by Strategic Business Unit. The SBU code is stored on each `JournalEntry` row.

```php
// Manual SBU tagging
$entry = Accounting::createJournalEntry([
    'date'        => today(),
    'description' => 'Dhaka branch rent',
    'sbu_code'    => 'DHK',
    'lines'       => [...],
]);

// Reports filtered by SBU
$pl  = Accounting::getIncomeStatement('2026-01-01', '2026-04-30', sbuCode: 'DHK');
$gl  = Accounting::getGeneralLedger(null, '2026-04-01', '2026-04-30', sbuCode: 'DHK');
$tb  = Accounting::getTrialBalance('2026-01-01', '2026-04-30', sbuCode: 'DHK');
$bs  = Accounting::getBalanceSheet('2026-04-30', sbuCode: 'DHK');
```

---

## Budgets

```php
use Centrex\Accounting\Facades\Accounting;
use Centrex\Accounting\Models\FiscalYear;

$fy = FiscalYear::where('is_current', true)->first();

$budget = Accounting::createBudget([
    'name'         => 'Q2 2026 Operating Budget',
    'fiscal_year_id' => $fy->id,
    'period_start' => '2026-04-01',
    'period_end'   => '2026-06-30',
    'currency'     => 'BDT',
    'items' => [
        ['account_id' => $rentExpenseId,     'description' => 'Office rent Q2',      'amount' => 225000],
        ['account_id' => $salariesId,         'description' => 'Staff salaries Q2',   'amount' => 900000],
        ['account_id' => $marketingId,        'description' => 'Digital marketing',   'amount' => 150000],
        ['account_id' => $officeSuppliesId,   'description' => 'Office supplies',     'amount' => 36000],
    ],
]);

// Approve the budget
Accounting::approveBudget($budget, auth()->id());

// Budget vs Actual comparison
$comparison = Accounting::getBudgetVsActual($budget);
foreach ($comparison['items'] as $item) {
    echo $item['account']->name . ': ';
    echo 'Budget ৳' . number_format($item['budgeted']) . ', ';
    echo 'Actual ৳' . number_format($item['actual'])   . ', ';
    echo 'Remaining ৳' . number_format($item['remaining']) . ' ';
    echo '(' . $item['percentage_used'] . '% used)' . PHP_EOL;
}
```

---

## Period Closing (Month-End)

### Pre-Close Checks

```php
use Centrex\Accounting\Facades\Accounting;
use Centrex\Accounting\Models\FiscalPeriod;

$period = FiscalPeriod::where('name', 'April 2026')->first();

$checks = Accounting::getPeriodCloseChecks($period);
// [
//   'unposted_journals' => 2,   // ← BLOCKER: must be 0 before closing
//   'open_invoices'     => 5,   // warning only (still editable after close)
//   'open_bills'        => 1,   // warning only
//   'has_blockers'      => true,
//   'has_warnings'      => true,
// ]

if ($checks['has_blockers']) {
    // Resolve the 2 unposted entries first
}
```

### Close the Period

```php
$result = Accounting::closeFiscalPeriod(
    period: $period,
    snapshotInventory: true,   // requires laravel-inventory with ERP bridge enabled
);

// $result['period']    — the now-closed FiscalPeriod model
// $result['inventory'] — inventory reconciliation data (or null)

if ($result['inventory']) {
    $inv = $result['inventory'];
    echo "Inventory snapshot: {$inv['snapshot_count']} lines captured\n";
    echo "Physical value:  ৳ " . number_format($inv['physical_value'], 2) . "\n";
    echo "GL balance (1300): ৳ " . number_format($inv['gl_balance'], 2) . "\n";
    echo "Variance:          ৳ " . number_format($inv['variance'], 2) . "\n";
    echo "Reconciled: " . ($inv['is_reconciled'] ? 'YES ✓' : 'NO — post an adjustment') . "\n";
}
```

After closing, the period is **locked**. Attempting to post a journal entry dated within a closed period throws `AccountingException`:

```php
// ACCOUNTING_ENFORCE_PERIOD_LOCK=true (default)
$entry->post();
// ✗ AccountingException: Cannot post to a closed period.
//   Entry date 2026-04-15 falls in a locked accounting period.

// Internal closing/adjusting entries bypass the lock automatically
// (closeFiscalYear() and closeFiscalPeriod() call post(bypassPeriodLock: true) internally)
```

To disable the lock temporarily for data migrations:

```env
ACCOUNTING_ENFORCE_PERIOD_LOCK=false
```

---

## Fiscal Year Closing

```php
use Centrex\Accounting\Models\FiscalYear;
use Centrex\Accounting\Facades\Accounting;

$fy = FiscalYear::where('name', '2025-26')->first();

Accounting::closeFiscalYear($fy);
// 1. Calculates net income for the year
// 2. Creates closing JE: DR Income Summary (3900) / CR Retained Earnings (3100)
// 3. Posts the entry (bypasses period lock)
// 4. Sets fiscal_year.is_closed = true
```

---

## Inventory Financing

Inventory financing is a revolving short-term credit facility where a lender advances funds specifically to purchase stock. The inventory itself serves as collateral. Each lender is registered as a **facility** and receives its own pair of dedicated GL sub-accounts, keeping balances fully isolated per lender.

### Account Structure

`initializeChartOfAccounts()` seeds these parent accounts automatically:

| Code | Name | Type | Notes |
| --- | --- | --- | --- |
| `2150` | Inventory Financing Payable | Liability | Parent — never posted to directly |
| `2151–2169` | Per-lender payable | Liability | Auto-created on `addFinancingFacility()` |
| `2170` | Accrued Interest — Inventory Financing | Liability | Parent — never posted to directly |
| `2171–2189` | Per-lender accrued interest | Liability | Auto-created on `addFinancingFacility()` |
| `6710` | Interest Expense — Inventory Financing | Expense | Child of `6700 Interest Expense` |

### Register Lenders

Each call to `addFinancingFacility()` creates the facility record and allocates the next available sub-account codes under `2150` and `2170`:

```php
use Centrex\Accounting\Facades\Accounting;

// Lender 1 — bank (gets 2151 + 2171)
$brac = Accounting::addFinancingFacility(
    lenderName:  'BRAC Bank Ltd',
    lenderType:  'bank',
    monthlyRate: 0.02,          // 2% per month
    creditLimit: 5_000_000.00,  // ৳50 lakh ceiling
    contact:     'Md. Hasan, 01700-000001',
);

// Lender 2 — private party (gets 2152 + 2172)
$karim = Accounting::addFinancingFacility(
    lenderName:  'Mr. Abdul Karim',
    lenderType:  'private',
    monthlyRate: 0.02,
    creditLimit: 1_500_000.00,
);

// Lender 3 — microfinance institution (gets 2153 + 2173)
$buro = Accounting::addFinancingFacility(
    lenderName:  'BURO Bangladesh',
    lenderType:  'mfi',
    monthlyRate: 0.02,
    creditLimit: 800_000.00,
);
```

### Draw Down Funds to Purchase Inventory

Each draw-down posts immediately as a draft journal entry. Submit and approve via the normal two-step workflow:

```php
// BRAC Bank advances ৳20,00,000 for Samsung batch purchase
$entry = Accounting::drawdownFinancing(
    facility:    $brac,
    amount:      2_000_000.00,
    date:        '2026-04-05',
    reference:   'BRAC-DD-2026-001',
    description: 'Samsung Galaxy A-series batch — PO-2026-047',
);
$entry->submit();    // accountant submits for review
$entry->post();      // finance manager approves
// DR Inventory 1300  ৳20,00,000
// CR BRAC Bank Payable 2151  ৳20,00,000

// Private party advances ৳8,00,000 for accessories stock
$entry2 = Accounting::drawdownFinancing(
    facility:  $karim,
    amount:    800_000.00,
    date:      '2026-04-10',
    reference: 'KARIM-DD-2026-001',
);
$entry2->submit();
$entry2->post();
// DR Inventory 1300  ৳8,00,000
// CR Mr. Abdul Karim Payable 2152  ৳8,00,000
```

A second draw-down from the same lender stacks on top:

```php
// Mid-month top-up from BRAC Bank
Accounting::drawdownFinancing($brac, 500_000.00, '2026-04-18', 'BRAC-DD-2026-002')
    ->submit();
// Outstanding under BRAC: 2151 balance = ৳25,00,000
// Attempting to exceed credit limit throws RuntimeException
```

### Month-End Interest Accrual

Run once at month-end for all active facilities in a single call, or accrue per-facility for fine-grained control:

```php
// Accrue all active facilities at once (run via scheduler on the 28th)
$results = Accounting::accrueAllFinancingInterest(date: '2026-04-30');
// Returns: [facility_id => JournalEntry|null]

// Per-facility breakdown:
// BRAC Bank — principal ৳25,00,000 × 2% = ৳50,000
//   DR Interest Expense — Inv. Financing 6710  ৳50,000
//   CR Accrued Interest — BRAC Bank 2171       ৳50,000

// Mr. Karim — principal ৳8,00,000 × 2% = ৳16,000
//   DR Interest Expense — Inv. Financing 6710  ৳16,000
//   CR Accrued Interest — Mr. Abdul Karim 2172 ৳16,000

// Accrue a single facility manually (for corrections/adjustments)
$je = Accounting::accrueFinancingInterest($brac, date: '2026-04-30');
// Returns null and skips cleanly if outstanding principal is zero
```

Automate via the scheduler so it never gets missed:

```php
// routes/console.php
Schedule::call(fn () => Accounting::accrueAllFinancingInterest())
    ->monthlyOn(28, '23:00')
    ->name('accounting:accrue-inventory-interest')
    ->withoutOverlapping();
```

### Pay the Interest

```php
// Pay BRAC Bank interest for April
Accounting::payFinancingInterest(
    facility:  $brac,
    amount:    50_000.00,
    date:      '2026-05-05',
    reference: 'BRAC-INT-APR-2026',
);
// DR Accrued Interest — BRAC Bank 2171  ৳50,000
// CR Bank Account 1100                  ৳50,000

// Pay private party interest
Accounting::payFinancingInterest($karim, 16_000.00, '2026-05-05', 'KARIM-INT-APR-2026');
```

### Repay Principal as Inventory Sells

Repay each lender as the financed goods are sold and cash comes in. The method validates the amount does not exceed outstanding principal:

```php
// ৳10,00,000 of Samsung stock sold — repay BRAC proportionally
Accounting::repayFinancing(
    facility:  $brac,
    amount:    1_000_000.00,
    date:      '2026-05-10',
    reference: 'BRAC-REPAY-2026-001',
);
// DR BRAC Bank Payable 2151  ৳10,00,000
// CR Bank Account 1100       ৳10,00,000
// Remaining BRAC balance: 2151 = ৳15,00,000

// Full repayment of private party facility
Accounting::repayFinancing($karim, 800_000.00, '2026-05-15', 'KARIM-REPAY-2026-001');
// 2152 balance = ৳0 — facility fully settled
```

### Portfolio Summary

```php
$summary = Accounting::getFinancingSummary();

// Returns per-facility:
// [
//   'lender_name'           => 'BRAC Bank Ltd',
//   'lender_type'           => 'bank',
//   'is_active'             => true,
//   'monthly_rate'          => 0.02,
//   'credit_limit'          => 5000000.0,
//   'outstanding_principal' => 1500000.0,   // ৳15,00,000 remaining
//   'accrued_interest'      => 0.0,         // paid already
//   'monthly_interest'      => 30000.0,     // next month estimate
//   'principal_account'     => '2151 Inv. Financing Payable — BRAC Bank Ltd',
//   'interest_account'      => '2171 Accrued Interest — BRAC Bank Ltd',
// ],
// [
//   'lender_name'           => 'Mr. Abdul Karim',
//   'outstanding_principal' => 0.0,         // fully repaid
//   'monthly_interest'      => 0.0,
//   ...
// ],
```

### Pre-Close Check Integration

The period-close wizard automatically flags financing imbalances before locking the period:

```php
$checks = Accounting::getPeriodCloseChecks($period);

// Example blocker if financing > inventory value:
// [
//   'type'    => 'blocker',
//   'message' => 'Inventory financing (৳28,00,000) exceeds inventory GL balance (৳24,50,000).
//                 Possible unrecorded goods receipt or over-draw.',
// ]
```

### Journal Flow at a Glance

| Event | DR | CR |
| --- | --- | --- |
| Draw down (buy inventory) | Inventory `1300` | Lender Payable `215x` |
| Month-end interest accrual | Interest Expense `6710` | Accrued Interest `217x` |
| Pay interest | Accrued Interest `217x` | Bank `1100` |
| Repay principal | Lender Payable `215x` | Bank `1100` |

---

## Organizational Loans & SBU-wise Tracking

Covers every loan a company takes from an external lender or an internal entity — term loans, working-capital lines, inter-company advances, director loans, equipment finance, and overdraft facilities. Each loan is a **facility** with its own GL sub-accounts. The `sbu_code` field on the facility tags every journal entry automatically, so SBU-filtered P&L and balance sheets work without any extra steps.

### Loan Types

| `loan_type` | Typical use |
| --- | --- |
| `term_loan` | Fixed-amount, fixed-tenure bank loan |
| `working_capital` | Short-term revolving credit for operations |
| `inter_company` | Advance from / to a sister or parent company |
| `director` | Unsecured loan from a director / shareholder |
| `equipment` | Asset-backed financing for machinery or vehicles |
| `overdraft` | Bank overdraft against current account |
| `bridge` | Short-term bridge pending long-term funding |

### Account Structure

`initializeChartOfAccounts()` seeds these parent accounts automatically:

| Code | Name | Term | Notes |
| --- | --- | --- | --- |
| `2400` | Short-term Loans Payable | Short | Parent — never posted to directly |
| `2401–2419` | Per-facility payable | Short | Auto-created on `addLoanFacility()` |
| `2420` | Accrued Interest — Short-term Loans | Short | Parent |
| `2421–2439` | Per-facility accrued interest | Short | Auto-created |
| `2500` | Long-term Loans Payable | Long | Parent |
| `2501–2519` | Per-facility payable | Long | Auto-created |
| `2520` | Accrued Interest — Long-term Loans | Long | Parent |
| `2521–2539` | Per-facility accrued interest | Long | Auto-created |
| `6720` | Interest Expense — Short-term Loans | — | Child of `6700` |
| `6730` | Interest Expense — Long-term Loans | — | Child of `6700` |

The `loan_term` field drives which range is used: `short_term` (< 12 months) → `240x/242x`; `long_term` (≥ 12 months) → `250x/252x`.

---

### Register Loan Facilities

```php
use Centrex\Accounting\Facades\Accounting;

// 1. Short-term working-capital line from Dutch-Bangla Bank — North SBU
$wcLine = Accounting::addLoanFacility(
    lenderName:    'Dutch-Bangla Bank Ltd',
    loanType:      'working_capital',
    loanTerm:      'short_term',
    monthlyRate:   0.02,                // 2% per month
    sbuCode:       'NORTH',             // all JEs tagged NORTH
    loanAmount:    3_000_000.00,
    disbursedAt:   '2026-04-01',
    dueAt:         '2026-10-01',
    tenureMonths:  6,
    contact:       'Branch Manager, Gulshan',
);
// → creates 2401 "Working Capital Payable — Dutch-Bangla Bank Ltd"
// → creates 2421 "Accrued Interest — Dutch-Bangla Bank Ltd"

// 2. Long-term term loan from BRAC Bank — South SBU
$termLoan = Accounting::addLoanFacility(
    lenderName:   'BRAC Bank Ltd',
    loanType:     'term_loan',
    loanTerm:     'long_term',
    monthlyRate:  0.02,
    sbuCode:      'SOUTH',
    loanAmount:   10_000_000.00,
    disbursedAt:  '2026-01-01',
    dueAt:        '2028-12-31',
    tenureMonths: 36,
);
// → creates 2501 "Term Loan Payable — BRAC Bank Ltd"
// → creates 2521 "Accrued Interest — BRAC Bank Ltd"

// 3. Inter-company advance from parent — Head Office SBU
$icLoan = Accounting::addLoanFacility(
    lenderName:  'ABC Holdings Ltd (Parent)',
    loanType:    'inter_company',
    loanTerm:    'short_term',
    monthlyRate: 0.02,
    sbuCode:     'HO',
    loanAmount:  5_000_000.00,
);
// → creates 2402 / 2422

// 4. Director loan — no SBU (company-wide)
$directorLoan = Accounting::addLoanFacility(
    lenderName:  'Mr. Rahim (Director)',
    loanType:    'director',
    loanTerm:    'short_term',
    monthlyRate: 0.02,
    loanAmount:  1_000_000.00,
);
// → creates 2403 / 2423 — sbu_code = null, JEs carry no SBU tag
```

---

### Drawdown — Receive Loan Proceeds

```php
// Dutch-Bangla working-capital: ৳30,00,000 received into bank
$entry = Accounting::drawdownLoan(
    facility:  $wcLine,
    amount:    3_000_000.00,
    date:      '2026-04-01',
    reference: 'DBBL-WC-2026-001',
);
$entry->submit();
$entry->post();
// DR Bank 1100               ৳30,00,000   [sbu_code = NORTH]
// CR Working Capital Payable 2401  ৳30,00,000

// BRAC term loan — first tranche ৳60,00,000
Accounting::drawdownLoan($termLoan, 6_000_000.00, '2026-04-01', 'BRAC-TL-2026-T1')
    ->submit();
// DR Bank 1100              ৳60,00,000   [sbu_code = SOUTH]
// CR Term Loan Payable 2501  ৳60,00,000

// Second tranche ৳40,00,000 a month later
Accounting::drawdownLoan($termLoan, 4_000_000.00, '2026-05-01', 'BRAC-TL-2026-T2')
    ->submit();
// Total outstanding on 2501: ৳1,00,00,000

// Inter-company advance — override SBU at draw-down level if needed
Accounting::drawdownLoan(
    facility: $icLoan,
    amount:   5_000_000.00,
    date:     '2026-04-10',
    reference: 'IC-ADV-2026-001',
    sbuCode:  'EAST',   // overrides facility-level 'HO' for this specific entry
);
```

---

### Month-End Interest Accrual

```php
// Accrue all active loan facilities in one call
$results = Accounting::accrueAllLoanInterest(date: '2026-04-30');

// Facility-by-facility breakdown:
// Dutch-Bangla WC: ৳30,00,000 × 2% = ৳60,000  [NORTH]
//   DR Interest Expense — Short-term 6720  ৳60,000
//   CR Accrued Interest — DBBL 2421         ৳60,000

// BRAC Term Loan: ৳60,00,000 × 2% = ৳1,20,000  [SOUTH]
//   DR Interest Expense — Long-term 6730    ৳1,20,000
//   CR Accrued Interest — BRAC 2521          ৳1,20,000

// Director loan: ৳10,00,000 × 2% = ৳20,000  [no SBU]
//   DR Interest Expense — Short-term 6720   ৳20,000
//   CR Accrued Interest — Mr. Rahim 2423     ৳20,000

foreach ($results as $facilityId => $je) {
    if ($je) {
        $je->submit();
        $je->post();
    }
}

// Accrue a single facility (e.g., missed in bulk run)
$je = Accounting::accrueLoanInterest($icLoan, date: '2026-04-30');

// Scheduler — run on the 28th of each month at 11 PM
Schedule::call(fn () => Accounting::accrueAllLoanInterest())
    ->monthlyOn(28, '23:00')
    ->name('accounting:accrue-loan-interest')
    ->withoutOverlapping();
```

---

### Pay Interest

```php
// Pay Dutch-Bangla interest for April
Accounting::payLoanInterest(
    facility:  $wcLine,
    amount:    60_000.00,
    date:      '2026-05-05',
    reference: 'DBBL-INT-APR26',
);
// DR Accrued Interest — DBBL 2421  ৳60,000   [sbu_code = NORTH]
// CR Bank 1100                      ৳60,000

// Pay BRAC interest
Accounting::payLoanInterest($termLoan, 120_000.00, '2026-05-05', 'BRAC-INT-APR26');
```

---

### Repay Principal

```php
// Monthly instalment on working-capital line: ৳5,00,000
Accounting::repayLoan(
    facility:  $wcLine,
    amount:    500_000.00,
    date:      '2026-05-01',
    reference: 'DBBL-REPAY-APR26',
);
// DR Working Capital Payable 2401  ৳5,00,000   [sbu_code = NORTH]
// CR Bank 1100                      ৳5,00,000

// Full director loan repayment
Accounting::repayLoan($directorLoan, 1_000_000.00, '2026-05-15', 'DIR-REPAY-2026');
// 2403 balance = ৳0 — facility fully settled
```

---

### SBU-wise Loan Portfolio

```php
// All facilities, all SBUs
$all = Accounting::getLoanSummary();

// Only NORTH SBU
$north = Accounting::getLoanSummary(sbuCode: 'NORTH');

// Returns per facility:
// [
//   'lender_name'           => 'Dutch-Bangla Bank Ltd',
//   'loan_type'             => 'working_capital',
//   'loan_term'             => 'short_term',
//   'sbu_code'              => 'NORTH',
//   'monthly_rate'          => 0.02,
//   'loan_amount'           => 3000000.0,
//   'disbursed_at'          => '2026-04-01',
//   'due_at'                => '2026-10-01',
//   'months_remaining'      => 5,
//   'outstanding_principal' => 2500000.0,   // after one instalment
//   'accrued_interest'      => 0.0,         // paid
//   'monthly_interest'      => 50000.0,     // next estimate on reduced balance
//   'principal_account'     => '2401 Working Capital Payable — Dutch-Bangla Bank Ltd',
//   'interest_account'      => '2421 Accrued Interest — Dutch-Bangla Bank Ltd',
// ]
```

---

### SBU-Filtered Financial Reports

Because every drawdown, accrual, and repayment journal entry is tagged with `sbu_code`, the standard report methods filter correctly with no extra work:

```php
// NORTH SBU income statement — interest expense attributed correctly
$pl = Accounting::getIncomeStatement('2026-04-01', '2026-04-30', sbuCode: 'NORTH');
// expenses will include ৳60,000 interest on the Dutch-Bangla WC line

// SOUTH SBU balance sheet — term loan liability appears under long-term
$bs = Accounting::getBalanceSheet('2026-04-30', sbuCode: 'SOUTH');
// liabilities will include 2501 = ৳1,00,00,000 (both tranches)

// Head-office consolidated trial balance (no SBU filter)
$tb = Accounting::getTrialBalance('2026-04-01', '2026-04-30');
// Includes all SBUs — 2400-parent and 2500-parent roll up correctly
```

---

### Journal Flow at a Glance

| Event | DR | CR | SBU tag |
| --- | --- | --- | --- |
| Receive loan proceeds | Bank `1100` | Loan Payable `240x`/`250x` | Facility SBU |
| Month-end interest accrual | Interest Expense `6720`/`6730` | Accrued Interest `242x`/`252x` | Facility SBU |
| Pay interest | Accrued Interest `242x`/`252x` | Bank `1100` | Facility SBU |
| Repay principal | Loan Payable `240x`/`250x` | Bank `1100` | Facility SBU |

---

## Real-World Example — Trading Company Month-End Close

**Scenario:** ABC Trading Ltd imports electronics from South Korea and sells in Bangladesh. The company carries two active inventory financing facilities — BRAC Bank (৳50 lakh limit, 2%/month) and a private party Mr. Karim (৳15 lakh limit, 2%/month). April 2026 had 80 purchase transactions, 140 sales orders, and 6 stock adjustments.

### Week 4 Pre-Close Workflow (Days 27–30)

#### Day 27–28: Cut-Off Procedures

Ensure all economic events before April 30 are captured:

```php
// 1. Every goods received note (GRN) for April must be posted
//    → JE: DR Inventory 1300 ৳ 4,218,500 / CR Accounts Payable 2000 ৳ 4,218,500

// 2. All April sale fulfillments posted
//    → JE: DR COGS 5000 ৳ 2,940,000 / CR Inventory 1300 ৳ 2,940,000

// 3. All customer invoices for April shipments issued
//    → JE: DR AR 1200 ৳ 5,100,000 / CR Sales Revenue 4000 ৳ 4,500,000
//                                   / CR VAT Payable 2300  ৳   600,000

// 4. All vendor bills for April purchases posted
//    → JE: DR Inventory 1300 ৳ 4,200,000 / DR VAT Input ৳ 630,000
//         / CR Accounts Payable 2000 ৳ 4,830,000

// 5. Bank receipts from customers posted
Accounting::recordInvoicePayment($invoice, [
    'date' => '2026-04-28', 'amount' => 2800000, 'method' => 'bank_transfer',
]);

// 6. Vendor payments posted
Accounting::recordBillPayment($bill, [
    'date' => '2026-04-28', 'amount' => 3200000, 'method' => 'bank_transfer',
]);
```

#### Day 29: Manual Adjusting Entries

```php
// Accrual: April electricity bill — received but not invoiced yet
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
$entry->post(); // Approved by Finance Manager

// Depreciation: Monthly depreciation for equipment
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

// Inventory financing interest accrual — all active facilities
// BRAC Bank: outstanding ৳38,00,000 × 2% = ৳76,000
// Mr. Karim:  outstanding ৳12,00,000 × 2% = ৳24,000
$accruals = Accounting::accrueAllFinancingInterest(date: '2026-04-30');

foreach ($accruals as $facilityId => $je) {
    if ($je) {
        $je->submit();
        $je->post(); // Finance Manager approves each
    }
}
// Total interest expense booked: ৳1,00,000
// DR Interest Expense — Inv. Financing 6710  ৳1,00,000
// CR Accrued Interest — BRAC Bank 2171        ৳76,000
// CR Accrued Interest — Mr. Abdul Karim 2172  ৳24,000
```

#### Day 30: Run Pre-Close Checks

```php
$period = FiscalPeriod::where('name', 'April 2026')->first();

$checks = Accounting::getPeriodCloseChecks($period);
// unposted_journals: 0  ✓
// open_invoices:     3  (3 customers haven't paid — OK, will carry forward)
// open_bills:        1  (1 vendor bill payment due May 15 — OK)
// has_blockers:      false  ✓  Ready to close
```

#### Day 30: Review Income Statement Before Closing

```php
$pl = Accounting::getIncomeStatement('2026-04-01', '2026-04-30');

// Revenue
//   Sales Revenue (4000):    ৳ 51,00,000
//   Total Revenue:           ৳ 51,00,000

// Expenses
//   COGS (5000):             ৳ 29,40,000
//   Office Rent (5100):      ৳    75,000
//   Salaries (5200):         ৳  3,00,000
//   Marketing (5600):        ৳    50,000
//   Utilities (5700):        ৳    18,000
//   Depreciation (5800):     ৳    25,000
//   Total Expenses:          ৳ 34,08,000

// Net Profit for April 2026: ৳ 16,92,000  ✓
```

#### Day 30: Physical Inventory Count

Warehouse team counts all items and updates system stock levels. A 3-unit shrinkage of Samsung TVs (WAC ৳ 12,500 each) is found:

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

#### Day 30: Close the Period + Inventory Snapshot

```php
$result = Accounting::closeFiscalPeriod($period, snapshotInventory: true);

// Inventory reconciliation output:
// Snapshot lines:    427 (product × warehouse combinations)
// Physical value:    ৳ 4,21,85,000.00
// GL balance (1300): ৳ 4,21,85,000.00
// Variance:          ৳          0.00  ✓ Reconciled

// Period is now LOCKED — no more entries can be posted with April dates
```

#### Final Balance Sheet — April 30, 2026

```php
$bs = Accounting::getBalanceSheet('2026-04-30');

// ASSETS
//   Cash (1000):               ৳  45,00,000
//   Bank (1100):               ৳  82,50,000
//   Accounts Receivable (1200):৳  24,60,000   (3 open invoices)
//   Inventory (1300):          ৳ 4,21,85,000  (verified by snapshot)
//   Total Assets:              ৳ 5,73,95,000

// LIABILITIES
//   Accounts Payable (2000):          ৳  38,50,000   (1 open bill)
//   Inv. Financing Payable — BRAC (2151): ৳  38,00,000
//   Inv. Financing Payable — Karim (2152):৳  12,00,000
//   Accrued Interest — BRAC (2171):   ৳      76,000   (booked Apr 30, paid May 5)
//   Accrued Interest — Karim (2172):  ৳      24,000
//   VAT Payable (2300):               ৳   6,00,000
//   Accrued Liabilities:              ৳      18,000
//   Total Liabilities:                ৳  95,68,000

// EQUITY
//   Share Capital (3000):      ৳ 4,96,85,000
//   Retained Earnings (3100):  ৳  15,00,000   (prior periods)
//   Current Period Income:     ৳  15,92,000   (April profit after ৳1,00,000 financing interest)
//   Total Equity:              ৳ 5,27,77,000

// BALANCE CHECK: ৳ 5,73,95,000  ✓  (assets unchanged; liabilities + equity = assets)
```

May 1 — a new period opens automatically. The April numbers are frozen.

---

## Authorization Gates

The package registers fine-grained gates. Override any gate in your `AppServiceProvider`:

```php
// Grant full access via the super-gate
Gate::define('accounting-admin', fn ($user) => $user->hasRole('accountant'));

// Or override individual abilities
Gate::define('accounting.journal.submit', fn ($user) => $user->hasRole(['accountant', 'junior_accountant']));
Gate::define('accounting.journal.post',   fn ($user) => $user->hasRole(['finance_manager', 'cfo']));
Gate::define('accounting.fiscal-year.close', fn ($user) => $user->hasRole('cfo'));
```

Available gates:

| Gate | Description |
| --- | --- |
| `accounting.journal.view` | See journal entries |
| `accounting.journal.create` | Create/edit draft entries |
| `accounting.journal.submit` | Submit entry for approval |
| `accounting.journal.post` | Approve and post to GL |
| `accounting.journal.void` | Void a posted entry |
| `accounting.invoice.view` | View invoices |
| `accounting.invoice.create` | Create/edit invoices |
| `accounting.invoice.post` | Post invoice to GL |
| `accounting.invoice.payment` | Record payments |
| `accounting.bill.view` | View bills |
| `accounting.bill.create` | Create/edit bills |
| `accounting.bill.post` | Post bill to GL |
| `accounting.bill.payment` | Record payments |
| `accounting.reports.view` | View all financial reports |
| `accounting.accounts.view` | View chart of accounts |
| `accounting.accounts.manage` | Create/edit accounts |
| `accounting.customers.view` | View customers |
| `accounting.customers.manage` | Create/edit customers |
| `accounting.vendors.view` | View vendors |
| `accounting.vendors.manage` | Create/edit vendors |
| `accounting.budget.view` | View budgets |
| `accounting.budget.manage` | Create/edit budgets |
| `accounting.budget.approve` | Approve budgets |
| `accounting.fiscal-year.close` | Close fiscal year |

---

## Web UI Routes

All routes are protected by `web_middleware` (default `['web', 'auth']`) under `web_prefix` (default `accounting`):

| Route name | URL | Description |
| --- | --- | --- |
| `accounting.dashboard` | `/accounting/dashboard` | Overview dashboard with pending approvals widget |
| `accounting.accounts` | `/accounting/accounts` | Chart of accounts |
| `accounting.journal` | `/accounting/journal-entries` | Journal entries with two-step workflow |
| `accounting.ledger` | `/accounting/ledger` | General ledger |
| `accounting.ledger.customers` | `/accounting/ledger/customers` | Customer ledger index |
| `accounting.ledger.vendors` | `/accounting/ledger/vendors` | Vendor ledger index |
| `accounting.customers.ledger` | `/accounting/customers/{customer}/ledger` | Per-customer statement |
| `accounting.vendors.ledger` | `/accounting/vendors/{vendor}/ledger` | Per-vendor statement |
| `accounting.invoices` | `/accounting/invoices` | Invoice management |
| `accounting.invoices.show` | `/accounting/invoices/{invoice}` | Invoice detail |
| `accounting.bills` | `/accounting/bills` | Bill management |
| `accounting.bills.show` | `/accounting/bills/{bill}` | Bill detail |
| `accounting.expenses` | `/accounting/expenses` | Expense management |
| `accounting.customers` | `/accounting/customers` | Customer list |
| `accounting.vendors` | `/accounting/vendors` | Vendor list |
| `accounting.reports` | `/accounting/reports` | Financial reports (trial balance, P&L, balance sheet, cash flow) |
| `accounting.budgets` | `/accounting/budgets` | Budget management |
| `accounting.period-close` | `/accounting/period-close` | Month-end period close wizard |

---

## REST API

Base prefix: `api/accounting`. Default middleware: `['api', 'auth:sanctum']`.

### Journal Entries

| Method | Endpoint | Action |
| --- | --- | --- |
| `GET` | `/api/accounting/journal-entries` | List entries (filterable by status, date) |
| `POST` | `/api/accounting/journal-entries` | Create entry |
| `POST` | `/api/accounting/journal-entries/{id}/submit` | Submit entry for approval |
| `POST` | `/api/accounting/journal-entries/{id}/post` | Post entry to GL |
| `POST` | `/api/accounting/journal-entries/{id}/void` | Void entry |

### Accounts

| Method | Endpoint | Action |
| --- | --- | --- |
| `GET` | `/api/accounting/accounts` | List accounts |
| `POST` | `/api/accounting/accounts` | Create account |
| `GET` | `/api/accounting/accounts/{id}/balance` | Current account balance |

### Invoices

| Method | Endpoint | Action |
| --- | --- | --- |
| `GET` | `/api/accounting/invoices` | List invoices |
| `POST` | `/api/accounting/invoices` | Create invoice |
| `POST` | `/api/accounting/invoices/{id}/post` | Post invoice to GL |
| `POST` | `/api/accounting/invoices/{id}/payments` | Record payment |

### Bills

| Method | Endpoint | Action |
| --- | --- | --- |
| `GET` | `/api/accounting/bills` | List bills |
| `POST` | `/api/accounting/bills` | Create bill |
| `POST` | `/api/accounting/bills/{id}/post` | Post bill to GL |
| `POST` | `/api/accounting/bills/{id}/payments` | Record payment |

### Expenses

| Method | Endpoint | Action |
| --- | --- | --- |
| `GET` | `/api/accounting/expenses` | List expenses |
| `POST` | `/api/accounting/expenses` | Create expense |
| `POST` | `/api/accounting/expenses/{id}/post` | Post expense to GL |
| `POST` | `/api/accounting/expenses/{id}/payments` | Record payment |

### Customers & Vendors

| Method | Endpoint | Action |
| --- | --- | --- |
| `GET` | `/api/accounting/customers` | List customers |
| `GET` | `/api/accounting/vendors` | List vendors |

### Budgets

| Method | Endpoint | Action |
| --- | --- | --- |
| `GET` | `/api/accounting/budgets` | List budgets |
| `POST` | `/api/accounting/budgets` | Create budget |
| `POST` | `/api/accounting/budgets/{id}/approve` | Approve budget |
| `GET` | `/api/accounting/budgets/{id}/vs-actual` | Budget vs actual comparison |

### Reports

| Method | Endpoint | Action |
| --- | --- | --- |
| `GET` | `/api/accounting/reports/trial-balance` | Trial balance (`?start=&end=&sbu=`) |
| `GET` | `/api/accounting/reports/balance-sheet` | Balance sheet (`?date=&sbu=`) |
| `GET` | `/api/accounting/reports/income-statement` | P&L (`?start=&end=&sbu=`) |
| `GET` | `/api/accounting/reports/cash-flow` | Cash flow (`?start=&end=&sbu=`) |
| `GET` | `/api/accounting/reports/general-ledger` | General ledger (`?account_id=&start=&end=&sbu=`) |

---

## Artisan Commands

### Demo data

```bash
# Seed a full multi-entity demo dataset (invoices, bills, expenses, journal entries, budgets)
php artisan accounting:demo
```

### Report generation

```bash
# Print a report to the terminal
php artisan accounting:report income-statement --start=2026-01-01 --end=2026-04-30 --format=table
php artisan accounting:report balance-sheet    --date=2026-04-30  --format=table
php artisan accounting:report trial-balance    --start=2026-01-01 --end=2026-04-30 --format=json

# Export to file
php artisan accounting:report all --start=2026-01-01 --end=2026-12-31 --format=csv --output=reports/fy2026.csv
```

Available types: `all` (default) | `trial-balance` | `balance-sheet` | `income-statement` | `cash-flow`

Available formats: `table` | `csv` | `json`

---

## Testing

```bash
composer test          # full suite: rector dry-run, pint check, phpstan, pest
composer test:unit     # pest only
composer test:types    # phpstan static analysis
composer test:lint     # pint style check
composer lint          # apply pint formatting
composer refacto       # apply rector refactors

# Single test
vendor/bin/pest tests/Feature/JournalEntryTest.php
vendor/bin/pest --filter "journal entry must balance"
```

---

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [centrex](https://github.com/centrex)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
