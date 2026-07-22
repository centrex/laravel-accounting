# Invoices & Bills

## Invoices (customer billing)

### Create and post an invoice

```php
use Centrex\Accounting\Models\{Customer, Invoice};
use Centrex\Accounting\Facades\Accounting;

$customer = Customer::create([
    'code'          => 'CUST-001',
    'name'          => 'Rahman Brothers Ltd',
    'email'         => 'accounts@rahman.com',
    'phone'         => '+880 1711-000000',
    'credit_limit'  => 500000,
    'payment_terms' => 30,    // days
    'currency'      => 'BDT',
]);

$invoice = Invoice::create([
    'customer_id'     => $customer->id,
    'invoice_date'    => '2026-04-10',
    'due_date'        => '2026-05-10',
    'currency'        => 'BDT',
    'subtotal'        => 200000,
    'tax_amount'      => 30000,
    'discount_amount' => 10000,
    'total'           => 220000,
    'notes'           => 'Payment terms: Net 30.',
]);

$invoice->items()->createMany([
    ['description' => 'Samsung TV 55"',  'quantity' => 10, 'unit_price' => 15000, 'total' => 150000],
    ['description' => 'Samsung Fridge',  'quantity' => 5,  'unit_price' => 10000, 'total' => 50000],
]);

// Post → JE: DR Accounts Receivable 1200 / CR Sales Revenue 4000 + CR Sales Tax 2300
$je = Accounting::postInvoice($invoice);
// Fires: InvoicePosted → SyncCustomerOutstanding (queued listener)
```

### Record payments

```php
// Partial payment
Accounting::recordInvoicePayment($invoice, [
    'date'      => '2026-04-25',
    'amount'    => 100000,
    'method'    => 'bank_transfer',    // cash | bank_transfer | cheque | card | mobile_banking
    'reference' => 'TT-DHAKA-20260425',
]);
// $invoice->status → 'partially_settled'
// JE: DR Bank 1100 / CR AR 1200

// Full settlement
Accounting::recordInvoicePayment($invoice, [
    'date'      => '2026-05-08',
    'amount'    => 120000,
    'method'    => 'cheque',
    'reference' => 'CHQ-00547',
]);
// $invoice->status → 'settled'
```

### Multi-currency invoice

```php
$invoice = Invoice::create([
    'customer_id'   => $exportCustomer->id,
    'invoice_date'  => today(),
    'due_date'      => today()->addDays(60),
    'currency'      => 'USD',
    'exchange_rate' => 110.50,   // 1 USD = 110.50 BDT
    'subtotal'      => 5000,     // USD
    'total'         => 5000,
]);

// Post converts to BDT at the locked exchange rate
// JE posts 5000 × 110.50 = ৳ 552,500 to GL
Accounting::postInvoice($invoice);
```

### Using managed tax rates

Line items can link to a `TaxRate` instead of a free-typed percentage — the rate is snapshotted into `tax_rate`/`tax_amount` at save time, so a later edit to the rate never changes an already-saved line:

```php
use Centrex\Accounting\Models\{InvoiceItem, TaxRate};

$vat = TaxRate::create(['name' => 'VAT Standard', 'code' => 'VAT', 'rate' => 15.00, 'is_active' => true]);

InvoiceItem::create([
    'invoice_id'  => $invoice->id,
    'description' => 'Samsung TV 55"',
    'quantity'    => 10,
    'unit_price'  => 15000,
    'tax_rate_id' => $vat->id,   // tax_rate = 15.00, tax_amount computed automatically
]);
```

Full write-up: [tax-rates.md](tax-rates.md).

---

## Bills (vendor invoices)

### Create and post a bill

```php
use Centrex\Accounting\Models\{Vendor, Bill};

$vendor = Vendor::create([
    'code'          => 'VEND-001',
    'name'          => 'Global Electronics Ltd',
    'email'         => 'billing@globalelec.com',
    'payment_terms' => 45,
    'currency'      => 'BDT',
    'tax_id'        => 'BIN-123456789',
]);

$bill = Bill::create([
    'vendor_id'  => $vendor->id,
    'bill_date'  => '2026-04-05',
    'due_date'   => '2026-05-20',
    'subtotal'   => 400000,
    'tax_amount' => 60000,
    'total'      => 460000,
    'notes'      => 'Invoice #GEL-2026-0312',
]);

$bill->items()->createMany([
    ['description' => 'Samsung TV 55" — 30 units',  'quantity' => 30, 'unit_price' => 12000, 'total' => 360000],
    ['description' => 'Samsung Fridge — 8 units',   'quantity' => 8,  'unit_price' => 5000,  'total' => 40000],
]);

// Post → JE: DR Expense 5000 + DR VAT Input / CR Accounts Payable 2000
Accounting::postBill($bill);
```

### Record vendor payment

```php
Accounting::recordBillPayment($bill, [
    'date'      => '2026-05-15',
    'amount'    => 460000,
    'method'    => 'bank_transfer',
    'reference' => 'TT-OUT-20260515',
]);
// $bill->status → 'settled'
// JE: DR Accounts Payable 2000 / CR Bank 1100
```

---

## Expenses

```php
use Centrex\Accounting\Models\Expense;

// Cash expense — paid immediately on posting
$expense = Expense::create([
    'account_id'     => $officeSuppliesAccountId,
    'expense_date'   => today(),
    'subtotal'       => 12000,
    'tax_amount'     => 1800,
    'total'          => 13800,
    'payment_method' => 'cash',       // 'cash' | 'credit'
    'vendor_name'    => 'City Shop',
    'notes'          => 'Printer cartridges',
]);
$expense->items()->createMany([
    ['description' => 'HP Ink 3-pack', 'amount' => 12000],
]);

// Post → JE: DR Office Supplies / CR Cash (1000)
Accounting::postExpense($expense);

// Credit expense — pay later
$creditExpense = Expense::create([
    'account_id'     => $marketingId,
    'expense_date'   => today(),
    'due_date'       => today()->addDays(15),
    'total'          => 50000,
    'payment_method' => 'credit',     // creates AP entry
    'vendor_name'    => 'DigiAds BD',
]);
Accounting::postExpense($creditExpense);
// JE: DR Marketing Expense / CR Accounts Payable 2000

// Settle later
Accounting::recordExpensePayment($creditExpense, [
    'date'   => today()->addDays(15),
    'amount' => 50000,
    'method' => 'bank_transfer',
]);
```

---

## Customer & Vendor ledgers

### Outstanding balance

```php
$customer = Customer::find($id);
$outstanding = $customer->total_outstanding;
// Sum of (total - paid_amount) for issued/partially_settled/overdue invoices only
```

### Statement of account (via web UI)

```
GET /accounting/customers/{customer}/ledger?startDate=2026-01-01&endDate=2026-04-30
GET /accounting/vendors/{vendor}/ledger
```

Each ledger page shows: opening balance, transactions (invoices / payments), and closing balance for the selected period.
