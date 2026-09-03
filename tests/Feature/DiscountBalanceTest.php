<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Livewire\{BillDetails, InvoiceDetails};
use Centrex\Accounting\Models\{Account, Bill, Customer, Invoice, Vendor};
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class DiscountBalanceTest extends TestCase
{
    use RefreshDatabase;

    private Accounting $accounting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounting = app(Accounting::class);
        $this->seedAccounts();
    }

    public function test_recording_an_invoice_discount_reduces_balance(): void
    {
        $invoice = Invoice::factory()->create([
            'customer_id'     => Customer::factory()->create()->id,
            'invoice_date'    => now()->toDateString(),
            'subtotal'        => 100,
            'tax_amount'      => 0,
            'discount_amount' => 0,
            'total'           => 100,
            'currency'        => 'BDT',
            'status'          => 'draft',
        ]);
        $this->accounting->postInvoice($invoice);
        $invoice = $invoice->fresh();

        $this->assertEquals(100.0, $invoice->balance);

        Livewire::test(InvoiceDetails::class, ['invoice' => $invoice])
            ->call('openDiscountModal')
            ->set('discount_type', '6130')
            ->set('discount_amount', '20')
            ->set('discount_date', now()->toDateString())
            ->call('recordDiscount');

        $this->assertEquals(80.0, $invoice->fresh()->balance);
    }

    public function test_recording_a_bill_discount_reduces_balance(): void
    {
        $bill = Bill::factory()->create([
            'vendor_id'       => Vendor::factory()->create()->id,
            'bill_date'       => now()->toDateString(),
            'subtotal'        => 100,
            'tax_amount'      => 0,
            'discount_amount' => 0,
            'total'           => 100,
            'currency'        => 'BDT',
            'status'          => 'draft',
        ]);
        $this->accounting->postBill($bill);
        $bill = $bill->fresh();

        $this->assertEquals(100.0, $bill->balance);

        Livewire::test(BillDetails::class, ['bill' => $bill])
            ->call('openDiscountModal')
            ->set('discount_type', '5500')
            ->set('discount_amount', '15')
            ->set('discount_date', now()->toDateString())
            ->call('recordDiscount');

        $this->assertEquals(85.0, $bill->fresh()->balance);
    }

    public function test_invoice_payment_cannot_exceed_balance_after_a_discount(): void
    {
        $invoice = Invoice::factory()->create([
            'customer_id'     => Customer::factory()->create()->id,
            'invoice_date'    => now()->toDateString(),
            'subtotal'        => 100,
            'tax_amount'      => 0,
            'discount_amount' => 0,
            'total'           => 100,
            'currency'        => 'BDT',
            'status'          => 'draft',
        ]);
        $this->accounting->postInvoice($invoice);
        $invoice = $invoice->fresh();

        Livewire::test(InvoiceDetails::class, ['invoice' => $invoice])
            ->call('openDiscountModal')
            ->set('discount_type', '6130')
            ->set('discount_amount', '20')
            ->set('discount_date', now()->toDateString())
            ->call('recordDiscount');

        // Only 80 is actually still owed (100 total − 20 discount) — a payment for the full
        // pre-discount total must be rejected, not silently accepted past the true balance.
        $this->expectException(\Centrex\Accounting\Exceptions\OverpaymentException::class);
        $this->accounting->recordInvoicePayment($invoice->fresh(), [
            'amount' => 90,
            'date'   => now()->toDateString(),
            'method' => 'cash',
        ]);
    }

    public function test_invoice_payment_up_to_the_discounted_balance_settles_it(): void
    {
        $invoice = Invoice::factory()->create([
            'customer_id'     => Customer::factory()->create()->id,
            'invoice_date'    => now()->toDateString(),
            'subtotal'        => 100,
            'tax_amount'      => 0,
            'discount_amount' => 0,
            'total'           => 100,
            'currency'        => 'BDT',
            'status'          => 'draft',
        ]);
        $this->accounting->postInvoice($invoice);
        $invoice = $invoice->fresh();

        Livewire::test(InvoiceDetails::class, ['invoice' => $invoice])
            ->call('openDiscountModal')
            ->set('discount_type', '6130')
            ->set('discount_amount', '20')
            ->set('discount_date', now()->toDateString())
            ->call('recordDiscount');

        $this->accounting->recordInvoicePayment($invoice->fresh(), [
            'amount' => 80,
            'date'   => now()->toDateString(),
            'method' => 'cash',
        ]);

        $this->assertEquals(0.0, $invoice->fresh()->balance);
    }

    public function test_bill_payment_cannot_exceed_balance_after_a_discount(): void
    {
        $bill = Bill::factory()->create([
            'vendor_id'       => Vendor::factory()->create()->id,
            'bill_date'       => now()->toDateString(),
            'subtotal'        => 100,
            'tax_amount'      => 0,
            'discount_amount' => 0,
            'total'           => 100,
            'currency'        => 'BDT',
            'status'          => 'draft',
        ]);
        $this->accounting->postBill($bill);
        $bill = $bill->fresh();

        Livewire::test(BillDetails::class, ['bill' => $bill])
            ->call('openDiscountModal')
            ->set('discount_type', '5500')
            ->set('discount_amount', '15')
            ->set('discount_date', now()->toDateString())
            ->call('recordDiscount');

        $this->expectException(\Centrex\Accounting\Exceptions\OverpaymentException::class);
        $this->accounting->recordBillPayment($bill->fresh(), [
            'amount' => 90,
            'date'   => now()->toDateString(),
            'method' => 'cash',
        ]);
    }

    private function seedAccounts(): void
    {
        $accounts = [
            ['code' => '1000', 'name' => 'Cash',                'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '1200', 'name' => 'Accounts Receivable',  'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '2000', 'name' => 'Accounts Payable',     'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '1300', 'name' => 'Inventory Asset',      'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '2300', 'name' => 'Sales Tax Payable',    'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '4000', 'name' => 'Sales Revenue',        'type' => 'revenue',   'subtype' => 'operating_revenue'],
            ['code' => '6130', 'name' => 'Sales Discount',       'type' => 'revenue',   'subtype' => 'contra_revenue'],
            ['code' => '5500', 'name' => 'Purchase Discount',    'type' => 'expense',   'subtype' => 'cost_of_goods_sold'],
        ];

        foreach ($accounts as $data) {
            Account::factory()->create($data);
        }
    }
}
