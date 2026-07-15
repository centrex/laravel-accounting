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

    private function postedInvoice(float $subtotal = 100, float $tax = 0): Invoice
    {
        $invoice = Invoice::factory()->create([
            'customer_id'     => Customer::factory()->create()->id,
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

    private function seedAccounts(): void
    {
        $accounts = [
            ['code' => '1000', 'name' => 'Cash',                       'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '1200', 'name' => 'Accounts Receivable',        'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '2300', 'name' => 'Sales Tax Payable',          'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '4000', 'name' => 'Sales Revenue',              'type' => 'revenue',   'subtype' => 'operating_revenue'],
            ['code' => '6134', 'name' => 'Sales Returns & Allowances', 'type' => 'expense',   'subtype' => 'selling_expense'],
        ];

        foreach ($accounts as $data) {
            Account::factory()->create($data);
        }
    }
}
