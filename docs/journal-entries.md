# Journal Entries

## Create a journal entry

```php
use Centrex\Accounting\Facades\Accounting;

$entry = Accounting::createJournalEntry([
    'date'        => '2026-04-30',
    'reference'   => 'RENT-APR-2026',
    'type'        => 'general',          // general | closing | adjustment
    'description' => 'April office rent payment',
    'currency'    => 'BDT',
    'sbu_code'    => 'DHK',              // optional SBU tag
    'created_by'  => auth()->id(),
    'lines' => [
        ['account_id' => $rentExpenseId, 'type' => 'debit',  'amount' => 75000, 'description' => 'April rent'],
        ['account_id' => $bankId,        'type' => 'credit', 'amount' => 75000],
    ],
]);
// $entry->status => JvStatus::DRAFT
```

## Two-step approval workflow

```php
// Accountant submits for review
$entry->submit();
// $entry->status        => JvStatus::SUBMITTED
// $entry->submitted_by  => auth()->id()
// $entry->submitted_at  => now()

// Reviewer approves → posts to GL
$entry->post();
// $entry->status       => JvStatus::POSTED
// $entry->approved_by  => auth()->id()
// $entry->approved_at  => now()

// Reviewer rejects → back to draft with a note
$entry->returnToDraft('Wrong expense account — use 5200 for admin costs');
// $entry->status        => JvStatus::DRAFT
// $entry->reviewer_note => 'Wrong expense account...'

// Users with accounting.journal.post gate can skip submit and post directly from draft
```

## Bypass the period lock

```php
// Force-post into a closed fiscal period (e.g., for correcting entries)
$entry->post(bypassPeriodLock: true);
```

## Void a posted entry

Voiding marks the entry as cancelled but does **not** reverse the GL. Post a compensating entry to reverse the GL effect:

```php
$entry->void();
// $entry->status => JvStatus::VOID

// Reverse the GL effect with a manually created reversing entry:
$reversal = Accounting::createJournalEntry([
    'date'        => today(),
    'reference'   => 'REV-' . $entry->entry_number,
    'type'        => 'adjustment',
    'description' => 'Reversal of: ' . $entry->description,
    'lines'       => $entry->lines->map(fn ($l) => [
        'account_id' => $l->account_id,
        'type'       => $l->type === 'debit' ? 'credit' : 'debit',
        'amount'     => $l->amount,
    ])->toArray(),
]);
$reversal->post();
```

## Multi-line complex entry

```php
// Purchase with VAT input credit and advance applied
$entry = Accounting::createJournalEntry([
    'date'        => '2026-04-15',
    'reference'   => 'PO-2026-047',
    'description' => 'Electronics batch purchase',
    'lines' => [
        ['account_id' => $inventoryId,    'type' => 'debit',  'amount' => 500000, 'description' => 'Goods'],
        ['account_id' => $vatInputId,     'type' => 'debit',  'amount' => 75000,  'description' => 'VAT 15%'],
        ['account_id' => $advanceToSupId, 'type' => 'credit', 'amount' => 100000, 'description' => 'Advance applied'],
        ['account_id' => $apId,           'type' => 'credit', 'amount' => 475000, 'description' => 'Balance payable'],
    ],
]);
$entry->submit();
$entry->post();
```

## isBalanced()

```php
$entry->isBalanced(); // true if debits == credits within rounding tolerance
```

Entries are validated before posting — an unbalanced entry throws an exception.

## Checking entry status in views

```php
$entry->status === \Centrex\Accounting\Enums\JvStatus::POSTED
$entry->status->label() // "Posted"
```
