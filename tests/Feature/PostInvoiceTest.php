<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\LaravelAccounting\Accounting;
use Centrex\LaravelAccounting\Exceptions\{DuplicatePaymentException, InvalidStatusTransitionException, OverpaymentException};
use Centrex\LaravelAccounting\Models\{Account, Customer, Invoice, Payment};
use Centrex\LaravelAccounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PostInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Accounting $accounting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounting = app(Accounting::class);
        $this->seedMinimalAccounts();
    }

    // -------------------------------------------------------------------------
    // Posting
    // -------------------------------------------------------------------------

    public function test_post_invoice_creates_journal_entry_and_sets_issued_status(): void
    {
        $invoice = $this->createInvoice(subtotal: 100, tax: 10, total: 110);

        $entry = $this->accounting->postInvoice($invoice);

        $this->assertNotNull($entry);
        $this->assertEquals('issued', $invoice->fresh()->status->value);
        $this->assertDatabaseHas('acct_journal_entries', ['id' => $entry->id, 'status' => 'posted']);
    }

    public function test_post_invoice_creates_balanced_journal_entry(): void
    {
        $invoice = $this->createInvoice(subtotal: 200, tax: 30, total: 230);

        $entry = $this->accounting->postInvoice($invoice);

        $debits  = $entry->lines->where('type', 'debit')->sum('amount');
        $credits = $entry->lines->where('type', 'credit')->sum('amount');

        $this->assertEquals($debits, $credits);
        $this->assertEquals(230.0, $debits);
    }

    public function test_post_invoice_links_journal_entry_id(): void
    {
        $invoice = $this->createInvoice();

        $entry = $this->accounting->postInvoice($invoice);

        $this->assertEquals($entry->id, $invoice->fresh()->journal_entry_id);
    }

    public function test_posting_already_posted_invoice_throws(): void
    {
        $invoice = $this->createInvoice();
        $this->accounting->postInvoice($invoice);

        $this->expectException(InvalidStatusTransitionException::class);
        $this->accounting->postInvoice($invoice->fresh());
    }

    public function test_posting_settled_invoice_throws(): void
    {
        $invoice = $this->createInvoice(subtotal: 100, tax: 0, total: 100);
        $this->accounting->postInvoice($invoice);
        $this->accounting->recordInvoicePayment($invoice->fresh(), $this->paymentData(100));

        $this->expectException(InvalidStatusTransitionException::class);
        $this->accounting->postInvoice($invoice->fresh());
    }

    public function test_posting_fails_when_required_account_missing(): void
    {
        Account::where('code', '1200')->delete();
        $invoice = $this->createInvoice();

        $this->expectException(\Centrex\LaravelAccounting\Exceptions\AccountNotFoundException::class);
        $this->accounting->postInvoice($invoice);
    }

    // -------------------------------------------------------------------------
    // Payments
    // -------------------------------------------------------------------------

    public function test_full_payment_settles_invoice(): void
    {
        $invoice = $this->createInvoice(total: 110);
        $this->accounting->postInvoice($invoice);

        $payment = $this->accounting->recordInvoicePayment($invoice->fresh(), $this->paymentData(110));

        $fresh = $invoice->fresh();
        $this->assertEquals('settled', $fresh->status->value);
        $this->assertEquals('110.00', $fresh->paid_amount);
        $this->assertNotNull($payment->journal_entry_id);
    }

    public function test_partial_payment_sets_partially_settled_status(): void
    {
        $invoice = $this->createInvoice(total: 110);
        $this->accounting->postInvoice($invoice);

        $this->accounting->recordInvoicePayment($invoice->fresh(), $this->paymentData(50));

        $this->assertEquals('partially_settled', $invoice->fresh()->status->value);
        $this->assertEquals('50.00', $invoice->fresh()->paid_amount);
    }

    public function test_payment_creates_balanced_journal_entry(): void
    {
        $invoice = $this->createInvoice(total: 100);
        $this->accounting->postInvoice($invoice);

        $payment = $this->accounting->recordInvoicePayment($invoice->fresh(), $this->paymentData(100));

        $entry   = $payment->journalEntry;
        $debits  = $entry->lines->where('type', 'debit')->sum('amount');
        $credits = $entry->lines->where('type', 'credit')->sum('amount');

        $this->assertEquals($debits, $credits);
        $this->assertEquals(100.0, $debits);
    }

    public function test_overpayment_throws_exception(): void
    {
        $invoice = $this->createInvoice(total: 100);
        $this->accounting->postInvoice($invoice);

        $this->expectException(OverpaymentException::class);
        $this->accounting->recordInvoicePayment($invoice->fresh(), $this->paymentData(200));
    }

    public function test_duplicate_payment_throws_exception(): void
    {
        $invoice = $this->createInvoice(total: 200);
        $this->accounting->postInvoice($invoice);
        $data = $this->paymentData(100);

        $this->accounting->recordInvoicePayment($invoice->fresh(), $data);

        $this->expectException(DuplicatePaymentException::class);
        $this->accounting->recordInvoicePayment($invoice->fresh(), $data);
    }

    public function test_multiple_partial_payments_accumulate_correctly(): void
    {
        $invoice = $this->createInvoice(total: 300);
        $this->accounting->postInvoice($invoice);

        $this->accounting->recordInvoicePayment($invoice->fresh(), $this->paymentData(100, date: '2025-01-01'));
        $this->accounting->recordInvoicePayment($invoice->fresh(), $this->paymentData(100, date: '2025-01-02'));

        $fresh = $invoice->fresh();
        $this->assertEquals('partially_settled', $fresh->status->value);
        $this->assertEquals('200.00', $fresh->paid_amount);

        $this->accounting->recordInvoicePayment($invoice->fresh(), $this->paymentData(100, date: '2025-01-03'));
        $this->assertEquals('settled', $invoice->fresh()->status->value);
    }

    // -------------------------------------------------------------------------
    // Trial Balance
    // -------------------------------------------------------------------------

    public function test_trial_balance_is_balanced_after_posting_invoice(): void
    {
        $invoice = $this->createInvoice(subtotal: 200, tax: 30, total: 230);
        $this->accounting->postInvoice($invoice);

        $tb = $this->accounting->getTrialBalance();

        $this->assertTrue($tb['is_balanced']);
        $this->assertEquals($tb['total_debits'], $tb['total_credits']);
    }

    public function test_trial_balance_is_balanced_after_payment(): void
    {
        $invoice = $this->createInvoice(total: 110);
        $this->accounting->postInvoice($invoice);
        $this->accounting->recordInvoicePayment($invoice->fresh(), $this->paymentData(110));

        $tb = $this->accounting->getTrialBalance();

        $this->assertTrue($tb['is_balanced']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function seedMinimalAccounts(): void
    {
        $accounts = [
            ['code' => '1000', 'name' => 'Cash',                 'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '1200', 'name' => 'Accounts Receivable',  'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '2300', 'name' => 'Sales Tax Payable',    'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '4000', 'name' => 'Sales Revenue',        'type' => 'revenue',   'subtype' => 'operating_revenue'],
        ];

        foreach ($accounts as $data) {
            Account::factory()->create($data);
        }
    }

    private function createInvoice(float $subtotal = 100, float $tax = 10, float $total = 110): Invoice
    {
        $customer = Customer::factory()->create();

        return Invoice::factory()->create([
            'customer_id'    => $customer->id,
            'invoice_date'   => now()->toDateString(),
            'subtotal'       => $subtotal,
            'tax_amount'     => $tax,
            'discount_amount'=> 0,
            'total'          => $total,
            'currency'       => 'BDT',
            'status'         => 'draft',
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private function paymentData(float $amount = 110, string $date = '2025-06-01', string $method = 'cash', array $overrides = []): array
    {
        return array_merge([
            'amount' => $amount,
            'date'   => $date,
            'method' => $method,
        ], $overrides);
    }
}
