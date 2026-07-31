# Owner's Equity

Capital contributions and owner drawings are posted as journal entries against the standard
equity accounts seeded by `initializeChartOfAccounts()`. There are two levels of tracking:

- **Company-wide (aggregate)** — post straight to the fixed `3000` Capital / `3200` Owner
  Drawings accounts. This is the only option if you don't need a per-owner breakdown.
- **Per-owner** — register each owner/partner via `addOwner()`, which auto-provisions a
  dedicated Capital and Drawings **sub-account** for that owner (under the standard `3000`/`3200`
  parents), so contributions and drawings can be attributed to a specific person while still
  rolling up into the aggregate balances on the Balance Sheet automatically.

Both levels support **multi-currency** contributions/drawings — see below.

## Account structure

| Code | Name | Normal balance | Purpose |
| --- | --- | --- | --- |
| `3000` | Capital (parent) | Credit | Owner / shareholder contributions — aggregate |
| `3001`–`3099` | Per-owner Capital sub-account | Credit | Auto-created by `addOwner()` |
| `3100` | Retained Earnings | Credit | Auto-updated by `closeFiscalYear()` — never post to this directly |
| `3200` | Owner Drawings (parent) | Debit (contra-equity) | Owner withdrawals — aggregate |
| `3201`–`3299` | Per-owner Drawings sub-account | Debit (contra-equity) | Auto-created by `addOwner()` |

Capital and Owner Drawings parent codes are configurable:

```env
ACCOUNTING_ACCOUNT_CAPITAL=3000
ACCOUNTING_ACCOUNT_OWNER_DRAWINGS=3200
```

resolved via `config('accounting.accounts.capital')` / `config('accounting.accounts.owner_drawings')`.

## Register an owner

`addOwner()` creates the `Owner` record and provisions its two GL sub-accounts in one transaction.

```php
use Centrex\Accounting\Facades\Accounting;

$owner = Accounting::addOwner([
    'code'                 => 'OWN-001',
    'name'                 => 'Jane Rahman',
    'email'                => 'jane@example.com',
    'ownership_percentage' => 60.0,          // optional, informational
    'notes'                => 'Founding shareholder',
]);
// → creates 3001 "Capital — Jane Rahman" and 3201 "Drawings — Jane Rahman"
```

`code`, `capital_account_id`, and `drawings_account_id` are immutable after creation — changing
them would orphan the historical journal lines already posted against those sub-accounts. `name`,
`email`, `ownership_percentage`, `notes`, and `is_active` can still be edited.

## Record a capital contribution

### For a specific owner

`recordOwnerContribution()` posts `DR <deposit account> / CR <that owner's Capital sub-account>`
and immediately posts the entry.

```php
$entry = Accounting::recordOwnerContribution($owner, [
    'amount'               => 500_000.00,
    'date'                 => today(),
    'deposit_account_code' => '1100',   // defaults to config('accounting.accounts.bank')
    'description'          => 'Capital injection — Q1 2026',
]);
// DR Bank 1100                 ৳5,00,000
// CR Capital — Jane Rahman 3001 ৳5,00,000
```

A foreign owner can contribute in their own currency — pass `currency`/`exchange_rate` and
`amount` is treated as that currency, converted to the accounting base currency for the journal
entry (same mechanism as `Invoice`/`Bill`/`LoanFacility`). There's no running balance to
reconcile against here, so this is a one-off per-transaction conversion — nothing is persisted
on `Owner` itself.

```php
$entry = Accounting::recordOwnerContribution($owner, [
    'amount'        => 10_000.00,     // USD 10,000
    'date'          => today(),
    'currency'      => 'USD',
    'exchange_rate' => 110.50,        // 1 USD = 110.50 BDT
    'description'   => 'Capital injection from overseas',
]);
// DR Bank 1100                 ৳11,05,000  (10,000 × 110.50)
// CR Capital — Jane Rahman 3001 ৳11,05,000
```

