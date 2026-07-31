# Industry Scenario Guide

The double-entry primitives in this package (`createJournalEntry`, `postInvoice`/`postBill`,
`postExpense`, loans/financing facilities, budgets, reports) are industry-agnostic — what changes
across business models is *which accounts get used, in what combination, and how often*. This
guide walks through six industry shapes, each with a **Simple** scenario (one clean, everyday
transaction) and a **Critical** scenario (the multi-step, higher-stakes case that's easy to get
wrong). Account codes are the standard chart from `Accounting::initializeChartOfAccounts()`
unless noted as a custom account the business would add itself.

For a full month-end close walkthrough, see [real-world-example.md](real-world-example.md). For
COGS-specific failure modes, see [cogs-scenarios.md](cogs-scenarios.md).

---

## 1. Commercial (mixed goods + services, corporate clients)

A general commercial enterprise that bills corporate clients for both product and service lines
on the same invoice — an IT reseller doing hardware supply *and* support contracts is typical.

### Simple: invoice a corporate client for hardware + a support retainer

```php
use Centrex\Accounting\Facades\Accounting;
use Centrex\Accounting\Models\Invoice;

$invoice = Invoice::create([
    'customer_id'  => $corporateClient->id,
    'invoice_date' => today(),
    'due_date'     => today()->addDays(30),
    'currency'     => 'BDT',
    'subtotal'     => 180000,
    'tax_amount'   => 27000,   // 15% VAT
    'total'        => 207000,
]);
$invoice->items()->createMany([
    ['description' => '10× Business Laptops',   'quantity' => 10, 'unit_price' => 15000, 'amount' => 150000],
    ['description' => 'Q2 IT Support Retainer', 'quantity' => 1,  'unit_price' => 30000, 'amount' => 30000],
]);

Accounting::postInvoice($invoice);
// JE: DR Accounts Receivable (1200)  ৳207,000
//     CR Sales Revenue (4000)        ৳150,000   (hardware)
//     CR Service Revenue (4100)      ৳ 30,000   (retainer)
//     CR Sales Tax Payable (2300)    ৳ 27,000
```

Note the split: hardware books to `4000`, the retainer to `4100` — a single invoice can span
revenue accounts by line item; `postInvoice()` groups by whatever account each `InvoiceItem` is
tagged with (see [invoices-bills.md](invoices-bills.md)).

### Critical: a client disputes the hardware line after partial payment

The client pays ৳100,000 against the ৳207,000 invoice, then disputes ৳20,000 of the hardware
line (a laptop was defective) after the payment already posted.

```php
// 1. Partial payment already recorded
Accounting::recordInvoicePayment($invoice, ['date' => today(), 'amount' => 100000, 'method' => 'bank_transfer']);
// Invoice status → partially_settled; due_amount = 107,000

// 2. Record the dispute as a sales discount against the invoice — NOT a direct AR write-down,
//    so the discount is auditable against this specific invoice (see invoices-bills.md's
//    "Record Discount" pattern, and the AR guard rule below).
use Centrex\Accounting\Models\{Account, Expense};

$discountAccount = Account::where('code', '6130')->first(); // Sales Discount
$expense = Expense::create([
    'chargeable_type' => Invoice::class,
    'chargeable_id'   => $invoice->id,
    'account_id'      => $discountAccount->id,
    'expense_date'    => today(),
    'subtotal'        => 20000, 'total' => 20000, 'paid_amount' => 20000,
    'currency'        => 'BDT', 'status' => 'paid', 'payment_method' => 'cash',
    'reference'       => $invoice->invoice_number,
]);
$entry = Accounting::createJournalEntry([
    'date' => today(), 'reference' => $invoice->invoice_number, 'type' => 'general',
    'description' => 'Sales discount — defective unit dispute',
    'lines' => [
        ['account_id' => $discountAccount->id,                       'type' => 'debit',  'amount' => 20000],
        ['account_id' => Account::where('code', '1200')->first()->id, 'type' => 'credit', 'amount' => 20000],
    ],
]);
$entry->post();
$expense->update(['journal_entry_id' => $entry->id]);

// New effective AR exposure: 207,000 − 100,000 (paid) − 20,000 (discount) = ৳87,000
```

