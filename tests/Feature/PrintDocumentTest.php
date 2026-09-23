<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Livewire\{BillDetails, InvoiceDetails};
use Centrex\Accounting\Models\{Bill, BillItem, Customer, Invoice, InvoiceItem, Vendor};
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PrintDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(): Invoice
    {
        $customer = Customer::create(['code' => 'CUST-001', 'name' => 'Acme Corp']);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-TEST-00001', 'customer_id' => $customer->id,
            'invoice_date'   => '2026-04-20', 'due_date' => '2026-05-20',
            'subtotal'       => 100, 'tax_amount' => 10, 'discount_amount' => 0, 'total' => 110,
            'paid_amount'    => 0, 'currency' => 'BDT', 'status' => 'draft',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'description' => 'Consulting services',
            'quantity'   => 1, 'unit_price' => 100, 'amount' => 100, 'tax_rate' => 10, 'tax_amount' => 10,
        ]);

        return $invoice;
    }

    private function bill(): Bill
    {
        $vendor = Vendor::create(['code' => 'VEND-001', 'name' => 'Cloud Hosting Ltd']);

        $bill = Bill::create([
            'bill_number' => 'BILL-TEST-00001', 'vendor_id' => $vendor->id,
            'bill_date'   => '2026-04-20', 'due_date' => '2026-05-20',
            'subtotal'    => 200, 'tax_amount' => 20, 'total' => 220,
            'paid_amount' => 0, 'currency' => 'BDT', 'status' => 'draft',
        ]);

        BillItem::create([
            'bill_id'  => $bill->id, 'description' => 'Infrastructure subscription',
            'quantity' => 1, 'unit_price' => 200, 'amount' => 200, 'tax_rate' => 10, 'tax_amount' => 20,
        ]);

        return $bill;
    }

    public function test_invoice_details_page_shows_a_print_button(): void
    {
        $response = $this->get(route('accounting.invoices.show', $this->invoice()));

        $response->assertOk();
        $response->assertSee('Print Invoice');
        $response->assertSee('exportPdf', false);
    }

    public function test_bill_details_page_shows_a_print_button(): void
    {
        $response = $this->get(route('accounting.bills.show', $this->bill()));

        $response->assertOk();
        $response->assertSee('Print Bill');
        $response->assertSee('exportPdf', false);
    }

    public function test_invoice_pdf_export_degrades_gracefully_without_dompdf(): void
    {
        $this->assertFalse(class_exists(\Barryvdh\DomPDF\Facade\Pdf::class), 'This test assumes dompdf is not installed — see PDF export docblock.');

        $component = new InvoiceDetails;
        $component->mount($this->invoice());

        $this->assertNull($component->exportPdf());
        $this->assertSame('PDF export is not available in this environment.', session('error'));
    }

    public function test_bill_pdf_export_degrades_gracefully_without_dompdf(): void
    {
        $this->assertFalse(class_exists(\Barryvdh\DomPDF\Facade\Pdf::class), 'This test assumes dompdf is not installed — see PDF export docblock.');

        $component = new BillDetails;
        $component->mount($this->bill());

        $this->assertNull($component->exportPdf());
        $this->assertSame('PDF export is not available in this environment.', session('error'));
    }
}
