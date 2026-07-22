<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Models\{Bill, BillItem, Customer, Invoice, InvoiceItem, TaxRate, Vendor};
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SalesTaxLiabilityReportTest extends TestCase
{
    use RefreshDatabase;

    private Accounting $accounting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounting = app(Accounting::class);
    }

    public function test_report_groups_and_nets_by_tax_rate_including_unassigned_bucket(): void
    {
        $vat = TaxRate::factory()->create(['name' => 'VAT Standard', 'code' => 'VAT', 'rate' => 15]);

        $customer = Customer::factory()->create();
        $invoice = Invoice::factory()->create([
            'customer_id'  => $customer->id,
            'invoice_date' => '2025-06-15',
            'status'       => 'issued',
        ]);
        InvoiceItem::create([
            'invoice_id'  => $invoice->id,
            'description' => 'Taxed item',
            'quantity'    => 1,
            'unit_price'  => 200,
            'tax_rate_id' => $vat->id,
        ]);
        InvoiceItem::create([
            'invoice_id'  => $invoice->id,
            'description' => 'Ad-hoc taxed item',
            'quantity'    => 1,
            'unit_price'  => 100,
            'tax_rate'    => 5,
        ]);

        $vendor = Vendor::factory()->create();
        $bill = Bill::factory()->create([
            'vendor_id' => $vendor->id,
            'bill_date' => '2025-06-20',
            'status'    => 'issued',
        ]);
        BillItem::create([
            'bill_id'     => $bill->id,
            'description' => 'Taxed purchase',
            'quantity'    => 1,
            'unit_price'  => 100,
            'tax_rate_id' => $vat->id,
        ]);

        $report = $this->accounting->getSalesTaxLiabilityReport('2025-06-01', '2025-06-30');

        $vatRow = collect($report['rows'])->firstWhere('code', 'VAT');
        $unassignedRow = collect($report['rows'])->firstWhere('name', 'Unassigned / Ad-hoc');

        $this->assertEquals(30.0, $vatRow['collected']); // 200 * 15%
        $this->assertEquals(15.0, $vatRow['paid']); // 100 * 15%
        $this->assertEquals(15.0, $vatRow['net_payable']);

        $this->assertEquals(5.0, $unassignedRow['collected']); // 100 * 5%
        $this->assertEquals(0.0, $unassignedRow['paid']);

        $this->assertEquals(35.0, $report['total_collected']);
        $this->assertEquals(15.0, $report['total_paid']);
        $this->assertEquals(20.0, $report['total_net_payable']);
    }

    public function test_draft_invoices_are_excluded_from_the_report(): void
    {
        $vat = TaxRate::factory()->create(['rate' => 15]);
        $customer = Customer::factory()->create();
        $invoice = Invoice::factory()->create([
            'customer_id'  => $customer->id,
            'invoice_date' => '2025-06-15',
            'status'       => 'draft',
        ]);
        InvoiceItem::create([
            'invoice_id'  => $invoice->id,
            'description' => 'Taxed item',
            'quantity'    => 1,
            'unit_price'  => 200,
            'tax_rate_id' => $vat->id,
        ]);

        $report = $this->accounting->getSalesTaxLiabilityReport('2025-06-01', '2025-06-30');

        $this->assertEquals(0.0, $report['total_collected']);
    }
}
