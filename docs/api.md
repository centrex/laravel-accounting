# Routes & API Reference

## Configuration

```php
// config/accounting.php
'web_prefix'     => 'accounting',               // URL prefix for web routes
'web_middleware' => ['web', 'auth'],             // middleware for web routes
'api_prefix'     => 'api/accounting',           // URL prefix for API routes
'api_middleware' => ['api', 'auth:sanctum'],    // middleware for API routes
```

---

## Web UI routes (Livewire)

All routes are prefixed with `web_prefix` (default `accounting`) and protected by `web_middleware`.

| Route name | Method | Path | Livewire component |
| --- | --- | --- | --- |
| `accounting.dashboard` | GET | `/accounting/dashboard` | `AccountingDashboard` |
| `accounting.accounts` | GET | `/accounting/accounts` | `ChartOfAccounts` |
| `accounting.journal` | GET | `/accounting/journal-entries` | `JournalEntries` |
| `accounting.ledger` | GET | `/accounting/ledger` | `GeneralLedger` |
| `accounting.ledger.customers` | GET | `/accounting/ledger/customers` | `CustomerLedgerIndex` |
| `accounting.ledger.vendors` | GET | `/accounting/ledger/vendors` | `VendorLedgerIndex` |
| `accounting.reports` | GET | `/accounting/reports` | `FinancialReports` |
| `accounting.invoices` | GET | `/accounting/invoices` | `Invoices` |
| `accounting.invoices.show` | GET | `/accounting/invoices/{invoice}` | `InvoiceDetails` |
| `accounting.bills` | GET | `/accounting/bills` | `Bills` |
| `accounting.bills.show` | GET | `/accounting/bills/{bill}` | `BillDetails` |
| `accounting.budgets` | GET | `/accounting/budgets` | `Budgets` |
| `accounting.customers` | GET | `/accounting/customers` | `Customers` |
| `accounting.customers.ledger` | GET | `/accounting/customers/{customer}/ledger` | `CustomerLedger` |
| `accounting.vendors` | GET | `/accounting/vendors` | `Vendors` |
| `accounting.vendors.ledger` | GET | `/accounting/vendors/{vendor}/ledger` | `VendorLedger` |
| `accounting.expenses` | GET | `/accounting/expenses` | `Expenses` |
| `accounting.period-close` | GET | `/accounting/period-close` | `PeriodClose` |
| `accounting.tax-rates` | GET | `/accounting/tax-rates` | `TaxRates` |
| `accounting.bank-reconciliations` | GET | `/accounting/bank-reconciliations` | `BankReconciliations` |
| `accounting.bank-reconciliations.show` | GET | `/accounting/bank-reconciliations/{bankReconciliation}` | `BankReconciliationDetails` |
| `accounting.qbo.connect` | GET | `/accounting/qbo/connect` | OAuth2 connect to QBO |
| `accounting.qbo.callback` | GET | `/accounting/qbo/callback` | OAuth2 callback (Intuit redirect) |

---

## REST API

Base prefix: `api/accounting` (configurable). Default middleware: `['api', 'auth:sanctum']`. All responses use `JsonResource` classes (`AccountResource`, `InvoiceResource`, etc.).

### Chart of Accounts

| Method | Endpoint | Action |
| --- | --- | --- |
| GET | `/api/accounting/accounts` | List all accounts |
| POST | `/api/accounting/accounts` | Create a custom account |
| GET | `/api/accounting/accounts/{id}` | Get account details |
| PUT | `/api/accounting/accounts/{id}` | Update account |
| GET | `/api/accounting/accounts/{id}/balance` | Current balance |

### Journal Entries

| Method | Endpoint | Action |
| --- | --- | --- |
| GET | `/api/accounting/journal-entries` | List journal entries |
| POST | `/api/accounting/journal-entries` | Create draft entry |
| GET | `/api/accounting/journal-entries/{id}` | Get entry with lines |
| POST | `/api/accounting/journal-entries/{id}/post` | Post entry |
| POST | `/api/accounting/journal-entries/{id}/void` | Void posted entry |

### Invoices

| Method | Endpoint | Action |
| --- | --- | --- |
| GET | `/api/accounting/invoices` | List invoices |
| POST | `/api/accounting/invoices` | Create invoice |
| GET | `/api/accounting/invoices/{id}` | Get invoice with items |
| PUT | `/api/accounting/invoices/{id}` | Update draft invoice |
| POST | `/api/accounting/invoices/{id}/post` | Post invoice to GL |
| POST | `/api/accounting/invoices/{id}/payments` | Record payment |
| DELETE | `/api/accounting/invoices/{id}` | Delete draft invoice |

### Bills

| Method | Endpoint | Action |
| --- | --- | --- |
| GET | `/api/accounting/bills` | List bills |
| POST | `/api/accounting/bills` | Create bill |
| GET | `/api/accounting/bills/{id}` | Get bill with items |
| POST | `/api/accounting/bills/{id}/post` | Post bill to GL |
| POST | `/api/accounting/bills/{id}/payments` | Record vendor payment |
| DELETE | `/api/accounting/bills/{id}` | Delete draft bill |

