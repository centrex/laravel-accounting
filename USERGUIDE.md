# Laravel Accounting Package

A comprehensive double-entry bookkeeping system with financial reporting, multi-currency support, and automated journal entries.

## Features

### ✅ Core Accounting
- **Double-Entry Bookkeeping** - Every transaction maintains debit = credit balance
- **Chart of Accounts** - Hierarchical account structure with 5 main types
- **Journal Entries** - Manual and automated entry creation with approval workflow
- **Account Balances** - Real-time balance calculations and historical tracking
- **Fiscal Periods** - Year and monthly period management with closing procedures

### ✅ Financial Reporting
- **Trial Balance** - Verify accounting equation integrity
- **Balance Sheet** - Assets, Liabilities, and Equity snapshot
- **Income Statement (P&L)** - Revenue and expenses with net income
- **Cash Flow Statement** - Operating, investing, and financing activities
- **General Ledger** - Complete transaction history by account

### ✅ Business Operations
- **Invoicing** - Create customer invoices with automatic AR entries
- **Bill Management** - Record vendor bills with AP automation
- **Payment Processing** - Track payments and update balances
- **Customer Management** - Credit limits and payment terms
- **Vendor Management** - Payment terms and outstanding balances

### ✅ Advanced Features
- **Multi-Currency Support** - Handle transactions in different currencies
- **Tax Management** - Multiple tax rates with automatic calculations
- **Approval Workflow** - Draft, posted, and void status management
- **Soft Deletes** - Safe deletion with audit trail
- **System Accounts** - Protected accounts for core functionality

## Installation

### 1. Run Migrations

```bash
php artisan migrate
```

### 2. Seed Initial Data

```bash
php artisan db:seed --class=AccountingSeeder
```

This creates:
- Standard chart of accounts
- Current fiscal year with periods
- Sample customers and vendors
- Tax rates

### 3. Register Service Provider

In `config/app.php`:

```php
'providers' => [
    // ...
    App\Providers\AccountingServiceProvider::class,
],
```

### 4. Create Service Provider

```bash
php artisan make:provider AccountingServiceProvider
```

```php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\AccountingService;

class AccountingServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(AccountingService::class);
    }
}
```

### 5. Create Livewire Components

```bash
php artisan make:livewire JournalEntries
php artisan make:livewire FinancialReports
php artisan make:livewire ChartOfAccounts
php artisan make:livewire AccountingDashboard
```

### 6. Add Routes

```php
Route::middleware(['auth'])->prefix('accounting')->group(function () {
    Route::get('/dashboard', AccountingDashboard::class)->name('accounting.dashboard');
    Route::get('/accounts', ChartOfAccounts::class)->name('accounting.accounts');
    Route::get('/journal-entries', JournalEntries::class)->name('accounting.journal');
    Route::get('/reports', FinancialReports::class)->name('accounting.reports');
});
```

## Usage Examples

### Creating Journal Entries

```php
use App\Services\AccountingService;

$service = app(AccountingService::class);

// Manual journal entry
$entry = $service->createJournalEntry([
    'date' => '2024-01-15',
    'reference' => 'INV-001',
    'description' => 'Sale to customer',
    'lines' => [
        [
            'account_id' => 1, // Accounts Receivable
            'type' => 'debit',
            'amount' => 1000.00,
            'description' => 'Invoice payment due'
        ],
        [
            'account_id' => 20, // Sales Revenue
            'type' => 'credit',
            'amount' => 1000.00,
            'description' => 'Product sales'
        ]
    ]
]);

// Post the entry
$entry->post();
```

### Recording an Invoice

```php
use App\Models\Invoice;
use App\Services\AccountingService;

// Create invoice
$invoice = Invoice::create([
    'customer_id' => 1,
    'invoice_date' => now(),
    'due_date' => now()->addDays(30),
    'subtotal' => 1000.00,
    'tax_amount' => 80.00,
    'total' => 1080.00,
    'status' => 'draft'
]);

// Add invoice items
$invoice->items()->create([
    'description' => 'Web Development Services',
    'quantity' => 10,
    'unit_price' => 100.00,
    'amount' => 1000.00,
    'tax_rate' => 8.00,
    'tax_amount' => 80.00
]);

// Post invoice (creates journal entry)
$service = app(AccountingService::class);
$journalEntry = $service->postInvoice($invoice);
```

### Recording Payment

```php
// Record payment received
$payment = $service->recordInvoicePayment($invoice, [
    'date' => now(),
    'amount' => 1080.00,
    'method' => 'bank_transfer',
    'reference' => 'TRANS-12345',
    'notes' => 'Payment received via wire transfer'
]);
```

### Generating Reports

```php
use App\Services\AccountingService;

$service = app(AccountingService::class);

// Trial Balance
$trialBalance = $service->getTrialBalance(
    startDate: '2024-01-01',
    endDate: '2024-12-31'
);

// Balance Sheet
$balanceSheet = $service->getBalanceSheet(
    date: '2024-12-31'
);

// Income Statement
$incomeStatement = $service->getIncomeStatement(
    startDate: '2024-01-01',
    endDate: '2024-12-31'
);

// Cash Flow Statement
$cashFlow = $service->getCashFlowStatement(
    startDate: '2024-01-01',
    endDate: '2024-12-31'
);
```

### Working with Accounts

