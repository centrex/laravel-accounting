# CLAUDE.md

## Package Overview

`centrex/laravel-accounting` — Full double-entry accounting system for Laravel with Livewire UI and REST API.

Namespace: `Centrex\Accounting\`  
Service Provider: `AccountingServiceProvider`  
Facade: `Facades/Accounting` → resolves `app('accounting')` → `Accounting` class

> Full usage documentation (journal entries, invoices, bills, reports, API endpoints, Livewire routes) is in the **root `CLAUDE.md`** of the `laravel_plugins` monorepo.

## Commands

Run from inside this directory (`cd laravel-accounting`):

```sh
composer install          # install dependencies
composer test             # full suite: rector dry-run, pint check, phpstan, pest
composer test:unit        # pest tests only
composer test:lint        # pint style check (read-only)
composer test:types       # phpstan static analysis
composer test:refacto     # rector refactor check (read-only)
composer lint             # apply pint formatting
composer refacto          # apply rector refactors
composer analyse          # phpstan (alias)
composer build            # prepare testbench workbench
composer start            # build + serve testbench dev server
```

Run a single test:
```sh
vendor/bin/pest tests/ExampleTest.php
vendor/bin/pest --filter "test name"
```

## Structure

```
src/
  Accounting.php                      # Main facade target — all public API methods
  AccountingServiceProvider.php
  Facades/Accounting.php
  Commands/
  Concerns/                           # Shared traits
  Enums/                              # AccountType, JvStatus, EntryStatus enums
  Events/                             # InvoicePosted, PaymentRecorded, etc.
  Exceptions/
  Http/
    Controllers/Api/                  # REST API controllers
    Requests/                         # Form request validation
    Resources/                        # JsonResource classes
  Listeners/                          # SyncCustomerOutstanding, NotifyAccountingTeam
  Livewire/                           # Livewire page components
  Mappers/
  Models/                             # Account, JournalEntry, JournalLine, Invoice, Bill, etc.
  Observers/
  Traits/
config/accounting.php
database/migrations/
routes/
  web.php
  api.php
resources/views/livewire/
tests/
workbench/
```

## Environment Variables

```env
ACCOUNTING_CURRENCY=BDT
ACCOUNTING_DB_CONNECTION=mysql
ACCOUNTING_TABLE_PREFIX=acct_
ACCOUNTING_FISCAL_START_MONTH=1
ACCOUNTING_FISCAL_AUTO_CREATE=true
```

## Key Design Decisions

- All DB tables use the prefix from `ACCOUNTING_TABLE_PREFIX` (default `acct_`)
- Supports a dedicated DB connection for multi-tenant isolation
- Every posted `JournalEntry` must balance (debits == credits within 0.01 tolerance)
- Entry status flow: `draft → posted → void` (enforced, no skipping)
- Invoice/Bill status auto-updates on payment: `partial` if part-paid, `paid` if fully paid

## Conventions

- PHP 8.2+, `declare(strict_types=1)` in all files
- Pest for tests, snake_case test names
- Pint with `laravel` preset
- Rector targeting PHP 8.3 with `CODE_QUALITY`, `DEAD_CODE`, `EARLY_RETURN`, `TYPE_DECLARATION`, `PRIVATIZATION` sets
- PHPStan at level `max` with Larastan
