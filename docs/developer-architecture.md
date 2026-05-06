# Accounting Developer Architecture

This package owns ledger correctness. Other packages may create invoices, bills, expenses, and payments through the accounting service, but posting rules should stay inside `Centrex\Accounting\Accounting`.

## Main Boundaries

| Area | Primary classes | Responsibility |
| --- | --- | --- |
| Public service API | `Accounting` | Posting, payments, reports, budgets, loans, and period controls |
| Ledger models | `JournalEntry`, `JournalEntryLine`, `Account` | Double-entry ledger records |
| Receivables | `Invoice`, `InvoiceItem`, `Payment` | Customer billing and collection |
| Payables | `Bill`, `BillItem`, `Payment` | Vendor billing and settlement |
| Expenses | `Expense`, `ExpenseItem` | Operating and document-linked costs |
| UI | `Livewire/*`, `resources/views/livewire/*` | Accounting screens and reports |
| API | `Http\Controllers\Api/*`, `Http\Resources/*` | Programmatic access to accounting workflows |

## Expense Posting Rules

Use these rules when changing expense code:

| Scenario | Journal entry | Resulting status |
| --- | --- | --- |
| Cash expense | DR expense, DR tax if any, CR cash | `paid` |
| Credit expense | DR expense, DR tax if any, CR accounts payable | `approved` |
| Credit expense payment | DR accounts payable, CR cash | `partial` or `paid` |
| Invoice expense | Linked `Expense` with invoice as `chargeable` | Posted immediately |
| Bill expense | Linked `Expense` with bill as `chargeable` | Posted immediately |

`recordInvoiceExpense()` and `recordBillExpense()` intentionally share `recordDocumentExpense()`. Keep document-specific differences in the caller and shared ledger mechanics in the private implementation.

## Cross-Package Integration

Inventory should not build journal lines directly. It should call accounting service methods or store accounting document IDs returned by this package. Accounting should not depend on inventory classes for core ledger behavior, but it may support optional source metadata such as `source_type`, `inventory_sale_order_id`, or `inventory_purchase_order_id`.

## Complexity Rules

- Keep debit/credit construction near the method that owns the accounting event.
- Extract helpers when the same posting pattern appears in more than one public method.
- Avoid business calculations in Livewire components; call `Accounting`.
- Keep report aggregation SQL in service methods, not Blade views.
- Add a short doc section for every new ledger workflow, including DR/CR lines and status changes.

## Safe Change Checklist

Before changing posting logic:

- Verify the journal remains balanced.
- Verify duplicate payment protection still works.
- Verify status transitions are explicit.
- Run the feature test that covers the affected document type.
- Add a migration or data repair note if historical rows need recalculation.