### Company-wide (no specific owner)

Post directly to the aggregate `3000` account using a plain journal entry — same
`currency`/`exchange_rate` handling applies:

```php
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
```

## Record an owner drawing

### For a specific owner

`recordOwnerDrawing()` posts `DR <that owner's Drawings sub-account> / CR <source account>`. It
takes the same `currency`/`exchange_rate` options as `recordOwnerContribution()`.

```php
$entry = Accounting::recordOwnerDrawing($owner, [
    'amount'              => 50_000.00,
    'date'                => today(),
    'source_account_code' => '1100',
    'description'         => 'Monthly draw',
]);
// DR Drawings — Jane Rahman 3201 ৳50,000
// CR Bank 1100                   ৳50,000
```

### Company-wide (no specific owner)

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
```

## Per-owner equity summary

```php
$summary = Accounting::getOwnerEquitySummary();
// Collection of, per active owner:
// [
//   'owner'            => Owner { ... },
//   'capital_balance'  => 500000.0,   // owner's Capital sub-account balance
//   'drawings_balance' => -50000.0,   // owner's Drawings sub-account balance (negative once drawn)
//   'net_equity'       => 450000.0,   // capital_balance + drawings_balance
// ]
```

`Owner::equityBalance()` computes the same `net_equity` figure for a single owner. It's `+` not
`-` on purpose: `Account::getCurrentBalance()` computes equity accounts as credits − debits, so
the Drawings sub-account balance comes out negative once real withdrawals post — adding that
already-negative number to the (positive) Capital balance nets correctly.

## Retained earnings rollup

`3100` is never posted to directly. `Accounting::closeFiscalYear($fiscalYear)` transfers the
year's net income into Retained Earnings via a closing journal entry, and
`getBalanceSheet()` reports `equity.retained_earnings` as prior Retained Earnings **plus**
cumulative net income accrued so far in the still-open fiscal year — see
[README.md § Fiscal Year Closing](../README.md#fiscal-year-closing).

## Web UI

Two pages, both under `/accounting/...`:

- **`/accounting/owners`** (route `accounting.owners`, gated by `accounting.owners.view` /
  `accounting.owners.manage`) — list, create, and edit owners via `Centrex\Accounting\Livewire\Owners`.
  Creating an owner calls `addOwner()` and provisions the sub-accounts; code is locked once set.
- **`/accounting/equity`** (route `accounting.equity`, gated by `accounting.equity.view` /
  `accounting.equity.manage`) — `Centrex\Accounting\Livewire\OwnerEquity` shows live Capital /
  Owner Drawings / Retained Earnings balances as stat cards, a **By Owner** table (capital,
  drawings, net equity per active owner), and a feed of posted equity journal lines.
  - **Record Contribution** / **Record Drawing** — each modal has an **Owner** picker
    ("Company-wide" or a specific owner), Amount, Currency, Exchange Rate, Date, and a
    cash/bank account picker restricted to active `10xx`/`11xx` accounts. Selecting an owner
    routes through `recordOwnerContribution()`/`recordOwnerDrawing()`; leaving it on
    "Company-wide" posts straight to the aggregate `3000`/`3200` accounts.

## Journal flow summary

| Event | DR | CR |
| --- | --- | --- |
| Capital contribution — company-wide | Bank `1100` (or any active `10xx`/`11xx`) | Capital `3000` |
| Capital contribution — per owner | Bank `1100` (or any active `10xx`/`11xx`) | Owner's Capital sub-account `300x` |
| Owner drawing — company-wide | Owner Drawings `3200` | Bank `1100` (or any active `10xx`/`11xx`) |
| Owner drawing — per owner | Owner's Drawings sub-account `320x` | Bank `1100` (or any active `10xx`/`11xx`) |
| Fiscal year close (automatic) | Revenue/Expense accounts (net) | Retained Earnings `3100` |
