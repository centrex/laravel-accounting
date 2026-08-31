<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Exceptions\InvalidStatusTransitionException;
use Centrex\Accounting\Livewire\{BillDetails, InvoiceDetails};
use Centrex\Accounting\Models\{Account, Bill, Customer, Invoice, Vendor};
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * A draft invoice/bill has no GL impact yet — recording a payment, charge, or discount
 * against one would post AR/AP-affecting entries for a document the accounting system
 * doesn't consider real yet. These actions must wait until postInvoice()/postBill() has run.
 */
class RequiresPostedBeforeActionTest extends TestCase
{
    use RefreshDatabase;

    private Accounting $accounting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounting = app(Accounting::class);
        $this->seedAccounts();
    }

    public function test_recording_a_payment_on_a_draft_invoice_throws(): void
    {
        $invoice = $this->draftInvoice();

        $this->expectException(InvalidStatusTransitionException::class);
        $this->accounting->recordInvoicePayment($invoice, [
            'amount' => 50,
            'date'   => now()->toDateString(),
            'method' => 'cash',
        ]);
    }

    public function test_recording_a_payment_on_a_draft_bill_throws(): void
    {
        $bill = $this->draftBill();

        $this->expectException(InvalidStatusTransitionException::class);
        $this->accounting->recordBillPayment($bill, [
            'amount' => 50,
            'date'   => now()->toDateString(),
            'method' => 'cash',
        ]);
    }

    public function test_recording_a_charge_on_a_draft_invoice_is_rejected(): void
    {
        $invoice = $this->draftInvoice();

        Livewire::test(InvoiceDetails::class, ['invoice' => $invoice])
            ->call('openChargeModal')
            ->set('charge_type', '6310')
            ->set('charge_amount', '10')
            ->set('charge_date', now()->toDateString())
            ->set('charge_account_code', '1000')
            ->call('recordCharge');

        $this->assertSame(0, $invoice->fresh()->expenses()->count());
    }

    public function test_recording_a_discount_on_a_draft_invoice_is_rejected(): void
    {
        $invoice = $this->draftInvoice();

        Livewire::test(InvoiceDetails::class, ['invoice' => $invoice])
            ->call('openDiscountModal')
            ->set('discount_type', '6130')
            ->set('discount_amount', '10')
            ->set('discount_date', now()->toDateString())
            ->call('recordDiscount');

        $this->assertSame(0, $invoice->fresh()->expenses()->count());
    }

    public function test_recording_a_charge_on_a_draft_bill_is_rejected(): void
    {
        $bill = $this->draftBill();

        Livewire::test(BillDetails::class, ['bill' => $bill])
            ->call('openChargeModal')
            ->set('charge_type', '6310')
            ->set('charge_amount', '10')
            ->set('charge_date', now()->toDateString())
            ->set('charge_account_code', '1000')
            ->call('recordCharge');

        $this->assertSame(0, $bill->fresh()->expenses()->count());
    }

    public function test_recording_a_discount_on_a_draft_bill_is_rejected(): void
    {
        $bill = $this->draftBill();

        Livewire::test(BillDetails::class, ['bill' => $bill])
            ->call('openDiscountModal')
            ->set('discount_type', '5500')
            ->set('discount_amount', '10')
            ->set('discount_date', now()->toDateString())
            ->call('recordDiscount');

        $this->assertSame(0, $bill->fresh()->expenses()->count());
    }

    public function test_posted_invoice_accepts_payment_charge_and_discount(): void
    {
        $invoice = $this->draftInvoice();
        $this->accounting->postInvoice($invoice);
        $invoice = $invoice->fresh();

        $this->accounting->recordInvoicePayment($invoice, [
            'amount' => 10,
            'date'   => now()->toDateString(),
            'method' => 'cash',
        ]);

        Livewire::test(InvoiceDetails::class, ['invoice' => $invoice->fresh()])
            ->call('openChargeModal')
            ->set('charge_type', '6310')
            ->set('charge_amount', '5')
            ->set('charge_date', now()->toDateString())
            ->set('charge_account_code', '1000')
            ->call('recordCharge');

        Livewire::test(InvoiceDetails::class, ['invoice' => $invoice->fresh()])
            ->call('openDiscountModal')
            ->set('discount_type', '6130')
            ->set('discount_amount', '5')
            ->set('discount_date', now()->toDateString())
            ->call('recordDiscount');

        $this->assertSame(2, $invoice->fresh()->expenses()->count());
    }

    private function draftInvoice(): Invoice
    {
        return Invoice::factory()->create([
            'customer_id'     => Customer::factory()->create()->id,
            'invoice_date'    => now()->toDateString(),
            'subtotal'        => 100,
            'tax_amount'      => 0,
            'discount_amount' => 0,
            'total'           => 100,
            'currency'        => 'BDT',
            'status'          => 'draft',
        ]);
    }

    private function draftBill(): Bill
    {
        return Bill::factory()->create([
            'vendor_id'       => Vendor::factory()->create()->id,
            'bill_date'       => now()->toDateString(),
            'subtotal'        => 100,
            'tax_amount'      => 0,
            'discount_amount' => 0,
            'total'           => 100,
            'currency'        => 'BDT',
            'status'          => 'draft',
        ]);
    }

    private function seedAccounts(): void
    {
        $accounts = [
            ['code' => '1000', 'name' => 'Cash',                'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '1200', 'name' => 'Accounts Receivable',  'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '2000', 'name' => 'Accounts Payable',     'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2300', 'name' => 'Sales Tax Payable',    'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '4000', 'name' => 'Sales Revenue',        'type' => 'revenue',   'subtype' => 'operating_revenue'],
            ['code' => '6130', 'name' => 'Sales Discount',       'type' => 'expense',   'subtype' => 'selling_expense'],
            ['code' => '6310', 'name' => 'Courier Charge',       'type' => 'expense',   'subtype' => 'selling_expense'],
            ['code' => '5500', 'name' => 'Purchase Discount',    'type' => 'expense',   'subtype' => 'cost_of_goods_sold'],
        ];

        foreach ($accounts as $data) {
            Account::factory()->create($data);
        }
    }
}
