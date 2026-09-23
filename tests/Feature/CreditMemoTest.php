<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Enums\CreditMemoStatus;
use Centrex\Accounting\Exceptions\{AccountingException, InvalidStatusTransitionException, OverpaymentException};
use Centrex\Accounting\Models\{Account, Customer, Invoice};
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CreditMemoTest extends TestCase
{
    use RefreshDatabase;

    private Accounting $accounting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounting = app(Accounting::class);
        $this->seedAccounts();
    }

    private function postedInvoice(float $subtotal = 100, float $tax = 0, ?int $customerId = null): Invoice
    {
        $invoice = Invoice::factory()->create([
            'customer_id'     => $customerId ?? Customer::factory()->create()->id,
            'invoice_date'    => now()->toDateString(),
            'subtotal'        => $subtotal,
            'tax_amount'      => $tax,
            'discount_amount' => 0,
            'total'           => $subtotal + $tax,
            'currency'        => 'BDT',
            'status'          => 'draft',
        ]);
        $this->accounting->postInvoice($invoice);

        return $invoice->fresh();
    }

    public function test_issuing_a_credit_memo_posts_reversing_journal_and_reduces_invoice_balance(): void
    {
        $invoice = $this->postedInvoice(100);

        $memo = $this->accounting->createCreditMemo($invoice, [
            'date'     => now()->toDateString(),
            'reason'   => 'Customer return SR-0001',
            'subtotal' => 40,
        ]);

        $this->assertSame(CreditMemoStatus::DRAFT, $memo->status);
        $this->assertStringStartsWith('CM-', $memo->credit_memo_number);
        // Draft memos have no accounting effect
        $this->assertEquals(100.0, $invoice->fresh()->balance);

        $entry = $this->accounting->issueCreditMemo($memo);
        $memo->refresh();

        $this->assertSame(CreditMemoStatus::ISSUED, $memo->status);
        $this->assertEquals($entry->id, $memo->journal_entry_id);
        $this->assertEquals(60.0, $invoice->fresh()->balance);

        $this->assertDatabaseHas('acct_journal_entries', ['id' => $entry->id, 'status' => 'posted']);

        $lines = $entry->lines()->with('account')->get();
        $debit = $lines->firstWhere('type', 'debit');
        $credit = $lines->firstWhere('type', 'credit');

        $this->assertEquals('6134', $debit->account->code);
        $this->assertEquals(40.0, (float) $debit->amount);
        $this->assertEquals('1200', $credit->account->code);
        $this->assertEquals(40.0, (float) $credit->amount);
    }

    public function test_issuing_with_tax_reversal_debits_tax_payable(): void
    {
        $invoice = $this->postedInvoice(100, tax: 15);

        $memo = $this->accounting->createCreditMemo($invoice, [
            'subtotal'   => 100,
            'tax_amount' => 15,
        ]);
        $entry = $this->accounting->issueCreditMemo($memo);

        $taxLine = $entry->lines()->whereHas('account', fn ($q) => $q->where('code', '2300'))->first();
        $arLine = $entry->lines()->whereHas('account', fn ($q) => $q->where('code', '1200'))->first();

        $this->assertNotNull($taxLine);
        $this->assertEquals('debit', $taxLine->type);
        $this->assertEquals(15.0, (float) $taxLine->amount);
        $this->assertEquals(115.0, (float) $arLine->amount);
        $this->assertEquals(0.0, $invoice->fresh()->balance);
    }

    public function test_credit_memos_cannot_exceed_the_invoice_total(): void
    {
        $invoice = $this->postedInvoice(100);

        $first = $this->accounting->createCreditMemo($invoice, ['subtotal' => 70]);
        $this->accounting->issueCreditMemo($first);

        $second = $this->accounting->createCreditMemo($invoice, ['subtotal' => 40]);

        $this->expectException(AccountingException::class);
        $this->accounting->issueCreditMemo($second);
    }

    public function test_refund_pays_credit_back_in_cash_and_tracks_status(): void
    {
        $invoice = $this->postedInvoice(100);
        // Settle the invoice first, then credit it — driving the balance negative
        $this->accounting->recordInvoicePayment($invoice, [
            'date' => now()->toDateString(), 'amount' => 100, 'method' => 'cash',
        ]);

        $memo = $this->accounting->createCreditMemo($invoice, ['subtotal' => 50]);
        $this->accounting->issueCreditMemo($memo);

        $this->assertEquals(-50.0, $invoice->fresh()->balance);

        $payment = $this->accounting->recordCreditMemoRefund($memo, [
            'date' => now()->toDateString(), 'amount' => 20, 'method' => 'cash',
        ]);
        $memo->refresh();

        $this->assertSame(CreditMemoStatus::PARTIALLY_REFUNDED, $memo->status);
        $this->assertEquals(20.0, (float) $memo->amount_refunded);
        $this->assertNotNull($payment->journal_entry_id);

        $lines = $payment->journalEntry->lines()->with('account')->get();
        $this->assertEquals('1200', $lines->firstWhere('type', 'debit')->account->code);
        $this->assertEquals('1000', $lines->firstWhere('type', 'credit')->account->code);

        $this->accounting->recordCreditMemoRefund($memo, [
            'date' => now()->addDay()->toDateString(), 'amount' => 30, 'method' => 'cash',
        ]);
        $memo->refresh();

        $this->assertSame(CreditMemoStatus::REFUNDED, $memo->status);
        $this->assertEquals(50.0, (float) $memo->amount_refunded);
        $this->assertEquals(0.0, $memo->refundable_amount);
    }

    public function test_unpaid_invoice_credit_memo_is_not_refundable(): void
    {
        $invoice = $this->postedInvoice(100);
        // Invoice was never paid — the return only lowers what the customer owes.
        $memo = $this->accounting->createCreditMemo($invoice, ['subtotal' => 50]);
        $this->accounting->issueCreditMemo($memo);
        $memo->refresh();

        $this->assertEquals(0.0, $memo->refundable_amount);

        $this->expectException(OverpaymentException::class);
        $this->accounting->recordCreditMemoRefund($memo, [
            'date' => now()->toDateString(), 'amount' => 10, 'method' => 'cash',
        ]);
    }

    public function test_credit_memo_refundable_amount_is_capped_by_cash_received_on_invoice(): void
    {
        $invoice = $this->postedInvoice(100);
        // Only half the invoice was paid before the return.
        $this->accounting->recordInvoicePayment($invoice, [
            'date' => now()->toDateString(), 'amount' => 50, 'method' => 'cash',
        ]);

        $memo = $this->accounting->createCreditMemo($invoice, ['subtotal' => 80]);
        $this->accounting->issueCreditMemo($memo);
        $memo->refresh();

        // Memo total is 80, but only 50 in cash was ever received — that's the cap.
        $this->assertEquals(50.0, $memo->refundable_amount);

        $payment = $this->accounting->recordCreditMemoRefund($memo, [
            'date' => now()->toDateString(), 'amount' => 50, 'method' => 'cash',
        ]);
        $this->assertNotNull($payment->id);

        $this->expectException(OverpaymentException::class);
        $this->accounting->recordCreditMemoRefund($memo, [
            'date' => now()->addDay()->toDateString(), 'amount' => 0.01, 'method' => 'cash',
        ]);
    }

    public function test_refund_cannot_exceed_the_remaining_credit(): void
    {
        $invoice = $this->postedInvoice(100);
        $memo = $this->accounting->createCreditMemo($invoice, ['subtotal' => 50]);
        $this->accounting->issueCreditMemo($memo);

        $this->expectException(OverpaymentException::class);
        $this->accounting->recordCreditMemoRefund($memo, [
            'date' => now()->toDateString(), 'amount' => 60, 'method' => 'cash',
        ]);
    }

    public function test_refunding_a_draft_memo_is_rejected(): void
    {
        $invoice = $this->postedInvoice(100);
        $memo = $this->accounting->createCreditMemo($invoice, ['subtotal' => 50]);

        $this->expectException(InvalidStatusTransitionException::class);
        $this->accounting->recordCreditMemoRefund($memo, [
            'date' => now()->toDateString(), 'amount' => 50, 'method' => 'cash',
        ]);
    }

    public function test_only_draft_memos_can_be_voided(): void
    {
        $invoice = $this->postedInvoice(100);

        $draft = $this->accounting->createCreditMemo($invoice, ['subtotal' => 10]);
        $this->accounting->voidCreditMemo($draft);
        $this->assertSame(CreditMemoStatus::VOID, $draft->fresh()->status);
        // Void memos never touch the balance
        $this->assertEquals(100.0, $invoice->fresh()->balance);

        $issued = $this->accounting->createCreditMemo($invoice, ['subtotal' => 10]);
        $this->accounting->issueCreditMemo($issued);

        $this->expectException(InvalidStatusTransitionException::class);
        $this->accounting->voidCreditMemo($issued->fresh());
    }

    public function test_credit_memo_requires_a_posted_invoice(): void
    {
        $invoice = Invoice::factory()->create([
            'customer_id'  => Customer::factory()->create()->id,
            'invoice_date' => now()->toDateString(),
            'subtotal'     => 100, 'tax_amount' => 0, 'discount_amount' => 0, 'total' => 100,
            'currency'     => 'BDT', 'status' => 'draft',
        ]);

        $this->expectException(AccountingException::class);
        $this->accounting->createCreditMemo($invoice, ['subtotal' => 10]);
    }

    public function test_credit_memo_can_be_applied_to_a_different_invoice_of_the_same_customer(): void
    {
        $customerId = Customer::factory()->create()->id;

        $invoiceA = $this->postedInvoice(100, customerId: $customerId);
        $this->accounting->recordInvoicePayment($invoiceA, [
            'date' => now()->toDateString(), 'amount' => 100, 'method' => 'cash',
        ]);
        $memo = $this->accounting->createCreditMemo($invoiceA, ['subtotal' => 50]);
        $this->accounting->issueCreditMemo($memo);
        $memo->refresh();
        $this->assertEquals(50.0, $memo->refundable_amount);

        $invoiceB = $this->postedInvoice(30, customerId: $customerId);

        $payment = $this->accounting->applyCreditMemoToInvoice($memo, $invoiceB, [
            'date' => now()->toDateString(), 'amount' => 30,
        ]);
        $memo->refresh();
        $invoiceB->refresh();

        $this->assertEquals('credit_memo', $payment->payment_method);
        $this->assertEquals($memo->credit_memo_number, $payment->reference);
        $this->assertEquals(30.0, (float) $memo->amount_refunded);
        $this->assertSame(CreditMemoStatus::PARTIALLY_REFUNDED, $memo->status);
        $this->assertEquals(20.0, $memo->refundable_amount);
        $this->assertEquals(30.0, (float) $invoiceB->paid_amount);
        $this->assertEquals(0.0, $invoiceB->balance);
        $this->assertEquals('settled', $invoiceB->status->value);

        // Both lines land on Accounts Receivable (1200) and net to zero there — no cash moved.
        $lines = $payment->journalEntry->lines()->with('account')->get();
        $this->assertCount(2, $lines);
        $this->assertTrue($lines->every(fn ($line) => $line->account->code === '1200'));
        $this->assertEquals(30.0, (float) $lines->firstWhere('type', 'debit')->amount);
        $this->assertEquals(30.0, (float) $lines->firstWhere('type', 'credit')->amount);

        // A mirrored Payment against the CreditMemo itself keeps CustomerLedger's
        // memoCredits/refunds netting correct — without it the memo's full $50 would
        // still look unclaimed even though $30 was just spent settling Invoice B.
        $memoPayment = $memo->payments()->first();
        $this->assertNotNull($memoPayment);
        $this->assertEquals(30.0, (float) $memoPayment->amount);
        $this->assertEquals($invoiceB->invoice_number, $memoPayment->reference);
        $this->assertEquals($payment->journal_entry_id, $memoPayment->journal_entry_id);
    }

    public function test_applying_credit_memo_is_capped_by_its_refundable_amount(): void
    {
        $customerId = Customer::factory()->create()->id;

        $invoiceA = $this->postedInvoice(100, customerId: $customerId);
        // Never paid — nothing real to apply elsewhere.
        $memo = $this->accounting->createCreditMemo($invoiceA, ['subtotal' => 50]);
        $this->accounting->issueCreditMemo($memo);

        $invoiceB = $this->postedInvoice(30, customerId: $customerId);

        $this->expectException(OverpaymentException::class);
        $this->accounting->applyCreditMemoToInvoice($memo, $invoiceB, [
            'date' => now()->toDateString(), 'amount' => 10,
        ]);
    }

    public function test_applying_credit_memo_cannot_exceed_the_target_invoices_balance(): void
    {
        $customerId = Customer::factory()->create()->id;

        $invoiceA = $this->postedInvoice(100, customerId: $customerId);
        $this->accounting->recordInvoicePayment($invoiceA, [
            'date' => now()->toDateString(), 'amount' => 100, 'method' => 'cash',
        ]);
        $memo = $this->accounting->createCreditMemo($invoiceA, ['subtotal' => 50]);
        $this->accounting->issueCreditMemo($memo);

        $invoiceB = $this->postedInvoice(30, customerId: $customerId);

        $this->expectException(OverpaymentException::class);
        $this->accounting->applyCreditMemoToInvoice($memo, $invoiceB, [
            'date' => now()->toDateString(), 'amount' => 40,
        ]);
    }

    public function test_credit_memo_cannot_be_applied_to_the_invoice_it_was_issued_against(): void
    {
        $invoice = $this->postedInvoice(100);
        $this->accounting->recordInvoicePayment($invoice, [
            'date' => now()->toDateString(), 'amount' => 100, 'method' => 'cash',
        ]);
        $memo = $this->accounting->createCreditMemo($invoice, ['subtotal' => 50]);
        $this->accounting->issueCreditMemo($memo);

        $this->expectException(AccountingException::class);
        $this->accounting->applyCreditMemoToInvoice($memo, $invoice, [
            'date' => now()->toDateString(), 'amount' => 10,
        ]);
    }

    public function test_credit_memo_cannot_be_applied_to_another_customers_invoice(): void
    {
        $invoiceA = $this->postedInvoice(100);
        $this->accounting->recordInvoicePayment($invoiceA, [
            'date' => now()->toDateString(), 'amount' => 100, 'method' => 'cash',
        ]);
        $memo = $this->accounting->createCreditMemo($invoiceA, ['subtotal' => 50]);
        $this->accounting->issueCreditMemo($memo);

        // A different customer's invoice.
        $invoiceB = $this->postedInvoice(30);

        $this->expectException(AccountingException::class);
        $this->accounting->applyCreditMemoToInvoice($memo, $invoiceB, [
            'date' => now()->toDateString(), 'amount' => 10,
        ]);
    }

    private function seedAccounts(): void
    {
        $accounts = [
            ['code' => '1000', 'name' => 'Cash',                       'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '1200', 'name' => 'Accounts Receivable',        'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '2300', 'name' => 'Sales Tax Payable',          'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '4000', 'name' => 'Sales Revenue',              'type' => 'revenue',   'subtype' => 'operating_revenue'],
            ['code' => '6134', 'name' => 'Sales Returns & Allowances', 'type' => 'revenue',   'subtype' => 'contra_revenue'],
        ];

        foreach ($accounts as $data) {
            Account::factory()->create($data);
        }
    }
}
