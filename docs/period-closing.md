# Period & Fiscal Year Closing

## Fiscal period (month-end close)

### Pre-close checks

Before locking a period, check for blockers and warnings:

```php
use Centrex\Accounting\Facades\Accounting;
use Centrex\Accounting\Models\FiscalPeriod;

$period = FiscalPeriod::where('name', 'April 2026')->first();

$checks = Accounting::getPeriodCloseChecks($period);
// [
//   'unposted_journals' => 2,    // ← BLOCKER: must be 0 before closing
//   'open_invoices'     => 5,    // warning only (will carry forward)
//   'open_bills'        => 1,    // warning only
//   'has_blockers'      => true,
//   'has_warnings'      => true,
// ]

if ($checks['has_blockers']) {
    // Post or void the 2 unposted journal entries first
}
```

### Close the period

```php
$result = Accounting::closeFiscalPeriod(
    period:            $period,
    snapshotInventory: true,   // requires laravel-inventory with ERP bridge enabled
);

// $result['period']    — the now-closed FiscalPeriod model ($period->is_closed = true)
// $result['inventory'] — inventory reconciliation data (or null)
```

What `closeFiscalPeriod()` does:
1. Snapshots all GL account balances into `acct_account_balances` (performance cache)
2. Optionally captures `PeriodInventorySnapshot` rows (WAC + qty per product×warehouse)
3. Sets `$period->is_closed = true`

After closing, all entries dated within the period are blocked (unless `bypassPeriodLock: true`).

### Inventory reconciliation

```php
if ($result['inventory']) {
    $inv = $result['inventory'];
    echo "Snapshot lines:    {$inv['snapshot_count']}\n";
    echo "Physical value:    ৳ " . number_format($inv['physical_value'], 2) . "\n";
    echo "GL balance (1300): ৳ " . number_format($inv['gl_balance'], 2) . "\n";
    echo "Variance:          ৳ " . number_format($inv['variance'], 2) . "\n";
    echo "Reconciled: " . ($inv['is_reconciled'] ? 'YES ✓' : 'NO — post an adjustment') . "\n";
}
```

---

## Fiscal year (annual close)

```php
use Centrex\Accounting\Models\FiscalYear;

$fy = FiscalYear::where('name', '2025-26')->first();

Accounting::closeFiscalYear($fy);
```

What `closeFiscalYear()` does:
1. Calculates net income for the year
2. Creates a closing JE: DR Income Summary → CR Retained Earnings (3100)
3. Posts the entry (bypasses period lock automatically)
4. Sets `$fy->is_closed = true`

---

## Period close workflow checklist

1. **Day 27–28** — Cut-off: ensure all GRNs, fulfillments, invoices, bills, and bank transactions for the period are posted
2. **Day 29** — Adjusting entries: accruals, depreciation, prepayment amortisation, financing interest accrual
3. **Day 30** — Run `getPeriodCloseChecks()` — resolve any blockers
4. **Day 30** — Review Income Statement: verify revenue, COGS, and expenses
5. **Day 30** — Physical stock count: post any adjustments via `laravel-inventory`
6. **Day 30** — Close the period with `closeFiscalPeriod(snapshotInventory: true)`
7. **Day 30** — Verify inventory reconciliation is zero variance
8. **Day 1 (next month)** — New period opens automatically

See [real-world-example.md](real-world-example.md) for a fully worked month-end close.

---

## Scheduling interest accrual

Automate interest accrual so it never gets missed:

```php
// routes/console.php
use Centrex\Accounting\Facades\Accounting;
use Illuminate\Support\Facades\Schedule;

Schedule::call(fn () => Accounting::accrueAllFinancingInterest())
    ->monthlyOn(28, '23:00')
    ->name('accounting:accrue-inventory-interest')
    ->withoutOverlapping();

Schedule::call(fn () => Accounting::accrueAllLoanInterest())
    ->monthlyOn(28, '23:30')
    ->name('accounting:accrue-loan-interest')
    ->withoutOverlapping();
```