```php
use App\Models\Account;

// Create a new account
$account = Account::create([
    'code' => '6550',
    'name' => 'Software Subscriptions',
    'type' => 'expense',
    'subtype' => 'operating_expense',
    'description' => 'Monthly software costs',
    'currency' => 'USD',
    'is_active' => true
]);

// Get current balance
$balance = $account->getCurrentBalance();

// Check if debit account
if ($account->isDebitAccount()) {
    // Assets and Expenses increase with debits
}
```

### Closing Fiscal Year

```php
use App\Models\FiscalYear;
use App\Services\AccountingService;

$fiscalYear = FiscalYear::where('name', 'FY 2024')->first();
$service = app(AccountingService::class);

// Close fiscal year (transfers net income to retained earnings)
$service->closeFiscalYear($fiscalYear);
```

## Understanding Double-Entry Bookkeeping

### Account Types and Normal Balances

| Account Type | Normal Balance | Increases With | Decreases With |
|-------------|----------------|----------------|----------------|
| Asset | Debit | Debit | Credit |
| Liability | Credit | Credit | Debit |
| Equity | Credit | Credit | Debit |
| Revenue | Credit | Credit | Debit |
| Expense | Debit | Debit | Credit |

### The Accounting Equation

**Assets = Liabilities + Equity**

Every transaction must maintain this balance.

### Journal Entry Rules

1. Every entry must have at least one debit and one credit
2. Total debits must equal total credits
3. Entries can have multiple lines but must balance
4. Posted entries cannot be edited (only voided)

### Example Transactions

#### Sale on Credit
```
Debit: Accounts Receivable $1,000
Credit: Sales Revenue $1,000
```

#### Pay Rent
```
Debit: Rent Expense $2,000
Credit: Cash $2,000
```

#### Purchase Equipment
```
Debit: Equipment $10,000
Credit: Cash $10,000
```

#### Receive Payment from Customer
```
Debit: Cash $1,000
Credit: Accounts Receivable $1,000
```

## Chart of Accounts Structure

### Account Code Ranges

- **1000-1999**: Assets
  - 1000-1499: Current Assets
  - 1500-1999: Fixed Assets
  
- **2000-2999**: Liabilities
  - 2000-2499: Current Liabilities
  - 2500-2999: Long-term Liabilities
  
- **3000-3999**: Equity
  
- **4000-4999**: Revenue
  - 4000-4799: Operating Revenue
  - 4800-4999: Non-operating Revenue
  
- **5000-6999**: Expenses
  - 5000-5999: Cost of Goods Sold
  - 6000-6999: Operating Expenses

## API Reference

### AccountingService Methods

```php
// Journal Entries
createJournalEntry(array $data): JournalEntry
postInvoice(Invoice $invoice): JournalEntry
postBill(Bill $bill): JournalEntry
recordInvoicePayment(Invoice $invoice, array $data): Payment

// Reports
getTrialBalance($startDate, $endDate): array
getBalanceSheet($date): array
getIncomeStatement($startDate, $endDate): array
getCashFlowStatement($startDate, $endDate): array

// Setup
initializeChartOfAccounts(): void
closeFiscalYear(FiscalYear $fiscalYear): void
```

### Model Relationships

```php
// Account
$account->journalEntryLines
$account->balances
$account->parent
$account->children

// Journal Entry
$entry->lines
$entry->creator
$entry->approver

// Invoice
$invoice->customer
$invoice->items
$invoice->payments
$invoice->journalEntry

// Customer
$customer->invoices
$customer->total_outstanding
```

## Best Practices

### 1. Always Use Service Layer

```php
// Good
$service = app(AccountingService::class);
$entry = $service->createJournalEntry($data);

// Avoid
$entry = JournalEntry::create($data); // Bypasses validation
```

### 2. Validate Before Posting

```php
if ($entry->isBalanced()) {
    $entry->post();
} else {
    throw new Exception('Entry is not balanced');
}
```

### 3. Use Transactions

```php
DB::transaction(function () use ($invoice, $payment) {
    $invoice->update(['paid_amount' => $payment->amount]);
    $entry = createJournalEntry($payment);
    $entry->post();
});
```

### 4. Protect System Accounts

```php
if ($account->is_system) {
    throw new Exception('Cannot delete system account');
}
```

### 5. Reconcile Regularly

```php
// Check if trial balance is balanced
$trialBalance = $service->getTrialBalance();
if (!$trialBalance['is_balanced']) {
    // Investigate discrepancy
}
```

## Testing

```php
use Tests\TestCase;
use App\Services\AccountingService;
use App\Models\Account;

class AccountingTest extends TestCase
{
    public function test_journal_entry_must_balance()
    {
        $service = app(AccountingService::class);
        
        $this->expectException(\Exception::class);
        
        $entry = $service->createJournalEntry([
            'date' => now(),
            'description' => 'Test entry',
            'lines' => [
                ['account_id' => 1, 'type' => 'debit', 'amount' => 100],
                ['account_id' => 2, 'type' => 'credit', 'amount' => 50], // Not balanced!
            ]
        ]);
    }
    
    public function test_trial_balance_is_balanced()
    {
        $service = app(AccountingService::class);
        $trialBalance = $service->getTrialBalance();
        
        $this->assertTrue($trialBalance['is_balanced']);
    }
}
```

## Troubleshooting

**Trial Balance Not Balancing**: Check for unposted entries or entries created outside the service layer

**Account Balance Incorrect**: Verify all journal entries are posted and no manual balance modifications

**Cannot Delete Account**: Account may be in use or marked as system account

**Fiscal Year Won't Close**: Ensure all periods are closed and entries are posted

## License

Open source - Free to use and modify for your projects!