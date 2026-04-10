<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Exceptions\{DuplicatePaymentException, InvalidStatusTransitionException, OverpaymentException};
use Centrex\Accounting\Models\{Account, Bill, Vendor};
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PostBillTest extends TestCase
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

    public function test_post_bill_creates_journal_entry_and_sets_issued_status(): void
    {
        $bill = $this->createBill(subtotal: 100, tax: 10, total: 110);

        $entry = $this->accounting->postBill($bill);

        $this->assertNotNull($entry);
        $this->assertEquals('issued', $bill->fresh()->status->value);
        $this->assertDatabaseHas('acct_journal_entries', ['id' => $entry->id, 'status' => 'posted']);
    }

    public function test_post_bill_creates_balanced_journal_entry(): void
    {
        $bill = $this->createBill(subtotal: 200, tax: 30, total: 230);

        $entry = $this->accounting->postBill($bill);

        $debits = $entry->lines->where('type', 'debit')->sum('amount');
        $credits = $entry->lines->where('type', 'credit')->sum('amount');

        $this->assertEquals($debits, $credits);
        $this->assertEquals(230.0, $debits);
    }

    public function test_post_bill_links_journal_entry_id(): void
    {
        $bill = $this->createBill();
        $entry = $this->accounting->postBill($bill);

        $this->assertEquals($entry->id, $bill->fresh()->journal_entry_id);
    }

    public function test_posting_already_posted_bill_throws(): void
    {
        $bill = $this->createBill();
        $this->accounting->postBill($bill);

        $this->expectException(InvalidStatusTransitionException::class);
        $this->accounting->postBill($bill->fresh());
    }

    public function test_posting_settled_bill_throws(): void
    {
        $bill = $this->createBill(subtotal: 100, tax: 0, total: 100);
        $this->accounting->postBill($bill);
        $this->accounting->recordBillPayment($bill->fresh(), $this->paymentData(100));

        $this->expectException(InvalidStatusTransitionException::class);
        $this->accounting->postBill($bill->fresh());
    }

    public function test_posting_fails_when_required_account_missing(): void
    {
        Account::where('code', '2000')->delete();
        $bill = $this->createBill();

        $this->expectException(\Centrex\Accounting\Exceptions\AccountNotFoundException::class);
        $this->accounting->postBill($bill);
    }

    // -------------------------------------------------------------------------
    // Payments
    // -------------------------------------------------------------------------

    public function test_full_payment_settles_bill(): void
    {
        $bill = $this->createBill(total: 110);
        $this->accounting->postBill($bill);

        $payment = $this->accounting->recordBillPayment($bill->fresh(), $this->paymentData(110));

        $fresh = $bill->fresh();
        $this->assertEquals('settled', $fresh->status->value);
        $this->assertEquals('110.00', $fresh->paid_amount);
        $this->assertNotNull($payment->journal_entry_id);
    }

    public function test_partial_payment_sets_partially_settled_status(): void
    {
        $bill = $this->createBill(total: 110);
        $this->accounting->postBill($bill);

        $this->accounting->recordBillPayment($bill->fresh(), $this->paymentData(50));

        $fresh = $bill->fresh();
        $this->assertEquals('partially_settled', $fresh->status->value);
        $this->assertEquals('50.00', $fresh->paid_amount);
    }

    public function test_payment_creates_balanced_journal_entry(): void
    {
        $bill = $this->createBill(total: 100);
        $this->accounting->postBill($bill);

        $payment = $this->accounting->recordBillPayment($bill->fresh(), $this->paymentData(100));

        $entry = $payment->journalEntry;
        $debits = $entry->lines->where('type', 'debit')->sum('amount');
        $credits = $entry->lines->where('type', 'credit')->sum('amount');

        $this->assertEquals($debits, $credits);
        $this->assertEquals(100.0, $debits);
    }

    public function test_overpayment_throws_exception(): void
    {
        $bill = $this->createBill(total: 100);
        $this->accounting->postBill($bill);

        $this->expectException(OverpaymentException::class);
        $this->accounting->recordBillPayment($bill->fresh(), $this->paymentData(200));
    }

    public function test_duplicate_payment_throws_exception(): void
    {
        $bill = $this->createBill(total: 200);
        $this->accounting->postBill($bill);
        $data = $this->paymentData(100);

        $this->accounting->recordBillPayment($bill->fresh(), $data);

        $this->expectException(DuplicatePaymentException::class);
        $this->accounting->recordBillPayment($bill->fresh(), $data);
    }

    public function test_multiple_partial_payments_accumulate_correctly(): void
    {
        $bill = $this->createBill(total: 300);
        $this->accounting->postBill($bill);

        $this->accounting->recordBillPayment($bill->fresh(), $this->paymentData(100, date: '2025-01-01'));
        $this->accounting->recordBillPayment($bill->fresh(), $this->paymentData(100, date: '2025-01-02'));

        $fresh = $bill->fresh();
        $this->assertEquals('partially_settled', $fresh->status->value);
        $this->assertEquals('200.00', $fresh->paid_amount);

        $this->accounting->recordBillPayment($bill->fresh(), $this->paymentData(100, date: '2025-01-03'));
        $this->assertEquals('settled', $bill->fresh()->status->value);
    }

    // -------------------------------------------------------------------------
    // Trial Balance
    // -------------------------------------------------------------------------

    public function test_trial_balance_is_balanced_after_posting_bill(): void
    {
        $bill = $this->createBill(subtotal: 200, tax: 30, total: 230);
        $this->accounting->postBill($bill);

        $tb = $this->accounting->getTrialBalance();

        $this->assertTrue($tb['is_balanced']);
        $this->assertEquals($tb['total_debits'], $tb['total_credits']);
    }

    public function test_trial_balance_is_balanced_after_payment(): void
    {
        $bill = $this->createBill(total: 110);
        $this->accounting->postBill($bill);
        $this->accounting->recordBillPayment($bill->fresh(), $this->paymentData(110));

        $tb = $this->accounting->getTrialBalance();

        $this->assertTrue($tb['is_balanced']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function seedMinimalAccounts(): void
    {
        $accounts = [
            ['code' => '1000', 'name' => 'Cash',              'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '2000', 'name' => 'Accounts Payable',  'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2300', 'name' => 'Sales Tax Payable', 'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '5000', 'name' => 'COGS',              'type' => 'expense',   'subtype' => 'operating_expense'],
        ];

        foreach ($accounts as $data) {
            Account::factory()->create($data);
        }
    }

    private function createBill(float $subtotal = 100, float $tax = 10, float $total = 110): Bill
    {
        $vendor = Vendor::factory()->create();

        return Bill::factory()->create([
            'vendor_id'       => $vendor->id,
            'bill_date'       => now()->toDateString(),
            'subtotal'        => $subtotal,
            'tax_amount'      => $tax,
            'discount_amount' => 0,
            'total'           => $total,
            'currency'        => 'BDT',
            'status'          => 'draft',
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
