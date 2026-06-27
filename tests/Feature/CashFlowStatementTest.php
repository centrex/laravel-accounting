<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Models\Account;
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CashFlowStatementTest extends TestCase
{
    use RefreshDatabase;

    private Accounting $accounting;

    private Account $cash;

    private Account $ar;

    private Account $revenue;

    private Account $taxPayable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounting = app(Accounting::class);

        $this->cash = Account::query()->create(['code' => '1000', 'name' => 'Cash', 'type' => 'asset', 'subtype' => 'cash']);
        $this->ar = Account::query()->create(['code' => '1200', 'name' => 'Accounts Receivable', 'type' => 'asset', 'subtype' => 'accounts_receivable']);
        $this->revenue = Account::query()->create(['code' => '4000', 'name' => 'Sales Revenue', 'type' => 'revenue', 'subtype' => 'operating_revenue']);
        $this->taxPayable = Account::query()->create(['code' => '2300', 'name' => 'Tax Payable', 'type' => 'liability', 'subtype' => 'tax_account']);
    }

    public function test_cash_flow_statement_returns_zeroes_when_no_entries(): void
    {
        $statement = $this->accounting->getCashFlowStatement('2025-01-01', '2025-01-31');

        $this->assertSame(['start' => '2025-01-01', 'end' => '2025-01-31'], $statement['period']);
        $this->assertSame(0.0, $statement['operating_activities']);
        $this->assertSame(0.0, $statement['investing_activities']);
        $this->assertSame(0.0, $statement['financing_activities']);
        $this->assertSame(0.0, $statement['net_change']);
    }

    public function test_cash_flow_statement_classifies_revenue_counterpart_as_operating_cash_flow(): void
    {
        $entry = $this->accounting->createJournalEntry([
            'date' => '2025-01-15',
            'reference' => 'CF-001',
            'description' => 'Direct cash sale',
            'lines' => [
                ['account_id' => $this->cash->id,    'type' => 'debit',  'amount' => 125],
                ['account_id' => $this->revenue->id, 'type' => 'credit', 'amount' => 125],
            ],
        ]);
        $entry->post();

        $statement = $this->accounting->getCashFlowStatement('2025-01-01', '2025-01-31');

        $this->assertSame(125.0, $statement['operating_activities']);
        $this->assertSame(0.0, $statement['investing_activities']);
        $this->assertSame(0.0, $statement['financing_activities']);
        $this->assertSame(125.0, $statement['net_change']);
        $this->assertSame(125.0, $statement['operating_breakdown']['net_income']);
        $this->assertSame(0.0, $statement['operating_breakdown']['working_capital_adjustments']);
    }

    /**
     * Invoice posted and paid in the SAME period.
     *
     * JE 1 (invoice): DR AR 1000 / CR Revenue 1000
     * JE 2 (payment): DR Cash 1000 / CR AR 1000
     *
     * AR nets to zero → no working-capital adjustment.
     * Revenue = net income = cash received.
     */
    public function test_invoice_posted_and_paid_same_period_reflects_in_operating_activities(): void
    {
        // Post invoice
        $invoiceEntry = $this->accounting->createJournalEntry([
            'date' => '2025-01-05',
            'reference' => 'INV-001',
            'description' => 'Invoice',
            'lines' => [
                ['account_id' => $this->ar->id,      'type' => 'debit',  'amount' => 1000],
                ['account_id' => $this->revenue->id, 'type' => 'credit', 'amount' => 1000],
            ],
        ]);
        $invoiceEntry->post();

        // Receive payment
        $paymentEntry = $this->accounting->createJournalEntry([
            'date' => '2025-01-20',
            'reference' => 'PMT-001',
            'description' => 'Payment received',
            'lines' => [
                ['account_id' => $this->cash->id, 'type' => 'debit',  'amount' => 1000],
                ['account_id' => $this->ar->id,   'type' => 'credit', 'amount' => 1000],
            ],
        ]);
        $paymentEntry->post();

        $statement = $this->accounting->getCashFlowStatement('2025-01-01', '2025-01-31');

        // AR nets to zero — operating = net income = 1000 (cash was actually received)
        $this->assertSame(1000.0, $statement['operating_activities']);
        $this->assertSame(0.0, $statement['investing_activities']);
        $this->assertSame(0.0, $statement['financing_activities']);
        $this->assertSame(1000.0, $statement['net_change']);

        $this->assertSame(1000.0, $statement['operating_breakdown']['net_income']);
        $this->assertSame(0.0, $statement['operating_breakdown']['working_capital_adjustments']);
        $this->assertEmpty($statement['operating_breakdown']['changes_in_working_capital']);
    }

    /**
     * Invoice posted in period A but NOT yet paid — operating must be zero (no cash yet).
     *
     * JE: DR AR 1000 / CR Revenue 1000
     * AR increased → reduces operating by 1000, offsetting the 1000 net income.
     */
    public function test_invoice_posted_but_unpaid_shows_zero_operating_cash_flow(): void
    {
        $invoiceEntry = $this->accounting->createJournalEntry([
            'date' => '2025-01-05',
            'reference' => 'INV-002',
            'description' => 'Invoice (unpaid)',
            'lines' => [
                ['account_id' => $this->ar->id,      'type' => 'debit',  'amount' => 1000],
                ['account_id' => $this->revenue->id, 'type' => 'credit', 'amount' => 1000],
            ],
        ]);
        $invoiceEntry->post();

        $statement = $this->accounting->getCashFlowStatement('2025-01-01', '2025-01-31');

        // Revenue = +1000, but AR increased = −1000 → operating = 0
        $this->assertSame(0.0, $statement['operating_activities']);
        $this->assertSame(0.0, $statement['net_change']);

        $this->assertSame(1000.0, $statement['operating_breakdown']['net_income']);
        $this->assertSame(-1000.0, $statement['operating_breakdown']['working_capital_adjustments']);

        // AR increase shows as a negative working-capital line
        $wcLines = $statement['operating_breakdown']['changes_in_working_capital'];
        $this->assertCount(1, $wcLines);
        $this->assertSame('1200', $wcLines[0]['code']);
        $this->assertSame(-1000.0, $wcLines[0]['amount']);
    }

    /**
     * Payment received in period B for an invoice posted in period A.
     *
     * Period A: DR AR 1000 / CR Revenue 1000  (not in this period's test)
     * Period B: DR Cash 1000 / CR AR 1000
     *
     * Net income in period B = 0. AR decreased by 1000 → +1000 operating adjustment.
     * Operating cash flow = 1000 (the collected cash IS visible here).
     */
    public function test_payment_received_for_prior_period_invoice_shows_as_operating_cash_inflow(): void
    {
        // Simulate prior-period invoice (Jan)
        $invoiceEntry = $this->accounting->createJournalEntry([
            'date' => '2025-01-05',
            'reference' => 'INV-003',
            'description' => 'Prior period invoice',
            'lines' => [
                ['account_id' => $this->ar->id,      'type' => 'debit',  'amount' => 1000],
                ['account_id' => $this->revenue->id, 'type' => 'credit', 'amount' => 1000],
            ],
        ]);
        $invoiceEntry->post();

        // Payment in Feb (period B)
        $paymentEntry = $this->accounting->createJournalEntry([
            'date' => '2025-02-10',
            'reference' => 'PMT-003',
            'description' => 'Payment for Jan invoice',
            'lines' => [
                ['account_id' => $this->cash->id, 'type' => 'debit',  'amount' => 1000],
                ['account_id' => $this->ar->id,   'type' => 'credit', 'amount' => 1000],
            ],
        ]);
        $paymentEntry->post();

        $statementFeb = $this->accounting->getCashFlowStatement('2025-02-01', '2025-02-28');

        // No revenue in Feb, but AR dropped by 1000 → +1000 operating
        $this->assertSame(1000.0, $statementFeb['operating_activities']);
        $this->assertSame(0.0, $statementFeb['investing_activities']);
        $this->assertSame(0.0, $statementFeb['financing_activities']);
        $this->assertSame(1000.0, $statementFeb['net_change']);

        $this->assertSame(0.0, $statementFeb['operating_breakdown']['net_income']);
        $this->assertSame(1000.0, $statementFeb['operating_breakdown']['working_capital_adjustments']);

        $wcLines = $statementFeb['operating_breakdown']['changes_in_working_capital'];
        $this->assertCount(1, $wcLines);
        $this->assertSame('1200', $wcLines[0]['code']);
        $this->assertSame(1000.0, $wcLines[0]['amount']); // AR decreased = positive cash inflow
    }

    /**
     * Invoice with tax: DR AR 1150 / CR Revenue 1000 / CR Tax Payable 150.
     * Payment:         DR Cash 1150 / CR AR 1150.
     *
     * Same period: AR nets to zero. Net income = 1000 (revenue only).
     * Tax Payable increased by 150 → operating adj +150.
     * Total operating = 1150 = actual cash received.
     */
    public function test_invoice_with_tax_full_cash_amount_reflected_in_operating_activities(): void
    {
        // Post invoice with tax
        $invoiceEntry = $this->accounting->createJournalEntry([
            'date' => '2025-01-05',
            'reference' => 'INV-004',
            'description' => 'Invoice with tax',
            'lines' => [
                ['account_id' => $this->ar->id,         'type' => 'debit',  'amount' => 1150],
                ['account_id' => $this->revenue->id,    'type' => 'credit', 'amount' => 1000],
                ['account_id' => $this->taxPayable->id, 'type' => 'credit', 'amount' => 150],
            ],
        ]);
        $invoiceEntry->post();

        // Receive full payment (including tax collected)
        $paymentEntry = $this->accounting->createJournalEntry([
            'date' => '2025-01-20',
            'reference' => 'PMT-004',
            'description' => 'Full payment',
            'lines' => [
                ['account_id' => $this->cash->id, 'type' => 'debit',  'amount' => 1150],
                ['account_id' => $this->ar->id,   'type' => 'credit', 'amount' => 1150],
            ],
        ]);
        $paymentEntry->post();

        $statement = $this->accounting->getCashFlowStatement('2025-01-01', '2025-01-31');

        // Net income = 1000, Tax Payable +150 → total = 1150 (full cash received)
        $this->assertSame(1150.0, $statement['operating_activities']);
        $this->assertSame(1150.0, $statement['net_change']);

        $this->assertSame(1000.0, $statement['operating_breakdown']['net_income']);
        $this->assertSame(150.0, $statement['operating_breakdown']['working_capital_adjustments']);

        $wcLines = $statement['operating_breakdown']['changes_in_working_capital'];
        $this->assertCount(1, $wcLines);
        $this->assertSame('2300', $wcLines[0]['code']);
        $this->assertSame(150.0, $wcLines[0]['amount']);
    }
}