**Guard rail:** payments + discounts recorded against an invoice can never exceed its total
(charges are excluded from this check) — the effective AR the system enforces is
`total − paid_amount − Σdiscounts`. Attempting to record a discount that would push AR negative
should be caught at the application layer before this point, not left to the ledger to silently
absorb.

---

## 2. Trading (import/export merchant)

Buys finished goods abroad, sells domestically — no manufacturing, no retail storefront. Margin
lives entirely in landed cost vs. resale price. See
[real-world-example.md](real-world-example.md) for a full month-end close for this exact
business shape.

### Simple: import shipment lands, sells to a domestic distributor

```php
use Centrex\Inventory\Facades\Inventory;

// Bill from the overseas supplier, in USD
$bill = Bill::create([
    'vendor_id' => $supplier->id, 'bill_date' => today(), 'due_date' => today()->addDays(45),
    'currency' => 'USD', 'exchange_rate' => 110.50,
    'subtotal' => 20000, 'tax_amount' => 0, 'total' => 20000,   // USD
]);
Accounting::postBill($bill);
// JE (base currency BDT): DR Inventory (1300) ৳2,210,000 / CR Accounts Payable (2000) ৳2,210,000

// Resold locally once landed
Accounting::postInvoice($invoice); // as in §1
```

### Critical: exchange rate moves between order and payment

The bill above was booked at 110.50 BDT/USD. By the time the vendor is actually paid, the rate has
moved to 112.00 — a ৳30,000 unrealized loss on a ৳20,000 USD payable that's now more expensive in
BDT terms.

```php
// Vendor payment, 45 days later, at the new rate
Accounting::recordBillPayment($bill, [
    'date' => today(), 'amount' => 20000, 'method' => 'bank_transfer', 'account_code' => '1100',
]);
// This settles the BDT-denominated AP balance (2,210,000) that was locked in at bill time —
// recordBillPayment() does NOT re-value the payable at a new rate; the bill's exchange_rate is
// fixed at creation. If the actual bank debit was ৳2,240,000 (20,000 × 112.00) because the
// company bought USD at the new spot rate to pay, book the FX difference explicitly:
$fxLossAccount = Account::firstOrCreate(
    ['code' => '6900'],
    ['name' => 'Foreign Exchange Loss', 'type' => 'expense', 'subtype' => 'other_expense', 'is_system' => false],
);
$fxEntry = Accounting::createJournalEntry([
    'date' => today(), 'reference' => $bill->bill_number . '-FX', 'type' => 'adjustment',
    'description' => 'Realized FX loss on vendor payment (110.50 → 112.00)',
    'lines' => [
        ['account_id' => $fxLossAccount->id, 'type' => 'debit',  'amount' => 30000],
        ['account_id' => Account::where('code', '1100')->first()->id, 'type' => 'credit', 'amount' => 30000],
    ],
]);
$fxEntry->post();
```

