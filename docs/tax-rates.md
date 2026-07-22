# Tax Rates

Invoice and bill line items have always had a per-line `tax_rate`/`tax_amount` pair, but historically the percentage was just typed in freehand. `TaxRate` turns it into a governed, reportable entity — a named rate (VAT, GST, a reduced rate) that line items select from instead of retyping a number every time. The free-typed percentage still works: `tax_rate_id` is optional, so nothing breaks for existing data or genuinely one-off rates.

## Manage tax rates

```php
use Centrex\Accounting\Models\TaxRate;

$vat = TaxRate::create([
    'name'        => 'VAT Standard',
    'code'        => 'VAT',
    'rate'        => 15.00,
    'is_compound' => false,
    'is_active'   => true,
]);

$vat->update(['rate' => 15.50]);      // future lines pick up the new rate; past lines don't
$vat->update(['is_active' => false]); // deactivate instead of deleting once it's been used
```

## Use a rate on a line item

Pass `tax_rate_id` when creating an `InvoiceItem`/`BillItem` — the current rate is **snapshotted** into the line's own `tax_rate`/`tax_amount` at save time, so a later edit to `TaxRate::rate` never retroactively changes an already-saved line:

```php
use Centrex\Accounting\Models\InvoiceItem;

$item = InvoiceItem::create([
    'invoice_id'  => $invoice->id,
    'description' => 'Samsung TV 55"',
    'quantity'    => 10,
    'unit_price'  => 15000,
    'tax_rate_id' => $vat->id,   // amount = 150000, tax_rate = 15.00, tax_amount = 22500
]);

// Free-typed fallback — omit tax_rate_id:
InvoiceItem::create([
    'invoice_id'  => $invoice->id,
    'description' => 'One-off negotiated rate',
    'quantity'    => 1,
    'unit_price'  => 1000,
    'tax_rate'    => 7.5,        // tax_amount = 75.00
]);
```

`is_compound` is stored for reporting but doesn't change per-line math today — the schema allows only one rate per line, so there's no second rate for it to compound on top of yet.

## Snapshot semantics

The snapshot only happens when `tax_rate_id` is newly set or changed (`InvoiceItemObserver`/`BillItemObserver`, via `ComputesLineItemAmounts`). Re-saving an existing line without touching `tax_rate_id` always uses the `tax_rate` already on the record — this is what keeps historical lines immune to later rate edits.

## Sales Tax Liability report

See [reports.md § Sales Tax Liability](reports.md#sales-tax-liability).

## REST API

```
GET    /api/accounting/tax-rates          list tax rates (?search=&active=)
POST   /api/accounting/tax-rates          create tax rate
GET    /api/accounting/tax-rates/{id}     get tax rate
PUT    /api/accounting/tax-rates/{id}     update tax rate
DELETE /api/accounting/tax-rates/{id}     delete tax rate (422 if referenced by an invoice/bill line)
```

## Web UI

```
GET /accounting/tax-rates    list and manage tax rates
```

Gates: `accounting.tax-rates.view`, `accounting.tax-rates.manage` — see [authorization.md](authorization.md).
