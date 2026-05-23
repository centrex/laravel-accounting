# QuickBooks Online Integration

`laravel-accounting` includes a built-in two-way integration with **QuickBooks Online (QBO)** via the v3 REST API and OAuth 2.0.

- **Push** — sync your Chart of Accounts, Customers, Vendors, Invoices, Bills, and Journal Entries to QBO
- **Pull** — fetch QBO-native reports (Profit & Loss, Balance Sheet, etc.) into your app
- **Format** — render your local reports in QBO-compatible structure for seamless reconciliation

> This integration targets **QuickBooks Online** (cloud). QuickBooks Desktop / IIF is not supported.

---

## Prerequisites

1. Create a free [Intuit Developer](https://developer.intuit.com/) account.
2. Create a new **App** under your developer dashboard → select **QuickBooks Online Accounting** scope.
3. Under **Keys & credentials** copy your **Client ID** and **Client Secret**.
4. Add your callback URL to the **Redirect URIs** list (e.g. `https://yourapp.com/accounting/qbo/callback`).
5. For production use, complete the [app review](https://developer.intuit.com/app/developer/qbo/docs/go-live) process.

---

## Environment Variables

Add these to your `.env` file:

```env
QBO_CLIENT_ID=ABcd1234...
QBO_CLIENT_SECRET=xyz5678...
QBO_REDIRECT_URI=https://yourapp.com/accounting/qbo/callback
QBO_ENVIRONMENT=sandbox          # sandbox | production
QBO_REALM_ID=                    # QBO Company ID (filled after first OAuth connect)
QBO_WEBHOOK_VERIFIER_TOKEN=      # optional; required only if you register a webhook endpoint
```

Publish the config and verify the `quickbooks` section:

```bash
php artisan vendor:publish --tag=laravel-accounting-config
```

```php
// config/accounting.php
'quickbooks' => [
    'client_id'              => env('QBO_CLIENT_ID', ''),
    'client_secret'          => env('QBO_CLIENT_SECRET', ''),
    'redirect_uri'           => env('QBO_REDIRECT_URI', ''),
    'environment'            => env('QBO_ENVIRONMENT', 'sandbox'),
    'default_realm_id'       => env('QBO_REALM_ID', ''),
    'webhook_verifier_token' => env('QBO_WEBHOOK_VERIFIER_TOKEN', ''),
],
```

---

## OAuth 2.0 Connect Flow

QBO uses OAuth 2.0 Authorization Code flow. Two web routes are registered automatically:

| Route name | Path | Description |
| --- | --- | --- |
| `accounting.qbo.connect` | `GET /accounting/qbo/connect` | Redirects user to Intuit authorization page |
| `accounting.qbo.callback` | `GET /accounting/qbo/callback` | Handles the OAuth callback and stores tokens |

### 1. Initiate the connection

Direct the user (or link a button) to the connect route:

```blade
<a href="{{ route('accounting.qbo.connect') }}" class="btn btn-primary">
    Connect QuickBooks
</a>
```

The controller stores a CSRF state value in the session, builds the Intuit authorization URL, and redirects the user.

### 2. Handle the callback

After the user approves access in QBO, Intuit redirects to your callback URL. The controller:

1. Validates the `state` parameter against the session value
2. Exchanges the authorization `code` for an access token + refresh token
3. Stores the tokens in `acct_quickbooks_tokens` keyed by `realm_id`
4. Redirects to the accounting dashboard

### 3. Verify the connection

```bash
# via artisan
php artisan accounting:qbo-sync --realm=<realmId>
```

```http
GET /api/accounting/qbo/status
Authorization: Bearer <token>
```

```json
{
  "data": {
    "connected": true,
    "realm_id": "123456789",
    "expires_at": "2026-05-23T11:00:00Z",
    "refresh_expires_at": "2026-08-21T10:00:00Z"
  }
}
```

### 4. Disconnect

```http
POST /api/accounting/qbo/disconnect
Authorization: Bearer <token>
```

This revokes the QBO access token and removes the stored token record.

### Token auto-refresh

QBO access tokens expire after **1 hour**. `QuickBooksClient` automatically detects a `401 Unauthorized` response, refreshes the token via the stored refresh token (valid 100 days), saves the new token, and retries the original request — all transparently.

---

## Pushing Data to QuickBooks

### Via PHP (service class)

```php
use Centrex\Accounting\QuickBooks\QuickBooksSyncService;

$sync = app(QuickBooksSyncService::class);
$realmId = config('accounting.quickbooks.default_realm_id');

// Sync Chart of Accounts
$result = $sync->syncAccounts($realmId);
// ['created' => 5, 'updated' => 12, 'skipped' => 3, 'errors' => []]

// Sync Customers
$result = $sync->syncCustomers($realmId);

// Sync Vendors
$result = $sync->syncVendors($realmId);

// Sync Invoices (optionally filter by last-modified date)
$result = $sync->syncInvoices($realmId, since: '2026-05-01');

// Sync Bills
$result = $sync->syncBills($realmId);

// Sync Journal Entries
$result = $sync->syncJournalEntries($realmId);
```

Each method returns a summary array:

```php
[
    'created' => int,   // new records created in QBO
    'updated' => int,   // existing records updated in QBO
    'skipped' => int,   // records with no changes
    'errors'  => [],    // ['id' => ..., 'error' => '...'] per failed record
]
```

**Matching logic:**
- Accounts matched by `AcctNum` (our account `code`)
- Customers and Vendors matched by `DisplayName`
- Invoices and Bills matched by our document number stored in QBO's `DocNumber`
- Journal Entries matched by our `reference` stored in QBO's `DocNumber`

### Via REST API

```http
POST /api/accounting/qbo/sync
Authorization: Bearer <token>
Content-Type: application/json

{
  "realm_id": "123456789",
  "entities": ["accounts", "customers", "vendors", "invoices", "bills", "journal_entries"],
  "since": "2026-05-01"
}
```

Response:

```json
{
  "data": {
    "accounts":        { "created": 2, "updated": 10, "skipped": 1, "errors": [] },
    "customers":       { "created": 0, "updated": 5,  "skipped": 2, "errors": [] },
    "vendors":         { "created": 1, "updated": 3,  "skipped": 0, "errors": [] },
    "invoices":        { "created": 4, "updated": 8,  "skipped": 1, "errors": [] },
    "bills":           { "created": 2, "updated": 6,  "skipped": 0, "errors": [] },
    "journal_entries": { "created": 0, "updated": 1,  "skipped": 3, "errors": [] }
  }
}
```

### Via Artisan

```bash
# Sync all entities
php artisan accounting:qbo-sync

# Sync specific entities only
php artisan accounting:qbo-sync --entity=accounts --entity=customers

# Sync with a date filter (only records modified after this date)
php artisan accounting:qbo-sync --entity=invoices --since=2026-05-01

# Target a specific QBO company (overrides config)
php artisan accounting:qbo-sync --realm=123456789
```

---

## Pulling QBO Reports

Fetch reports directly from QuickBooks Online into your application.

### Via PHP

```php
use Centrex\Accounting\QuickBooks\QuickBooksSyncService;

$sync    = app(QuickBooksSyncService::class);
$realmId = config('accounting.quickbooks.default_realm_id');

// Pull a report with optional parameters
$report = $sync->pullQboReport($realmId, 'ProfitAndLoss', [
    'start_date' => '2026-01-01',
    'end_date'   => '2026-04-30',
]);

// Available report names
// ProfitAndLoss, ProfitAndLossDetail
// BalanceSheet, BalanceSheetDetail
// CashFlow
// TrialBalance
// AgedReceivables, AgedPayableDetail
// AgedPayables, AgedPayableDetail
// GeneralLedger
// TransactionList
// CustomerBalance, CustomerIncome
// VendorBalance, VendorExpenses
```

### Via REST API

```http
GET /api/accounting/qbo/reports/{report}?start_date=2026-01-01&end_date=2026-04-30
Authorization: Bearer <token>
```

Available `{report}` slugs:

| Slug | QBO Report |
| --- | --- |
| `profit-and-loss` | ProfitAndLoss |
| `balance-sheet` | BalanceSheet |
| `cash-flow` | CashFlow |
| `trial-balance` | TrialBalance |
| `aged-receivables` | AgedReceivables |
| `aged-payables` | AgedPayables |
| `general-ledger` | GeneralLedger |
| `transaction-list` | TransactionList |

### Via Artisan

```bash
# Pull a report and dump JSON to stdout
php artisan accounting:qbo-sync --pull=profit-and-loss --start=2026-01-01 --end=2026-04-30

# Pull with a specific realm
php artisan accounting:qbo-sync --pull=balance-sheet --start=2026-04-30 --realm=123456789
```

---

## QBO-Formatted Local Reports

Render your local accounting data in QBO-compatible report structure using `QuickBooksReportFormatter`.

### Profit & Loss

```php
use Centrex\Accounting\Facades\Accounting;
use Centrex\Accounting\QuickBooks\QuickBooksReportFormatter;

$pl        = Accounting::getIncomeStatement('2026-01-01', '2026-04-30');
$formatter = app(QuickBooksReportFormatter::class);

$qboReport = $formatter->profitAndLoss($pl);
```

Output structure mirrors QBO's P&L sections:

```php
[
  'income'               => ['accounts' => [...], 'total' => 5100000.00],
  'cost_of_goods_sold'   => ['accounts' => [...], 'total' => 2100000.00],
  'gross_profit'         => 3000000.00,
  'expenses'             => ['accounts' => [...], 'total' => 1308000.00],
  'net_operating_income' => 1692000.00,
  'other_income'         => ['accounts' => [...], 'total' => 0.00],
  'other_expenses'       => ['accounts' => [...], 'total' => 0.00],
  'net_other_income'     => 0.00,
  'net_income'           => 1692000.00,
]
```

### Balance Sheet

```php
$bs        = Accounting::getBalanceSheet('2026-04-30');
$qboReport = $formatter->balanceSheet($bs);
```

```php
[
  'assets' => [
    'current_assets' => [
      'bank_accounts'        => ['accounts' => [...], 'total' => 3200000.00],
      'accounts_receivable'  => ['accounts' => [...], 'total' => 850000.00],
      'other_current_assets' => ['accounts' => [...], 'total' => 400000.00],
      'total'                => 4450000.00,
    ],
    'fixed_assets'           => ['accounts' => [...], 'total' => 2500000.00],
    'other_assets'           => ['accounts' => [...], 'total' => 0.00],
    'total'                  => 6950000.00,
  ],
  'liabilities_and_equity' => [
    'liabilities' => [
      'current_liabilities' => [
        'accounts_payable'        => ['accounts' => [...], 'total' => 620000.00],
        'credit_cards'            => ['accounts' => [...], 'total' => 0.00],
        'other_current_liabilities' => ['accounts' => [...], 'total' => 80000.00],
        'total'                   => 700000.00,
      ],
      'long_term_liabilities'     => ['accounts' => [...], 'total' => 1000000.00],
      'total'                     => 1700000.00,
    ],
    'equity'      => ['accounts' => [...], 'total' => 5250000.00],
    'total'       => 6950000.00,
  ],
]
```

### Cash Flow

```php
$cf        = Accounting::getCashFlowStatement('2026-01-01', '2026-04-30');
$qboReport = $formatter->cashFlow($cf);
```

```php
[
  'operating_activities'  => ['label' => 'Operating Activities',  'amount' => 2800000.00],
  'investing_activities'  => ['label' => 'Investing Activities',  'amount' => -500000.00],
  'financing_activities'  => ['label' => 'Financing Activities',  'amount' => 0.00],
  'net_change_in_cash'    => 2300000.00,
]
```

### Trial Balance with QBO Types

```php
$tb        = Accounting::getTrialBalance('2026-01-01', '2026-04-30');
$qboReport = $formatter->trialBalance($tb);
// Each account row gains a 'qbo_type' key:
// ['account' => [...], 'debit' => ..., 'credit' => ..., 'qbo_type' => 'Expense']
```

### Via REST API

All standard report endpoints accept `?format=qbo` to return the QBO-structured variant:

```http
GET /api/accounting/reports/income-statement?start_date=2026-01-01&end_date=2026-04-30&format=qbo
GET /api/accounting/reports/balance-sheet?date=2026-04-30&format=qbo
GET /api/accounting/reports/cash-flow?start_date=2026-01-01&end_date=2026-04-30&format=qbo
GET /api/accounting/reports/trial-balance?start_date=2026-01-01&end_date=2026-04-30&format=qbo
```

---

## A/R and A/P Aging Reports

Aging reports use QBO-compatible buckets: **current** (not yet due), **1–30**, **31–60**, **61–90**, **91+** days past due.

### A/R Aging

```php
$aging = Accounting::getArAging(asOfDate: '2026-04-30', sbuCode: null);

// Raw structure:
// [
//   'as_of_date' => '2026-04-30',
//   'sbu_code'   => null,
//   'rows' => [
//     ['name' => 'Acme Corp', 'current' => 5000.00, '1_30' => 1200.00, '31_60' => 0.00, '61_90' => 800.00, 'over_90' => 0.00, 'total' => 7000.00],
//     ...
//   ],
//   'totals' => ['current' => 5000.00, '1_30' => 1200.00, '31_60' => 0.00, '61_90' => 800.00, 'over_90' => 0.00, 'total' => 7000.00],
// ]

// QBO-formatted structure:
use Centrex\Accounting\QuickBooks\QuickBooksReportFormatter;

$qboAging = app(QuickBooksReportFormatter::class)->arAging($aging);
```

Via API (supports `?format=qbo`):

```http
GET /api/accounting/reports/ar-aging?as_of_date=2026-04-30
GET /api/accounting/reports/ar-aging?as_of_date=2026-04-30&format=qbo
```

### A/P Aging

```php
$aging = Accounting::getApAging(asOfDate: '2026-04-30', sbuCode: null);

// QBO-formatted:
$qboAging = app(QuickBooksReportFormatter::class)->apAging($aging);
```

Via API:

```http
GET /api/accounting/reports/ap-aging?as_of_date=2026-04-30
GET /api/accounting/reports/ap-aging?as_of_date=2026-04-30&format=qbo
```

**Invoice statuses included in A/R aging:** `sent`, `issued`, `partially_settled`, `overdue`

**Bill statuses included in A/P aging:** `issued`, `partially_settled`, `overdue`

---

## Webhooks

QuickBooks can push real-time change notifications to your app. Register your webhook endpoint in the Intuit Developer dashboard:

```
https://yourapp.com/api/accounting/qbo/webhook
```

The endpoint bypasses Sanctum authentication and is verified by **HMAC-SHA256 signature** instead. Set `QBO_WEBHOOK_VERIFIER_TOKEN` in your `.env` to enable verification.

### Supported entity types

The webhook handler processes these entity change events automatically:

| QBO Entity | Action |
| --- | --- |
| `Account` | Logs the change |
| `Customer` | Logs the change |
| `Vendor` | Logs the change |
| `Invoice` | Logs the change |
| `Bill` | Logs the change |
| `JournalEntry` | Logs the change |

Extend `QuickBooksSyncService::handleWebhook()` to add custom reactions (e.g. pull the updated record, trigger a re-sync).

---

## Account Type Mapping

`QuickBooksAccountTypeMapper` translates your `AccountSubtype` values into QBO's `AccountType` + `AccountSubType` fields, and provides a `section()` string used internally by the formatter.

```php
use Centrex\Accounting\QuickBooks\QuickBooksAccountTypeMapper;
use Centrex\Accounting\Models\Account;

$mapper  = app(QuickBooksAccountTypeMapper::class);
$account = Account::where('code', '1100')->first(); // Bank account

$mapper->qboType($account);    // "Bank"
$mapper->qboSubType($account); // "Checking"
$mapper->section($account);    // "bank_accounts"

// Full mapping:
$mapper->map($account);
// ['AccountType' => 'Bank', 'AccountSubType' => 'Checking']
```

### Mapping reference

| Our subtype | QBO AccountType | QBO AccountSubType |
| --- | --- | --- |
| `cash` | Bank | CashAndCashEquivalents |
| `bank` | Bank | Checking |
| `savings` | Bank | Savings |
| `accounts_receivable` | Accounts Receivable | AccountsReceivable |
| `inventory` | Other Current Asset | Inventory |
| `prepaid_expense` | Other Current Asset | Prepaid Expenses |
| `current_asset` | Other Current Asset | OtherCurrentAssets |
| `fixed_asset` | Fixed Asset | FurnitureAndFixtures |
| `accumulated_depreciation` | Fixed Asset | AccumulatedDepletion |
| `intangible_asset` | Other Asset | LeaseBuyout |
| `other_asset` | Other Asset | OtherAssets |
| `accounts_payable` | Accounts Payable | AccountsPayable |
| `credit_card` | Credit Card | CreditCard |
| `accrued_liability` | Other Current Liability | AccruedLiabilities |
| `current_liability` | Other Current Liability | OtherCurrentLiabilities |
| `deferred_revenue` | Other Current Liability | DeferredRevenue |
| `long_term_liability` | Long Term Liability | OtherLongTermLiabilities |
| `notes_payable` | Long Term Liability | NotesPayable |
| `loan_payable` | Long Term Liability | LongTermDebt |
| `common_stock` | Equity | CommonStock |
| `retained_earnings` | Equity | RetainedEarnings |
| `equity` | Equity | OpeningBalanceEquity |
| `revenue` | Income | SalesOfProductIncome |
| `other_income` | Other Income | OtherMiscIncome |
| `cost_of_goods_sold` | Cost of Goods Sold | SuppliesMaterialsCogs |
| `expense` | Expense | OtherMiscExpense |
| `payroll_expense` | Expense | OtherMiscExpense |
| `depreciation_expense` | Expense | Depreciation |
| `other_expense` | Other Expense | OtherMiscExpense |
| `interest_expense` | Other Expense | InterestPaid |
| `income_tax` | Other Expense | TaxesPaid |

---

## Authorization Gates

Two gates control QBO access. Override them in your `AppServiceProvider` for fine-grained control.

| Gate | Description |
| --- | --- |
| `accounting.qbo.connect` | Initiate OAuth2 connect / disconnect |
| `accounting.qbo.sync` | Trigger push sync or pull reports |

```php
// AppServiceProvider::boot()
Gate::define('accounting.qbo.connect', fn ($user) => $user->hasRole('admin'));
Gate::define('accounting.qbo.sync',    fn ($user) => $user->hasRole(['admin', 'accountant']));
```

---

## Class Reference

| Class | Namespace | Purpose |
| --- | --- | --- |
| `QuickBooksClient` | `Centrex\Accounting\QuickBooks` | OAuth2 token management + HTTP client for QBO v3 API |
| `QuickBooksSyncService` | `Centrex\Accounting\QuickBooks` | Push entities to QBO; pull QBO reports; handle webhooks |
| `QuickBooksReportFormatter` | `Centrex\Accounting\QuickBooks` | Transform local report arrays into QBO-structured format |
| `QuickBooksAccountTypeMapper` | `Centrex\Accounting\QuickBooks` | Map `AccountSubtype` → QBO AccountType + AccountSubType |
| `QuickBooksToken` | `Centrex\Accounting\Models` | Eloquent model storing OAuth tokens per `realm_id` |
| `QuickBooksController` | `Centrex\Accounting\Http\Controllers\Api` | Web OAuth routes + API sync/webhook endpoints |
| `QuickBooksSyncCommand` | `Centrex\Accounting\Commands` | `accounting:qbo-sync` artisan command |

All four service classes are registered as **singletons** in the service container — inject any of them via the constructor or `app()`.
