<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Exceptions\{DuplicatePaymentException, InvalidStatusTransitionException, OverpaymentException};
use Centrex\Accounting\Models\{Account, Expense};
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PostExpenseTest extends TestCase
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

    public function test_post_expense_creates_journal_entry_and_sets_issued_status(): void
    {
        $expense = $this->createExpense(subtotal: 100, tax: 10, total: 110);

        $entry = $this->accounting->postExpense($expense);

        $this->assertNotNull($entry);
        $this->assertEquals('issued', $expense->fresh()->status->value);
        $this->assertDatabaseHas('acct_journal_entries', ['id' => $entry->id, 'status' => 'posted']);
    }

    public function test_post_expense_creates_balanced_journal_entry(): void
    {
        $expense = $this->createExpense(subtotal: 200, tax: 30, total: 230);

        $entry = $this->accounting->postExpense($expense);

        $debits = $entry->lines->where('type', 'debit')->sum('amount');
        $credits = $entry->lines->where('type', 'credit')->sum('amount');

        $this->assertEquals($debits, $credits);
        $this->assertEquals(230.0, $debits);
    }

    public function test_post_expense_links_journal_entry_id(): void
    {
        $expense = $this->createExpense();
        $entry = $this->accounting->postExpense($expense);

        $this->assertEquals($entry->id, $expense->fresh()->journal_entry_id);
    }

    public function test_posting_already_posted_expense_throws(): void
    {
        $expense = $this->createExpense();
        $this->accounting->postExpense($expense);

        $this->expectException(InvalidStatusTransitionException::class);
        $this->accounting->postExpense($expense->fresh());
    }

    public function test_posting_settled_expense_throws(): void
    {
        $expense = $this->createExpense(subtotal: 100, tax: 0, total: 100);
        $this->accounting->postExpense($expense);
        $this->accounting->recordExpensePayment($expense->fresh(), $this->paymentData(100));

        $this->expectException(InvalidStatusTransitionException::class);
        $this->accounting->postExpense($expense->fresh());
    }

    public function test_post_expense_without_tax_creates_two_lines(): void
    {
        $expense = $this->createExpense(subtotal: 500, tax: 0, total: 500);

        $entry = $this->accounting->postExpense($expense);

        // Only debit (expense) and credit (cash) — no tax line
        $this->assertCount(2, $entry->lines);
    }

    public function test_post_expense_with_tax_creates_three_lines(): void
    {
        $expense = $this->createExpense(subtotal: 500, tax: 75, total: 575);

        $entry = $this->accounting->postExpense($expense);

        // Debit expense, debit tax, credit cash
        $this->assertCount(3, $entry->lines);
    }

    // -------------------------------------------------------------------------
    // Payments
    // -------------------------------------------------------------------------

    public function test_full_payment_settles_expense(): void
    {
        $expense = $this->createExpense(total: 110);
        $this->accounting->postExpense($expense);

        $payment = $this->accounting->recordExpensePayment($expense->fresh(), $this->paymentData(110));

        $fresh = $expense->fresh();
        $this->assertEquals('settled', $fresh->status->value);
        $this->assertEquals('110.00', $fresh->paid_amount);
        $this->assertNotNull($payment->journal_entry_id);
    }

    public function test_partial_payment_sets_partially_settled_status(): void
    {
        $expense = $this->createExpense(total: 110);
        $this->accounting->postExpense($expense);

        $this->accounting->recordExpensePayment($expense->fresh(), $this->paymentData(50));

        $fresh = $expense->fresh();
        $this->assertEquals('partially_settled', $fresh->status->value);
        $this->assertEquals('50.00', $fresh->paid_amount);
    }

    public function test_payment_creates_balanced_journal_entry(): void
    {
        $expense = $this->createExpense(total: 100);
        $this->accounting->postExpense($expense);

        $payment = $this->accounting->recordExpensePayment($expense->fresh(), $this->paymentData(100));

        $entry = $payment->journalEntry;
        $debits = $entry->lines->where('type', 'debit')->sum('amount');
        $credits = $entry->lines->where('type', 'credit')->sum('amount');

        $this->assertEquals($debits, $credits);
        $this->assertEquals(100.0, $debits);
    }

    public function test_overpayment_throws_exception(): void
    {
        $expense = $this->createExpense(total: 100);
        $this->accounting->postExpense($expense);

        $this->expectException(OverpaymentException::class);
        $this->accounting->recordExpensePayment($expense->fresh(), $this->paymentData(200));
    }

    public function test_duplicate_payment_throws_exception(): void
    {
        $expense = $this->createExpense(total: 200);
        $this->accounting->postExpense($expense);
        $data = $this->paymentData(100);

        $this->accounting->recordExpensePayment($expense->fresh(), $data);

        $this->expectException(DuplicatePaymentException::class);
        $this->accounting->recordExpensePayment($expense->fresh(), $data);
    }

    public function test_multiple_partial_payments_accumulate_correctly(): void
    {
        $expense = $this->createExpense(total: 300);
        $this->accounting->postExpense($expense);

        $this->accounting->recordExpensePayment($expense->fresh(), $this->paymentData(100, date: '2025-01-01'));
        $this->accounting->recordExpensePayment($expense->fresh(), $this->paymentData(100, date: '2025-01-02'));

        $fresh = $expense->fresh();
        $this->assertEquals('partially_settled', $fresh->status->value);
        $this->assertEquals('200.00', $fresh->paid_amount);

        $this->accounting->recordExpensePayment($expense->fresh(), $this->paymentData(100, date: '2025-01-03'));
        $this->assertEquals('settled', $expense->fresh()->status->value);
    }

    // -------------------------------------------------------------------------
    // Trial Balance
    // -------------------------------------------------------------------------

    public function test_trial_balance_is_balanced_after_posting_expense(): void
    {
        $expense = $this->createExpense(subtotal: 200, tax: 30, total: 230);
        $this->accounting->postExpense($expense);

        $tb = $this->accounting->getTrialBalance();

        $this->assertTrue($tb['is_balanced']);
        $this->assertEquals($tb['total_debits'], $tb['total_credits']);
    }

    public function test_trial_balance_is_balanced_after_expense_payment(): void
    {
        $expense = $this->createExpense(total: 110);
        $this->accounting->postExpense($expense);
        $this->accounting->recordExpensePayment($expense->fresh(), $this->paymentData(110));

        $tb = $this->accounting->getTrialBalance();

        $this->assertTrue($tb['is_balanced']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function seedMinimalAccounts(): void
    {
        $accounts = [
            ['code' => '1000', 'name' => 'Cash',              'type' => 'asset',   'subtype' => 'current_asset'],
            ['code' => '2300', 'name' => 'Sales Tax Payable', 'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '5000', 'name' => 'COGS',              'type' => 'expense', 'subtype' => 'operating_expense'],
        ];

        foreach ($accounts as $data) {
            Account::factory()->create($data);
        }
    }

    private function createExpense(float $subtotal = 100, float $tax = 10, float $total = 110): Expense
    {
        return Expense::factory()->create([
            'expense_date'    => now()->toDateString(),
            'subtotal'        => $subtotal,
            'tax_amount'      => $tax,
            'discount_amount' => 0,
            'total'           => $total,
            'currency'        => 'BDT',
            'status'          => 'draft',
            'payment_method'  => 'cash',
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
