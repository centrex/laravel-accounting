# Bank Reconciliation

Matches the GL activity on a bank/cash account against an actual bank statement — one `BankReconciliation` session per statement period, with `BankStatementLine` rows imported from the statement and matched against `JournalEntryLine`s one at a time. Unmatched lines (bank fees, interest earned) are resolved with an adjusting entry created directly from the reconciliation, and the session can't be completed until every statement line is accounted for and the running balance ties to the statement's ending balance within `ACCOUNTING_ROUNDING_TOLERANCE`.

## Start a reconciliation

```php
use Centrex\Accounting\Facades\Accounting;
use Centrex\Accounting\Models\Account;

$bank = Account::where('code', '1100')->first();

$reconciliation = Accounting::createBankReconciliation([
    'account_id'               => $bank->id,
    'statement_date'           => '2026-04-30',
    'opening_balance'          => 1_200_000.00,
    'statement_ending_balance' => 1_487_650.00,
]);
```

## Import statement lines

The facade takes plain, already-parsed rows — the Livewire workspace parses a pasted CSV (`date,description,amount,type,reference`) before calling this, so any source can feed it the same shape:

```php
Accounting::importBankStatementLines($reconciliation, [
    ['transaction_date' => '2026-04-02', 'description' => 'Customer wire — Rahman Brothers', 'amount' => 220000.00, 'type' => 'debit'],
    ['transaction_date' => '2026-04-15', 'description' => 'Monthly service charge',           'amount' => 250.00,    'type' => 'credit'],
]);
```

Malformed rows (bad date, non-numeric amount, invalid type) should be validated by the caller before this point — the Livewire workspace skips and reports bad rows individually rather than failing the whole import.

## Match statement lines to GL lines

```php
$unreconciled = Accounting::getUnreconciledLines($bank->id);   // posted GL lines not yet tied to a statement
$statementLine = $reconciliation->statementLines()->first();
$glLine = $unreconciled->first();

Accounting::matchStatementLine($statementLine, $glLine);
```

Validates, in order:

1. Neither side is already matched (`StatementLineAlreadyMatchedException`).
2. The amounts agree within `ACCOUNTING_ROUNDING_TOLERANCE` (`AmountToleranceExceededException` otherwise).
3. The debit/credit polarity matches exactly — a statement debit pairs with a GL debit line on that account, a statement credit with a GL credit line (`StatementLinePolarityMismatchException` otherwise; no sign-flip is needed since both vocabularies already align).

```php
// Made a mistake? Unmatch while the reconciliation is still draft:
Accounting::unmatchStatementLine($statementLine);
// Throws if the parent reconciliation is already completed.
```

## Resolve unmatched lines with an adjusting entry

For statement activity with no corresponding GL line — bank fees, interest earned — post one directly from the reconciliation. It builds a balanced two-line journal entry (the bank leg + an offset expense/revenue account) and matches the bank leg to the statement line in the same transaction:

```php
use Centrex\Accounting\Models\Account;

$bankFees = Account::where('code', config('accounting.accounts.bank_fees_expense', '6800'))->first();

$entry = Accounting::createAdjustingJournalEntryForStatementLine($statementLine, [
    'offset_account_id' => $bankFees->id,
    'description'       => 'Monthly service charge',
]);
```

`config('accounting.accounts.bank_fees_expense')` and `config('accounting.accounts.interest_income')` are pre-selected defaults for this form (env: `ACCOUNTING_ACCOUNT_BANK_FEES_EXPENSE` default `6800`, `ACCOUNTING_ACCOUNT_INTEREST_INCOME` default `4900`) — any active account can still be picked instead.

## Complete the reconciliation

```php
Accounting::completeBankReconciliation($reconciliation);
```

Throws if:

- Any statement line is still unmatched.
- `opening_balance + reconciled debits − reconciled credits` doesn't equal `statement_ending_balance` within tolerance — `ReconciliationBalanceMismatchException` reports the exact variance.

On success: `status` → `completed`, `reconciled_by`/`reconciled_at` stamped. Matched lines can no longer be unmatched once completed.

## REST API

```
GET    /api/accounting/bank-reconciliations                          list reconciliations (?account_id=)
POST   /api/accounting/bank-reconciliations                          start a reconciliation
GET    /api/accounting/bank-reconciliations/{id}                     get reconciliation with statement lines
POST   /api/accounting/bank-reconciliations/{id}/statement-lines     import statement lines (rows: [...])
POST   /api/accounting/bank-reconciliations/{id}/match                match a statement line to a GL line
POST   /api/accounting/bank-reconciliations/{id}/unmatch              unmatch a statement line
POST   /api/accounting/bank-reconciliations/{id}/complete             complete the reconciliation
```

## Web UI

```
GET /accounting/bank-reconciliations                     list reconciliations
GET /accounting/bank-reconciliations/{bankReconciliation} workspace — import, match, adjust, complete
```

Gates: `accounting.bank-reconciliation.view`, `.create`, `.reconcile` — see [authorization.md](authorization.md).
