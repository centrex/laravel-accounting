# Installation

## Requirements

- PHP 8.2+
- Laravel 11+
- Livewire 3 (for the web UI)

## Install the package

```bash
composer require centrex/laravel-accounting
```

## Publish config and migrate

```bash
php artisan vendor:publish --tag="laravel-accounting-config"
php artisan migrate
```

## Seed the standard chart of accounts

Run once after migrations (safe to re-run — it is idempotent):

```php
use Centrex\Accounting\Facades\Accounting;

Accounting::initializeChartOfAccounts();
```

Standard accounts created:

| Code | Name | Type |
| --- | --- | --- |
| 1000 | Cash | Asset |
| 1100 | Bank | Asset |
| 1200 | Accounts Receivable | Asset |
| 1300 | Inventory | Asset |
| 2000 | Accounts Payable | Liability |
| 2150 | Inventory Financing Payable (parent) | Liability |
| 2170 | Accrued Interest — Inv. Financing (parent) | Liability |
| 2300 | Sales Tax Payable | Liability |
| 2400 | Short-term Loans Payable (parent) | Liability |
| 2500 | Long-term Loans Payable (parent) | Liability |
| 3000 | Share Capital | Equity |
| 3100 | Retained Earnings | Equity |
| 4000 | Sales Revenue | Revenue |
| 4900 | Inventory Gain | Revenue |
| 5000 | COGS | Expense |
| 6700 | Interest Expense | Expense |
| 6710 | Interest — Inventory Financing | Expense |
| 6720 | Interest — Short-term Loans | Expense |
| 6730 | Interest — Long-term Loans | Expense |

## Publish views (optional)

```bash
php artisan vendor:publish --tag="laravel-accounting-views"
```

---

## Environment variables

```env
ACCOUNTING_CURRENCY=BDT
ACCOUNTING_DB_CONNECTION=mysql          # optional separate DB connection
ACCOUNTING_TABLE_PREFIX=acct_
ACCOUNTING_FISCAL_START_MONTH=1         # 1 = January
ACCOUNTING_FISCAL_AUTO_CREATE=true      # auto-create fiscal periods on first use
ACCOUNTING_ENFORCE_PERIOD_LOCK=true     # block posting to closed periods
ACCOUNTING_ROUNDING_TOLERANCE=0.005     # max debit/credit imbalance allowed
ACCOUNTING_ADMIN_ROLES=administrator,admin,superadmin
ACCOUNTING_ADMIN_ROLE_ATTRIBUTE=        # fallback attribute for role checking
ACCOUNTING_USER_FOREIGN_KEYS=false      # add FK constraints on created_by columns

# Pre-selected default accounts for the bank reconciliation adjusting-entry mini-form
ACCOUNTING_ACCOUNT_BANK_FEES_EXPENSE=6800
ACCOUNTING_ACCOUNT_INTEREST_INCOME=4900
```

## Artisan commands

```bash
# Seed a full multi-entity demo dataset (customers, suppliers, invoices, JEs, loans...)
php artisan accounting:demo

# Generate and export reports
php artisan accounting:report income-statement --start=2026-01-01 --end=2026-04-30
php artisan accounting:report balance-sheet    --date=2026-04-30  --format=csv --output=bs.csv
php artisan accounting:report trial-balance    --start=2026-01-01 --end=2026-04-30 --format=json
php artisan accounting:report sales-tax-liability --start=2026-04-01 --end=2026-04-30
# --type options: all | trial-balance | balance-sheet | income-statement | cash-flow | sales-tax-liability
# --format:       table | csv | json
```
