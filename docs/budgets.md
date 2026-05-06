# Budgets

## Create a budget

```php
use Centrex\Accounting\Facades\Accounting;
use Centrex\Accounting\Models\FiscalYear;

$fy = FiscalYear::where('is_current', true)->first();

$budget = Accounting::createBudget([
    'name'           => 'Q2 2026 Operating Budget',
    'fiscal_year_id' => $fy->id,
    'period_start'   => '2026-04-01',
    'period_end'     => '2026-06-30',
    'currency'       => 'BDT',
    'items' => [
        ['account_id' => $rentId,          'description' => 'Office rent Q2',    'amount' => 225000],
        ['account_id' => $salariesId,      'description' => 'Staff salaries Q2', 'amount' => 900000],
        ['account_id' => $marketingId,     'description' => 'Digital marketing', 'amount' => 150000],
        ['account_id' => $officeSuppliesId,'description' => 'Office supplies',   'amount' => 36000],
    ],
]);
// $budget->status => 'draft'
```

## Approve a budget

```php
Accounting::approveBudget($budget, auth()->id());
// $budget->status => 'approved' (uses pessimistic lock to prevent double-approval)
```

Only approved budgets appear in variance reports.

## Budget vs Actual

Compare budgeted amounts against actual expense spend for the budget period:

```php
$comparison = Accounting::getBudgetVsActual($budget);

foreach ($comparison['items'] as $item) {
    $item['account'];          // Account model
    $item['description'];      // budget item description
    $item['budgeted'];         // budgeted amount
    $item['actual'];           // actual spend (from Expense model, status approved/paid)
    $item['remaining'];        // budgeted - actual
    $item['percentage_used'];  // actual / budgeted × 100
    $item['over_budget'];      // bool — actual > budgeted
}

$comparison['total_budgeted'];
$comparison['total_actual'];
$comparison['total_remaining'];
$comparison['total_percentage_used'];
```

## Budget summary across all periods

```php
$summary = Accounting::getBudgetSummary(
    startDate: '2026-01-01',
    endDate:   '2026-12-31',
);
// Aggregated comparison across all approved budgets overlapping the given period
```

## REST API

```
GET  /api/accounting/budgets              list budgets
POST /api/accounting/budgets              create budget
POST /api/accounting/budgets/{id}/approve approve budget
GET  /api/accounting/budgets/{id}/vs-actual budget variance report
```

## Web UI

```
GET /accounting/budgets    list and manage budgets
```