### Expenses

| Method | Endpoint | Action |
| --- | --- | --- |
| GET | `/api/accounting/expenses` | List expenses |
| POST | `/api/accounting/expenses` | Create expense |
| GET | `/api/accounting/expenses/{id}` | Get expense details |
| POST | `/api/accounting/expenses/{id}/post` | Post expense to GL |
| POST | `/api/accounting/expenses/{id}/payments` | Settle credit expense |
| DELETE | `/api/accounting/expenses/{id}` | Delete draft expense |

### Customers

| Method | Endpoint | Action |
| --- | --- | --- |
| GET | `/api/accounting/customers` | List customers |
| POST | `/api/accounting/customers` | Create customer |
| GET | `/api/accounting/customers/{id}` | Get customer |
| PUT | `/api/accounting/customers/{id}` | Update customer |
| DELETE | `/api/accounting/customers/{id}` | Delete customer |

### Vendors

| Method | Endpoint | Action |
| --- | --- | --- |
| GET | `/api/accounting/vendors` | List vendors |
| POST | `/api/accounting/vendors` | Create vendor |
| GET | `/api/accounting/vendors/{id}` | Get vendor |
| PUT | `/api/accounting/vendors/{id}` | Update vendor |
| DELETE | `/api/accounting/vendors/{id}` | Delete vendor |

### Financial Reports

All report endpoints accept optional query parameters: `start_date`, `end_date`, `date`, `sbu_code`.

| Method | Endpoint | Action |
| --- | --- | --- |
| GET | `/api/accounting/reports/trial-balance` | Trial balance |
| GET | `/api/accounting/reports/balance-sheet` | Balance sheet |
| GET | `/api/accounting/reports/income-statement` | Income statement (P&L) |
| GET | `/api/accounting/reports/cash-flow` | Cash flow statement |
| GET | `/api/accounting/reports/general-ledger` | General ledger (per-account) |
| GET | `/api/accounting/reports/ar-aging` | A/R aging (QBO-compatible buckets); `?format=qbo` for QBO structure |
| GET | `/api/accounting/reports/ap-aging` | A/P aging (QBO-compatible buckets); `?format=qbo` for QBO structure |
| GET | `/api/accounting/reports/sales-tax-liability` | Sales tax liability by rate (`start_date`/`end_date` required) |

Report query parameters: `start_date`, `end_date`, `date`, `as_of_date`, `sbu_code`, `format` (`raw` or `qbo`).

### Tax Rates

| Method | Endpoint | Action |
| --- | --- | --- |
| GET | `/api/accounting/tax-rates` | List tax rates (`?search=&active=`) |
| POST | `/api/accounting/tax-rates` | Create tax rate |
| GET | `/api/accounting/tax-rates/{id}` | Get tax rate |
| PUT | `/api/accounting/tax-rates/{id}` | Update tax rate |
| DELETE | `/api/accounting/tax-rates/{id}` | Delete tax rate (422 if referenced by an invoice/bill line) |

### Bank Reconciliation

| Method | Endpoint | Action |
| --- | --- | --- |
| GET | `/api/accounting/bank-reconciliations` | List reconciliations (`?account_id=`) |
| POST | `/api/accounting/bank-reconciliations` | Start a reconciliation |
| GET | `/api/accounting/bank-reconciliations/{id}` | Get reconciliation with statement lines |
| POST | `/api/accounting/bank-reconciliations/{id}/statement-lines` | Import statement lines (`rows: [...]`) |
| POST | `/api/accounting/bank-reconciliations/{id}/match` | Match a statement line to a GL line |
| POST | `/api/accounting/bank-reconciliations/{id}/unmatch` | Unmatch a statement line |
| POST | `/api/accounting/bank-reconciliations/{id}/complete` | Complete the reconciliation (balance check) |

### Budgets

| Method | Endpoint | Action |
| --- | --- | --- |
| GET | `/api/accounting/budgets` | List budgets |
| POST | `/api/accounting/budgets` | Create budget |
| GET | `/api/accounting/budgets/{id}` | Get budget |
| PUT | `/api/accounting/budgets/{id}` | Update draft budget |
| POST | `/api/accounting/budgets/{id}/approve` | Approve budget |
| GET | `/api/accounting/budgets/{id}/vs-actual` | Budget variance report |
| DELETE | `/api/accounting/budgets/{id}` | Delete budget |

### QuickBooks Online

Requires `accounting.qbo.connect` or `accounting.qbo.sync` gate (see [QuickBooks integration](quickbooks.md)).

| Method | Endpoint | Action |
| --- | --- | --- |
| GET | `/api/accounting/qbo/status` | Connection status and token expiry |
| POST | `/api/accounting/qbo/sync` | Push entities to QBO (`entities`, `realm_id`, `since`) |
| POST | `/api/accounting/qbo/disconnect` | Revoke QBO access and remove stored token |
| GET | `/api/accounting/qbo/reports/{report}` | Pull a named report from QBO |
| POST | `/api/accounting/qbo/webhook` | QBO webhook receiver (HMAC-verified, no auth middleware) |
