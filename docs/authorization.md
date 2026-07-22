# Authorization

`laravel-accounting` ships with a set of Laravel gates that protect every write action and sensitive read. All gates are registered in `AccountingServiceProvider::registerGates()` and follow a two-tier fallback model.

## How the fallback works

1. **Super-gate** — if your app defines `accounting-admin`, any user who passes it is granted all accounting abilities automatically.
2. **Role attribute** — if `accounting.admin_role_attribute` is configured and the user model has a `hasRole()` method, the gate checks `accounting.admin_roles`.
3. **Default** — deny (safe default until you wire up the gates).

## Grant blanket access via super-gate

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    // Anyone with the 'accountant' role gets all accounting abilities
    Gate::define('accounting-admin', function ($user): bool {
        return $user->hasRole('accountant');
    });
}
```

## Override individual gates

```php
public function boot(): void
{
    // Managers can view reports; only accountants can post entries
    Gate::define('accounting.reports.view', fn ($user) => $user->hasAnyRole(['accountant', 'manager']));
    Gate::define('accounting.journal.post',  fn ($user) => $user->hasRole('accountant'));
    Gate::define('accounting.budget.approve', fn ($user) => $user->hasRole('cfo'));
}
```

Gates are only defined by the package if the host app has **not** already registered them, so any definition in your `AppServiceProvider` takes precedence.

## Gate reference

| Gate | Description |
| --- | --- |
| **Journal Entries** | |
| `accounting.journal.view` | List and view journal entries |
| `accounting.journal.create` | Create draft journal entries |
| `accounting.journal.submit` | Submit entries for approval |
| `accounting.journal.post` | Post (approve) journal entries |
| `accounting.journal.void` | Void posted entries |
| **Invoices** | |
| `accounting.invoice.view` | List and view invoices |
| `accounting.invoice.create` | Create invoices |
| `accounting.invoice.post` | Post invoices to the GL |
| `accounting.invoice.payment` | Record invoice payments |
| **Bills** | |
| `accounting.bill.view` | List and view vendor bills |
| `accounting.bill.create` | Create vendor bills |
| `accounting.bill.post` | Post bills to the GL |
| `accounting.bill.payment` | Record bill payments |
| **Reports** | |
| `accounting.reports.view` | View all financial reports |
| **Chart of Accounts** | |
| `accounting.accounts.view` | Browse the chart of accounts |
| `accounting.accounts.manage` | Create / update / deactivate accounts |
| **Customers & Vendors** | |
| `accounting.customers.view` | View customer list and ledgers |
| `accounting.customers.manage` | Create and update customers |
| `accounting.vendors.view` | View vendor list and ledgers |
| `accounting.vendors.manage` | Create and update vendors |
| **Fiscal Year & Budgets** | |
| `accounting.fiscal-year.close` | Close a fiscal period or year |
| `accounting.budget.view` | View budgets and variance reports |
| `accounting.budget.manage` | Create and update budgets |
| `accounting.budget.approve` | Approve budgets |
| **Tax Rates** | |
| `accounting.tax-rates.view` | View tax rates |
| `accounting.tax-rates.manage` | Create / edit / deactivate tax rates |
| **Bank Reconciliation** | |
| `accounting.bank-reconciliation.view` | View bank reconciliations |
| `accounting.bank-reconciliation.create` | Start a new reconciliation |
| `accounting.bank-reconciliation.reconcile` | Complete a reconciliation |

## Check gates in code

```php
// In a controller or Livewire component
$this->authorize('accounting.journal.post');

// Or
if (Gate::denies('accounting.reports.view')) {
    abort(403);
}

// In Blade
@can('accounting.invoice.create')
    <x-tallui-button label="New Invoice" wire:click="create" />
@endcan
```
