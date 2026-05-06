# Core Concepts

## Double-entry bookkeeping

Every `JournalEntry` has two or more lines. Debits must equal credits within the configured `rounding_tolerance` (default 0.005) before an entry can be posted. Only **posted** entries affect account balances and financial reports.

| Account type | Normal balance | Increases with | Decreases with |
| --- | --- | --- | --- |
| Asset | Debit | Debit | Credit |
| Liability | Credit | Credit | Debit |
| Equity | Credit | Credit | Debit |
| Revenue | Credit | Credit | Debit |
| Expense | Debit | Debit | Credit |

---

## Journal entry lifecycle (JvStatus)

```
DRAFT ──► SUBMITTED ──► POSTED ──► VOID
           (review)     (GL impact)
```

- **DRAFT** — being prepared; no GL impact
- **SUBMITTED** — sent for approval; no GL impact; `submitted_by` and `submitted_at` recorded
- **POSTED** — hits the General Ledger; affects all reports and balances
- **VOID** — cancelled; does not reverse the GL — post a separate reversing entry if needed

Users with the `accounting.journal.post` gate can skip the submit step and post directly from DRAFT.

---

## Invoice / Bill status (EntryStatus)

```
DRAFT → SENT/ISSUED → PARTIALLY_SETTLED → SETTLED
                                        → OVERDUE (if past due_date)
                     → VOID
```

Status updates automatically when payments are recorded.

---

## Period lock

When `ACCOUNTING_ENFORCE_PERIOD_LOCK=true` (default), attempting to post a journal entry dated within a closed `FiscalPeriod` throws `AccountingException`. Internal closing entries bypass the lock automatically via `post(bypassPeriodLock: true)`.

Disable temporarily for data migrations:

```env
ACCOUNTING_ENFORCE_PERIOD_LOCK=false
```

---

## SBU (Strategic Business Unit) tagging

Every `JournalEntry` has an optional `sbu_code` field. All report methods accept `?string $sbuCode` to filter results by business unit. Auto-resolved from warehouse, customer, supplier, or expense data when the entry is created by the ERP bridge.

---

## Weighted Average Cost (WAC)

Used when `laravel-accounting` is integrated with `laravel-inventory`. WAC is calculated per product per warehouse and feeds into COGS journal entries. See the [inventory documentation](../../laravel-inventory/docs/core-concepts.md) for the WAC formula.

---

## Account subtypes

The `AccountSubtype` enum has 183 cases covering every functional classification: `current_asset`, `fixed_asset`, `contra_asset`, `current_liability`, `long_term_liability`, `equity`, `revenue`, `cost_of_goods_sold`, `operating_expense`, `interest_expense`, `clearing`, `transit`, `suspense`, `memorandum`, and more.

Subtypes are informational — they drive report grouping but do not change debit/credit behaviour.
