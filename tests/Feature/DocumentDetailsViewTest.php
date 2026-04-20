<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Models\{Bill, BillItem, Customer, Invoice, InvoiceItem, Vendor};
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DocumentDetailsViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_details_route_renders(): void
    {
        $customer = Customer::create([
            'code' => 'CUST-001',
            'name' => 'Acme Corp',
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-TEST-00001',
            'customer_id' => $customer->id,
            'invoice_date' => '2026-04-20',
            'due_date' => '2026-05-20',
            'subtotal' => 100,
            'tax_amount' => 10,
            'discount_amount' => 0,
            'total' => 110,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'draft',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Consulting services',
            'quantity' => 1,
            'unit_price' => 100,
            'amount' => 100,
            'tax_rate' => 10,
            'tax_amount' => 10,
        ]);

        $response = $this->get(route('accounting.invoices.show', $invoice));

        $response->assertOk();
        $response->assertSee('Invoice INV-TEST-00001');
        $response->assertSee('Consulting services');
        $response->assertSee('Acme Corp');
    }

    public function test_bill_details_route_renders(): void
    {
        $vendor = Vendor::create([
            'code' => 'VEND-001',
            'name' => 'Cloud Hosting Ltd',
        ]);

        $bill = Bill::create([
            'bill_number' => 'BILL-TEST-00001',
            'vendor_id' => $vendor->id,
            'bill_date' => '2026-04-20',
            'due_date' => '2026-05-20',
            'subtotal' => 200,
            'tax_amount' => 20,
            'total' => 220,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'draft',
        ]);

        BillItem::create([
            'bill_id' => $bill->id,
            'description' => 'Infrastructure subscription',
            'quantity' => 1,
            'unit_price' => 200,
            'amount' => 200,
            'tax_rate' => 10,
            'tax_amount' => 20,
        ]);

        $response = $this->get(route('accounting.bills.show', $bill));

        $response->assertOk();
        $response->assertSee('Bill BILL-TEST-00001');
        $response->assertSee('Infrastructure subscription');
        $response->assertSee('Cloud Hosting Ltd');
    }
}
