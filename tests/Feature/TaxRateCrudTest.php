<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Models\{Customer, Invoice, InvoiceItem, TaxRate};
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TaxRateCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_a_tax_rate(): void
    {
        $taxRate = TaxRate::create([
            'name'        => 'VAT Standard',
            'code'        => 'VAT',
            'rate'        => 15,
            'is_compound' => false,
            'is_active'   => true,
        ]);

        $this->assertDatabaseHas('acct_tax_rates', ['code' => 'VAT', 'name' => 'VAT Standard']);
        $this->assertEquals(15.0, (float) $taxRate->rate);
    }

    public function test_can_update_and_toggle_a_tax_rate(): void
    {
        $taxRate = TaxRate::factory()->create(['is_active' => true]);

        $taxRate->update(['is_active' => false]);

        $this->assertFalse($taxRate->fresh()->is_active);
    }

    public function test_soft_deleting_an_unreferenced_tax_rate_removes_it_from_default_queries(): void
    {
        $taxRate = TaxRate::factory()->create();

        $taxRate->delete();

        $this->assertNull(TaxRate::find($taxRate->id));
        $this->assertSoftDeleted('acct_tax_rates', ['id' => $taxRate->id]);
    }

    public function test_invoice_items_relation_detects_usage_for_the_delete_guard(): void
    {
        $taxRate = TaxRate::factory()->create(['rate' => 10]);
        $unused = TaxRate::factory()->create(['rate' => 5]);
        $customer = Customer::factory()->create();
        $invoice = Invoice::factory()->create(['customer_id' => $customer->id]);

        InvoiceItem::create([
            'invoice_id'  => $invoice->id,
            'description' => 'Widget',
            'quantity'    => 1,
            'unit_price'  => 100,
            'tax_rate_id' => $taxRate->id,
        ]);

        // TaxRateController::destroy() guards on this before allowing deletion.
        $this->assertTrue($taxRate->invoiceItems()->exists());
        $this->assertFalse($unused->invoiceItems()->exists());
    }

    public function test_deleting_an_unreferenced_tax_rate_does_not_affect_other_rates(): void
    {
        $taxRate = TaxRate::factory()->create();
        $other = TaxRate::factory()->create();

        $taxRate->delete();

        $this->assertNotNull($other->fresh());
    }
}