**Why this matters:** the package locks `exchange_rate` at document-creation time by design (so a
bill's cost basis doesn't silently shift while it's open) — which means realized FX gain/loss on
settlement is *always* a manual entry, never automatic. A trading company with meaningful FX
exposure should book this every time a foreign-currency bill/invoice is settled, not just when
someone remembers.

---

## 3. Retail (B2C storefront / POS)

High transaction volume, small ticket size, sales tax on every line, frequent returns.

### Simple: a POS sale, cash, with VAT

```php
// A POS sale is a fully-paid invoice at the moment of sale — no AR exposure.
$invoice = Invoice::create([
    'customer_id' => $walkInCustomerId, 'invoice_date' => today(), 'due_date' => today(),
    'currency' => 'BDT', 'subtotal' => 1200, 'tax_amount' => 180, 'total' => 1380,
]);
$invoice->items()->create(['description' => 'Sneakers — size 42', 'quantity' => 1, 'unit_price' => 1200, 'amount' => 1200]);
Accounting::postInvoice($invoice);
// DR AR (1200) 1,380 / CR Sales Revenue (4000) 1,200 / CR Sales Tax Payable (2300) 180

Accounting::recordInvoicePayment($invoice, ['date' => today(), 'amount' => 1380, 'method' => 'cash']);
// DR Cash (1000) 1,380 / CR AR (1200) 1,380 — net effect: cash sale, AR touches and clears same day
```

### Critical: a customer return after the sale, past the return window, with restocking

```php
// Return happens 10 days later — the original invoice is already settled and its period may
// already be close to locking. Post the return as its own dated entry, not a backdated edit
// to the original invoice.
$salesReturnAccount = Account::where('code', '6134')->first(); // Sales Returns & Allowances
$arAccount = Account::where('code', '1200')->first();

$returnEntry = Accounting::createJournalEntry([
    'date' => today(), 'reference' => $invoice->invoice_number . '-RET', 'type' => 'general',
    'description' => 'Customer return — sneakers, size 42 (defect)',
    'lines' => [
        ['account_id' => $salesReturnAccount->id, 'type' => 'debit',  'amount' => 1200],
        ['account_id' => $arAccount->id,           'type' => 'credit', 'amount' => 1200],
    ],
]);
$returnEntry->post();

// Refund the customer (already fully paid, so this is a cash outflow, not an AR reduction)
$refundEntry = Accounting::createJournalEntry([
    'date' => today(), 'reference' => $invoice->invoice_number . '-REFUND', 'type' => 'general',
    'description' => 'Cash refund for returned item',
    'lines' => [
        ['account_id' => $arAccount->id,                        'type' => 'debit',  'amount' => 1200],
        ['account_id' => Account::where('code', '1000')->first()->id, 'type' => 'credit', 'amount' => 1200],
    ],
]);
$refundEntry->post();

// Physical restock + COGS reversal is the inventory side of this — see
// laravel-inventory's Inventory::postSaleReturn() and cogs-scenarios.md §6 for the exact
// unit_cost_amount pitfall on returns older than the WAC has since moved.
```

**Retail-specific gotcha:** a return posted *after* the original invoice's period has closed
needs `bypassPeriodLock: true` on both entries above, or it must wait and land in the current
open period instead — never silently backdate it into the closed period's numbers.

---

## 4. Wholesale (B2B bulk distribution)

Large orders, negotiated credit terms (30/60/90 days), volume discounts, and — unlike retail —
customers who can genuinely go over their credit limit if nobody's watching.

### Simple: bulk order to a repeat B2B customer on 60-day terms

```php
$invoice = Invoice::create([
    'customer_id' => $distributor->id, 'invoice_date' => today(), 'due_date' => today()->addDays(60),
    'currency' => 'BDT', 'subtotal' => 850000, 'tax_amount' => 0, 'total' => 850000,
]);
$invoice->items()->create(['description' => 'Bulk order — 500 units @ ৳1,700', 'quantity' => 500, 'unit_price' => 1700, 'amount' => 850000]);
Accounting::postInvoice($invoice);
// DR AR (1200) 850,000 / CR Sales Revenue (4000) 850,000 — no cash movement for 60 days
```

### Critical: volume discount applied after the fact, customer near their credit limit

The distributor above hits a quarterly volume target retroactively, earning a 5% rebate
(৳42,500) on the order above — and is already close to their credit limit on other open
invoices.

```php
use Centrex\Inventory\Facades\Inventory;

// Check exposure BEFORE committing to the rebate — a rebate reduces AR, which is good for
// exposure, but if the customer is already over-limit the underlying credit policy question
// (should this customer get more orders at all?) is separate from the accounting entry.
// laravel-inventory's Customer is a distinct model from this package's own Customer — resolve
// the corresponding inv_customers.id for this client through whatever mapping your app keeps
// between the two (there's no built-in FK between them) before calling:
$creditSnapshot = Inventory::customerCreditSnapshot($inventoryCustomerId);
// {credit_limit_amount, outstanding_exposure, available_credit_amount, is_over_limit}

$volumeDiscountAccount = Account::where('code', '6132')->first(); // Volume Discount (Sales)
$expense = Expense::create([
    'chargeable_type' => Invoice::class, 'chargeable_id' => $invoice->id,
    'account_id' => $volumeDiscountAccount->id, 'expense_date' => today(),
    'subtotal' => 42500, 'total' => 42500, 'paid_amount' => 42500,
    'currency' => 'BDT', 'status' => 'paid', 'payment_method' => 'cash',
    'reference' => $invoice->invoice_number,
]);
$entry = Accounting::createJournalEntry([
    'date' => today(), 'reference' => $invoice->invoice_number, 'type' => 'general',
    'description' => 'Q2 volume rebate — 5% quarterly target achieved',
    'lines' => [
        ['account_id' => $volumeDiscountAccount->id, 'type' => 'debit',  'amount' => 42500],
        ['account_id' => Account::where('code', '1200')->first()->id, 'type' => 'credit', 'amount' => 42500],
    ],
]);
$entry->post();
$expense->update(['journal_entry_id' => $entry->id]);
// New effective AR on this invoice: 850,000 − 42,500 = ৳807,500
```

**Wholesale-specific gotcha:** volume rebates are almost always recognized *after* the revenue
they're earned against — resist the temptation to net them against the original invoice total at
creation time. Booking them as a separate discount entry (as above) keeps the original sale
figure intact for commission/target calculations that reference gross invoice value, while still
correctly reducing what's actually collectible.

---

## 5. Manufacturing (raw materials → WIP → finished goods)

Neither package ships a bill-of-materials/work-order system — there's no built-in "consume 3kg
steel + 2hrs labor → 1 unit finished good" recipe engine. Manufacturing costing here is booked
with plain journal entries against custom WIP/overhead accounts the business creates itself; the
`1300 Inventory` account represents finished + raw goods, and a custom `1350` represents
work-in-process.

### Simple: issue raw materials to production, absorb labor, complete a batch

```php
$wipAccount = Account::firstOrCreate(
    ['code' => '1350'],
    ['name' => 'Work in Process', 'type' => 'asset', 'subtype' => 'current_asset', 'is_system' => false],
);
$directLaborAccount = Account::firstOrCreate(
    ['code' => '6050'],
    ['name' => 'Direct Labor', 'type' => 'expense', 'subtype' => 'salaries_and_wages_expense', 'is_system' => false],
);
$rawMaterialsAccount = Account::where('code', '1300')->first(); // Inventory (raw materials)
$finishedGoodsAccount = $rawMaterialsAccount; // same physical Inventory account, different lot/warehouse in laravel-inventory

// 1. Issue ৳300,000 of raw steel to the production floor
$issue = Accounting::createJournalEntry([
    'date' => today(), 'reference' => 'WO-2026-014', 'type' => 'general',
    'description' => 'Raw material issue — production order WO-2026-014',
    'lines' => [
        ['account_id' => $wipAccount->id,          'type' => 'debit',  'amount' => 300000],
        ['account_id' => $rawMaterialsAccount->id, 'type' => 'credit', 'amount' => 300000],
    ],
]);
$issue->post();

// 2. Absorb direct labor for the batch (paid separately via payroll — this just allocates the
//    cost into the batch, it isn't the payroll cash entry itself)
$labor = Accounting::createJournalEntry([
    'date' => today(), 'reference' => 'WO-2026-014', 'type' => 'general',
    'description' => 'Direct labor absorption — WO-2026-014',
    'lines' => [
        ['account_id' => $wipAccount->id,        'type' => 'debit',  'amount' => 80000],
        ['account_id' => $directLaborAccount->id, 'type' => 'credit', 'amount' => 80000],
    ],
]);
$labor->post();

// 3. Batch completes — 200 finished units move from WIP back into Inventory at
//    ৳1,900/unit (300,000 + 80,000 = 380,000 ÷ 200)
$complete = Accounting::createJournalEntry([
    'date' => today(), 'reference' => 'WO-2026-014', 'type' => 'general',
    'description' => 'Batch complete — 200 units @ ৳1,900',
    'lines' => [
        ['account_id' => $finishedGoodsAccount->id, 'type' => 'debit',  'amount' => 380000],
        ['account_id' => $wipAccount->id,            'type' => 'credit', 'amount' => 380000],
    ],
]);
$complete->post();
// WIP account nets back to 0; Inventory now carries 200 finished units at a ৳1,900 unit cost —
// register that unit cost in laravel-inventory (e.g. as this batch's GRN unit_cost_amount) so
// the WAC engine picks it up for the products actually sold from this batch.
```

### Critical: overhead variance discovered after the batch already sold

Factory overhead (electricity, machine depreciation) was estimated at ৳20,000 for the batch above
and absorbed into WIP at that estimate. The actual utility bill arrives a month later at
৳27,000 — but by then, all 200 units have already sold and their COGS is already posted at the
*estimated* overhead rate.

```php
// The ৳7,000 variance can no longer be allocated to inventory that no longer exists — it must
// hit COGS directly (the units it belongs to have already left the building), not WIP or
// Inventory, which is why a "variance account" exists separately from both.
$overheadVarianceAccount = Account::firstOrCreate(
    ['code' => '5050'],
    ['name' => 'Manufacturing Overhead Variance', 'type' => 'expense', 'subtype' => 'cost_of_goods_sold', 'is_system' => false],
);
$variance = Accounting::createJournalEntry([
    'date' => today(), 'reference' => 'WO-2026-014-VAR', 'type' => 'adjustment',
    'description' => 'Overhead variance — actual utility bill vs. estimate, WO-2026-014 (already sold)',
    'lines' => [
        ['account_id' => $overheadVarianceAccount->id,               'type' => 'debit',  'amount' => 7000],
        ['account_id' => Account::where('code', '2000')->first()->id, 'type' => 'credit', 'amount' => 7000], // AP to utility co.
    ],
]);
$variance->post();
```

**Manufacturing-specific gotcha:** the moment a WIP batch's output has been sold, WIP/Inventory is
no longer a valid destination for any later cost correction on that batch — even a legitimate
one. Route it to a variance account against COGS (or a dedicated variance line on the Income
Statement) instead of retroactively editing an inventory value that no longer represents real
stock on hand.

---

## 6. Service (no inventory, billing for time)

No goods, no COGS in the traditional sense — cost of delivering the service is direct labor and
overhead, and revenue recognition timing (retainer vs. milestone vs. time-and-materials) is the
main risk area, not inventory valuation.

### Simple: time-and-materials invoice for a completed engagement

```php
$invoice = Invoice::create([
    'customer_id' => $client->id, 'invoice_date' => today(), 'due_date' => today()->addDays(15),
    'currency' => 'BDT', 'subtotal' => 240000, 'tax_amount' => 0, 'total' => 240000,
]);
$invoice->items()->create(['description' => '80 hrs consulting @ ৳3,000/hr', 'quantity' => 80, 'unit_price' => 3000, 'amount' => 240000]);
Accounting::postInvoice($invoice);
// DR AR (1200) 240,000 / CR Service Revenue (4100) 240,000
```

### Critical: a 12-month retainer paid upfront, recognized monthly

A client pays ৳1,200,000 upfront for a 12-month support retainer (৳100,000/month). Recognizing
the full amount as revenue on receipt would overstate this month's income and understate every
month after — this needs deferred revenue.

```php
$deferredRevenueAccount = Account::firstOrCreate(
    ['code' => '2600'],
    ['name' => 'Deferred Revenue', 'type' => 'liability', 'subtype' => 'current_liability', 'is_system' => false],
);
$serviceRevenueAccount = Account::where('code', '4100')->first();
$bankAccount = Account::where('code', '1100')->first();

// 1. Cash received upfront — booked entirely as a liability, not revenue
$receipt = Accounting::createJournalEntry([
    'date' => today(), 'reference' => 'RETAINER-2026-CLIENT-A', 'type' => 'general',
    'description' => '12-month retainer received upfront',
    'lines' => [
        ['account_id' => $bankAccount->id,            'type' => 'debit',  'amount' => 1200000],
        ['account_id' => $deferredRevenueAccount->id, 'type' => 'credit', 'amount' => 1200000],
    ],
]);
$receipt->post();

// 2. Each month, recognize 1/12th as actual revenue
$monthlyRecognition = Accounting::createJournalEntry([
    'date' => today()->endOfMonth(), 'reference' => 'RETAINER-2026-CLIENT-A-M1', 'type' => 'adjustment',
    'description' => 'Retainer revenue recognition — month 1 of 12',
    'lines' => [
        ['account_id' => $deferredRevenueAccount->id, 'type' => 'debit',  'amount' => 100000],
        ['account_id' => $serviceRevenueAccount->id,   'type' => 'credit', 'amount' => 100000],
    ],
]);
$monthlyRecognition->post();
// Repeat monthly for the remaining 11 months — schedule this the same way
// accrueAllFinancingInterest()/accrueAllLoanInterest() are scheduled monthly (see
// real-world-example.md), just against Deferred Revenue instead of an interest accrual.
```

**Service-specific gotcha:** a retainer client who cancels mid-term with an unearned balance
still sitting in Deferred Revenue needs that balance either refunded (`DR Deferred Revenue /
CR Bank`) or recognized immediately as a cancellation fee (`DR Deferred Revenue / CR Service
Revenue`) — it can't just be left on the balance sheet indefinitely once the engagement is
actually over.
