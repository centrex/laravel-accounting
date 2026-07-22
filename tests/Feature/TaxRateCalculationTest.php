<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Models\{Customer, Invoice, InvoiceItem, TaxRate};
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TaxRateCalculationTest extends TestCase
{
    use RefreshDatabase;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $customer = Customer::factory()->create();
        $this->invoice = Invoice::factory()->create(['customer_id' => $customer->id]);
    }

    public function test_selecting_a_tax_rate_computes_tax_amount_from_it(): void
    {
        $taxRate = TaxRate::factory()->create(['rate' => 15]);

        $item = InvoiceItem::create([
            'invoice_id'  => $this->invoice->id,
            'description' => 'Widget',
            'quantity'    => 2,
            'unit_price'  => 100,
            'tax_rate_id' => $taxRate->id,
        ]);

        $this->assertEquals(200.0, (float) $item->amount);
        $this->assertEquals(15.0, (float) $item->tax_rate);
        $this->assertEquals(30.0, (float) $item->tax_amount);
    }

    public function test_zero_rate_produces_zero_tax(): void
    {
        $taxRate = TaxRate::factory()->create(['rate' => 0]);

        $item = InvoiceItem::create([
            'invoice_id'  => $this->invoice->id,
            'description' => 'Widget',
            'quantity'    => 1,
            'unit_price'  => 50,
            'tax_rate_id' => $taxRate->id,
        ]);

        $this->assertEquals(0.0, (float) $item->tax_amount);
    }

    public function test_free_typed_tax_rate_still_works_without_a_tax_rate_id(): void
    {
        $item = InvoiceItem::create([
            'invoice_id'  => $this->invoice->id,
            'description' => 'Widget',
            'quantity'    => 1,
            'unit_price'  => 100,
            'tax_rate'    => 7.5,
        ]);

        $this->assertNull($item->tax_rate_id);
        $this->assertEquals(7.5, (float) $item->tax_rate);
        $this->assertEquals(7.5, (float) $item->tax_amount);
    }

    public function test_editing_tax_rate_after_save_does_not_change_historical_line(): void
    {
        $taxRate = TaxRate::factory()->create(['rate' => 10]);

        $item = InvoiceItem::create([
            'invoice_id'  => $this->invoice->id,
            'description' => 'Widget',
            'quantity'    => 1,
            'unit_price'  => 100,
            'tax_rate_id' => $taxRate->id,
        ]);

        $this->assertEquals(10.0, (float) $item->tax_amount);

        $taxRate->update(['rate' => 50]);

        // Re-saving the line without changing tax_rate_id must not re-snapshot the new rate.
        $item->description = 'Widget (updated description)';
        $item->save();

        $this->assertEquals(10.0, (float) $item->fresh()->tax_amount);
    }

    public function test_changing_tax_rate_id_on_an_existing_line_resnapshots(): void
    {
        $original = TaxRate::factory()->create(['rate' => 10]);
        $replacement = TaxRate::factory()->create(['rate' => 20]);

        $item = InvoiceItem::create([
            'invoice_id'  => $this->invoice->id,
            'description' => 'Widget',
            'quantity'    => 1,
            'unit_price'  => 100,
            'tax_rate_id' => $original->id,
        ]);

        $item->tax_rate_id = $replacement->id;
        $item->save();

        $this->assertEquals(20.0, (float) $item->fresh()->tax_amount);
    }
}
