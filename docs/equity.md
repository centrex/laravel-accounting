# Owner's Equity

Capital contributions and owner drawings are posted as plain journal entries against the
standard equity accounts seeded by `initializeChartOfAccounts()` — there's no dedicated
`LoanFacility`-style model behind equity, just three fixed accounts and two Livewire modals.

## Account structure

| Code | Name | Normal balance | Purpose |
| --- | --- | --- | --- |
| `3000` | Capital | Credit | Owner / shareholder contributions |
| `3100` | Retained Earnings | Credit | Auto-updated by `closeFiscalYear()` — never post to this directly |
| `3200` | Owner Drawings | Debit (contra-equity) | Owner withdrawals |

Capital and Owner Drawings are configurable:

```env
ACCOUNTING_ACCOUNT_CAPITAL=3000
ACCOUNTING_ACCOUNT_OWNER_DRAWINGS=3200
```

resolved via `config('accounting.accounts.capital')` / `config('accounting.accounts.owner_drawings')`.

## Record a capital contribution

```php
use Centrex\Accounting\Facades\Accounting;
use Centrex\Accounting\Models\Account;

$bank    = Account::where('code', '1100')->first();
$capital = Account::where('code', config('accounting.accounts.capital', '3000'))->first();

$entry = Accounting::createJournalEntry([
    'date'        => today(),
    'reference'   => 'CAP-001',
    'description' => 'Capital injection — founding shareholders',
    'currency'    => 'BDT',
    'lines' => [
        ['account_id' => $bank->id,    'type' => 'debit',  'amount' => 500_000.00],
        ['account_id' => $capital->id, 'type' => 'credit', 'amount' => 500_000.00],
    ],
]);
$entry->post();
// DR Bank 1100  ৳5,00,000
// CR Capital 3000  ৳5,00,000
```

## Record an owner drawing

```php
$drawings = Account::where('code', config('accounting.accounts.owner_drawings', '3200'))->first();
$bank     = Account::where('code', '1100')->first();

$entry = Accounting::createJournalEntry([
    'date'        => today(),
    'reference'   => 'DRAW-001',
    'description' => 'Owner withdrawal',
    'currency'    => 'BDT',
    'lines' => [
        ['account_id' => $drawings->id, 'type' => 'debit',  'amount' => 50_000.00],
        ['account_id' => $bank->id,     'type' => 'credit', 'amount' => 50_000.00],
    ],
]);
$entry->post();
// DR Owner Drawings 3200  ৳50,000
// CR Bank 1100            ৳50,000
```

## Retained earnings rollup

`3100` is never posted to directly. `Accounting::closeFiscalYear($fiscalYear)` transfers the
year's net income into Retained Earnings via a closing journal entry, and
`getBalanceSheet()` reports `equity.retained_earnings` as prior Retained Earnings **plus**
cumulative net income accrued so far in the still-open fiscal year — see
[README.md § Fiscal Year Closing](../README.md#fiscal-year-closing).

## Web UI

`/accounting/equity` (route `accounting.equity`, gated by `accounting.equity.view` /
`accounting.equity.manage`) shows live Capital / Owner Drawings / Retained Earnings balances
as stat cards, plus a feed of posted equity journal lines.

- **Record Contribution** — opens a modal (amount, date, a cash/bank account picker
  restricted to active `10xx`/`11xx` accounts) and posts `DR <bank account> / CR Capital` in
  one step.
- **Record Drawing** — same modal shape, posts `DR Owner Drawings / CR <bank account>`.

Both modals live in `Centrex\Accounting\Livewire\OwnerEquity` — there is no facade method to
call from a command or seeder; replicate the two journal entries above instead.

## Journal flow summary

| Event | DR | CR |
| --- | --- | --- |
| Capital contribution | Bank `1100` (or any active `10xx`/`11xx`) | Capital `3000` |
| Owner drawing | Owner Drawings `3200` | Bank `1100` (or any active `10xx`/`11xx`) |
| Fiscal year close (automatic) | Revenue/Expense accounts (net) | Retained Earnings `3100` |
