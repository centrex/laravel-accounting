# agents.md

## Agent Guidance — laravel-accounting

### Package Purpose
Full double-entry accounting system. Every financial operation creates balanced journal entries. The public API is almost entirely on the `Accounting` facade (`src/Accounting.php`).

### Before Making Changes
- Read `src/Accounting.php` — all high-level operations live here
- Read `src/Enums/` — `AccountType`, `JvStatus`, `EntryStatus` are used everywhere
- Read `src/Models/JournalEntry.php` and `src/Models/JournalLine.php` — core of the ledger
- Check `src/Events/` and `src/Listeners/` before touching invoice/payment flows (events fire on post/payment)

### Critical Invariants — Never Break
- **Journal entries must balance**: debits == credits (within 0.01 tolerance). `isBalanced()` is checked before posting.
- **Entry status is a one-way flow**: `draft → posted → void`. No skipping, no reversing.
- **Posted entries are immutable**: lines on a posted entry must not be modified.
- **Table prefix**: all models use `config('accounting.table_prefix')`. Never hardcode table names.

### Common Tasks

**Adding a new report**
1. Add a method to `src/Accounting.php`
2. Add a corresponding API endpoint in `routes/api.php` and `src/Http/Controllers/Api/`
3. Add a `JsonResource` in `src/Http/Resources/` for the response shape
4. Add a Livewire component in `src/Livewire/` and register the route in `routes/web.php`
5. Add tests

**Adding a new account type or transaction type**
- Add to the relevant enum in `src/Enums/`
- Update `Accounting::initializeChartOfAccounts()` if it affects standard accounts
- Update methods in `src/Accounting.php` that branch on account type (debit-normal vs credit-normal)

**Modifying migrations**
- Never modify existing migrations — always add new ones
- Use the table prefix: `config('accounting.table_prefix') . 'table_name'`

### Testing
```sh
composer test:unit        # pest — covers journal entry balancing, posting, voiding
composer test:types       # phpstan at level max — required before any PR
composer test:lint        # pint style
```

Test journal entry balance in any new financial operation:
```php
expect($entry->isBalanced())->toBeTrue();
```

### Environment Variables for Tests
The testbench workbench uses in-memory SQLite by default. Set `ACCOUNTING_DB_CONNECTION` to test with a specific connection.

### Safe Operations
- Adding new report methods to `Accounting.php`
- Adding new API endpoints (read-only reports)
- Adding new Livewire display components
- Adding new account types to the enum

### Risky Operations — Confirm Before Doing
- Changing `isBalanced()` tolerance threshold
- Modifying `post()` or `void()` logic on `JournalEntry`
- Changing `initializeChartOfAccounts()` — affects fresh installs
- Altering existing migration column types

### Do Not
- Allow posting an unbalanced journal entry
- Modify lines on a posted entry
- Remove the `ACCOUNTING_TABLE_PREFIX` support from any model
- Skip `declare(strict_types=1)` in any new file
