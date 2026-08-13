<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Models\{Account, Bill, Customer, Expense, Invoice, Vendor};
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CashFlowForecastTest extends TestCase
{
    use RefreshDatabase;

    private Accounting $accounting;

    private Account $cash;

    private Account $ar;

    private Account $ap;

    private Account $revenue;

    private Account $expense;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounting = app(Accounting::class);
        $this->seedMinimalAccounts();

        $this->cash = Account::query()->where('code', '1000')->firstOrFail();
        $this->ar = Account::query()->where('code', '1200')->firstOrFail();
        $this->ap = Account::query()->where('code', '2000')->firstOrFail();
        $this->revenue = Account::query()->where('code', '4000')->firstOrFail();
        $this->expense = Account::query()->where('code', '6100')->firstOrFail();
    }

    public function test_forecast_starts_from_the_current_posted_cash_balance(): void
    {
        $this->postEntry('2025-01-01', $this->cash->id, $this->revenue->id, 5000);

        $forecast = $this->accounting->getCashFlowForecast('2025-02-01');

        $this->assertSame(5000.0, $forecast['starting_cash_balance']);
    }

    public function test_open_invoice_appears_as_expected_inflow_in_its_due_week(): void
    {
        $invoice = $this->createInvoice(total: 1000, dueDate: '2025-02-10');
        $this->accounting->postInvoice($invoice);

        $forecast = $this->accounting->getCashFlowForecast('2025-02-01', forecastWeeks: 4);

        // 2025-02-10 is 9 days after 2025-02-01 -> week index 1 ("Week of Feb 08")
        $this->assertSame(1000.0, $forecast['buckets'][1]['expected_inflows']);
        $this->assertSame(0.0, $forecast['buckets'][0]['expected_inflows']);
        $this->assertCount(1, $forecast['ar_schedule']);
        $this->assertSame($invoice->invoice_number, $forecast['ar_schedule'][0]['invoice_number']);
        $this->assertSame(1000.0, $forecast['ar_schedule'][0]['amount']);
        $this->assertSame(0.0, $forecast['overdue']['ar']);
    }

    public function test_overdue_invoice_is_pulled_into_this_week_and_flagged_separately(): void
    {
        $invoice = $this->createInvoice(total: 750, dueDate: '2025-01-10');
        $this->accounting->postInvoice($invoice);

        $forecast = $this->accounting->getCashFlowForecast('2025-02-01', forecastWeeks: 4);

        $this->assertSame(750.0, $forecast['buckets'][0]['expected_inflows']);
        $this->assertSame(750.0, $forecast['overdue']['ar']);
        $this->assertSame('overdue', $forecast['ar_schedule'][0]['bucket']);
    }

    public function test_approved_credit_expense_appears_as_expected_outflow_in_its_due_week(): void
    {
        Expense::create([
            'account_id'     => $this->expense->id,
            'expense_date'   => '2025-01-25',
            'due_date'       => '2025-02-12',
            'subtotal'       => 600,
            'tax_amount'     => 0,
            'total'          => 600,
            'paid_amount'    => 0,
            'currency'       => 'BDT',
            'status'         => 'approved',
            'payment_method' => 'credit',
            'vendor_name'    => 'Office Supplies Co',
        ]);

        $forecast = $this->accounting->getCashFlowForecast('2025-02-01', forecastWeeks: 4);

        // 2025-02-12 is 11 days after 2025-02-01 -> week index 1
        $this->assertSame(600.0, $forecast['buckets'][1]['expected_outflows']);
        $this->assertCount(1, $forecast['expense_schedule']);
        $this->assertSame('Office Supplies Co', $forecast['expense_schedule'][0]['vendor']);
        $this->assertSame(0.0, $forecast['overdue']['expenses']);
    }

    public function test_draft_or_cash_expenses_are_excluded_from_the_forecast(): void
    {
        Expense::create([
            'account_id'     => $this->expense->id,
            'expense_date'   => '2025-01-25',
            'due_date'       => '2025-02-12',
            'subtotal'       => 200,
            'tax_amount'     => 0,
            'total'          => 200,
            'paid_amount'    => 0,
            'currency'       => 'BDT',
            'status'         => 'draft',
            'payment_method' => 'credit',
        ]);

        Expense::create([
            'account_id'     => $this->expense->id,
            'expense_date'   => '2025-01-25',
            'due_date'       => '2025-02-12',
            'subtotal'       => 300,
            'tax_amount'     => 0,
            'total'          => 300,
            'paid_amount'    => 300,
            'currency'       => 'BDT',
            'status'         => 'paid',
            'payment_method' => 'cash',
        ]);

        $forecast = $this->accounting->getCashFlowForecast('2025-02-01', forecastWeeks: 4);

        $this->assertCount(0, $forecast['expense_schedule']);
    }

    public function test_overdue_credit_expense_is_flagged_separately(): void
    {
        Expense::create([
            'account_id'     => $this->expense->id,
            'expense_date'   => '2025-01-05',
            'due_date'       => '2025-01-10',
            'subtotal'       => 450,
            'tax_amount'     => 0,
            'total'          => 450,
            'paid_amount'    => 0,
            'currency'       => 'BDT',
            'status'         => 'approved',
            'payment_method' => 'credit',
        ]);

        $forecast = $this->accounting->getCashFlowForecast('2025-02-01', forecastWeeks: 4);

        $this->assertSame(450.0, $forecast['buckets'][0]['expected_outflows']);
        $this->assertSame(450.0, $forecast['overdue']['expenses']);
        $this->assertSame('overdue', $forecast['expense_schedule'][0]['bucket']);
    }

    public function test_open_bill_appears_as_expected_outflow_in_its_due_week(): void
    {
        $bill = $this->createBill(total: 400, dueDate: '2025-02-15');
        $this->accounting->postBill($bill);

        $forecast = $this->accounting->getCashFlowForecast('2025-02-01', forecastWeeks: 4);

        // 2025-02-15 is 14 days after 2025-02-01 -> week index 2
        $this->assertSame(400.0, $forecast['buckets'][2]['expected_outflows']);
        $this->assertCount(1, $forecast['ap_schedule']);
        $this->assertSame($bill->bill_number, $forecast['ap_schedule'][0]['bill_number']);
    }

    public function test_fully_paid_invoice_is_excluded_from_the_schedule(): void
    {
        $invoice = $this->createInvoice(total: 200, dueDate: '2025-02-10');
        $this->accounting->postInvoice($invoice);
        $this->accounting->recordInvoicePayment($invoice->fresh(), [
            'amount' => 200,
            'date'   => '2025-01-15',
            'method' => 'cash',
        ]);

        $forecast = $this->accounting->getCashFlowForecast('2025-02-01', forecastWeeks: 4);

        $this->assertCount(0, $forecast['ar_schedule']);
        $this->assertSame(0.0, $forecast['buckets'][0]['expected_inflows'] + $forecast['buckets'][1]['expected_inflows']);
    }

    public function test_invoice_due_beyond_the_horizon_is_excluded_from_buckets_but_reported_separately(): void
    {
        $invoice = $this->createInvoice(total: 300, dueDate: '2025-06-01');
        $this->accounting->postInvoice($invoice);

        $forecast = $this->accounting->getCashFlowForecast('2025-02-01', forecastWeeks: 4);

        $this->assertSame(300.0, $forecast['beyond_horizon']['ar']);
        $this->assertSame('beyond_horizon', $forecast['ar_schedule'][0]['bucket']);

        foreach ($forecast['buckets'] as $bucket) {
            $this->assertSame(0.0, $bucket['expected_inflows']);
        }
    }

    public function test_run_rate_excludes_invoice_and_bill_payments_but_includes_other_cash_movements(): void
    {
        // Direct cash expense (payroll-like) — not tied to any Invoice/Bill —
        // should feed the run-rate baseline.
        $this->postEntry('2025-01-10', $this->expense->id, $this->cash->id, 700);

        // An invoice paid within the lookback window: its cash receipt must NOT
        // also inflate the "other" run-rate, since it's already forecast explicitly
        // from open AR due dates elsewhere.
        $invoice = $this->createInvoice(total: 500, dueDate: '2025-01-05');
        $this->accounting->postInvoice($invoice);
        $this->accounting->recordInvoicePayment($invoice->fresh(), [
            'amount' => 500,
            'date'   => '2025-01-12',
            'method' => 'cash',
        ]);

        $forecast = $this->accounting->getCashFlowForecast('2025-02-01', forecastWeeks: 1, lookbackDays: 31);

        // net cash movement in lookback = +500 (invoice payment) - 700 (expense) = -200
        // minus the invoice payment itself (+500) = -700 other net cash flow over 31 days
        $this->assertEqualsWithDelta(-700 / 31, $forecast['run_rate']['daily'], 0.01);
        $this->assertEqualsWithDelta((-700 / 31) * 7, $forecast['buckets'][0]['baseline_other'], 0.02);
    }

    public function test_projected_balance_accumulates_starting_cash_inflows_outflows_and_run_rate(): void
    {
        $this->postEntry('2025-01-01', $this->cash->id, $this->revenue->id, 1000);

        $invoice = $this->createInvoice(total: 300, dueDate: '2025-02-03');
        $this->accounting->postInvoice($invoice);

        $bill = $this->createBill(total: 100, dueDate: '2025-02-03');
        $this->accounting->postBill($bill);

        $forecast = $this->accounting->getCashFlowForecast('2025-02-01', forecastWeeks: 1, lookbackDays: 30);

        $expectedNet = round($forecast['buckets'][0]['expected_inflows'] - $forecast['buckets'][0]['expected_outflows'] + $forecast['buckets'][0]['baseline_other'], 2);

        $this->assertSame(300.0, $forecast['buckets'][0]['expected_inflows']);
        $this->assertSame(100.0, $forecast['buckets'][0]['expected_outflows']);
        $this->assertEqualsWithDelta(1000 + $expectedNet, $forecast['ending_projected_balance'], 0.01);
        $this->assertEqualsWithDelta($forecast['ending_projected_balance'], $forecast['buckets'][0]['projected_balance'], 0.01);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function seedMinimalAccounts(): void
    {
        $accounts = [
            ['code' => '1000', 'name' => 'Cash',                'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '1200', 'name' => 'Accounts Receivable', 'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '1300', 'name' => 'Inventory Asset',     'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '2000', 'name' => 'Accounts Payable',    'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2300', 'name' => 'Sales Tax Payable',   'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '4000', 'name' => 'Sales Revenue',       'type' => 'revenue',   'subtype' => 'operating_revenue'],
            ['code' => '6100', 'name' => 'Payroll Expense',     'type' => 'expense',   'subtype' => 'operating_expense'],
        ];

        foreach ($accounts as $data) {
            Account::factory()->create($data);
        }
    }

    private function postEntry(string $date, int $debitAccountId, int $creditAccountId, float $amount): void
    {
        $entry = $this->accounting->createJournalEntry([
            'date'  => $date,
            'lines' => [
                ['account_id' => $debitAccountId,  'type' => 'debit',  'amount' => $amount],
                ['account_id' => $creditAccountId, 'type' => 'credit', 'amount' => $amount],
            ],
        ]);
        $entry->post();
    }

    private function createInvoice(float $total = 100, string $dueDate = '2025-02-01'): Invoice
    {
        $customer = Customer::factory()->create();

        return Invoice::factory()->create([
            'customer_id'     => $customer->id,
            'invoice_date'    => now()->toDateString(),
            'due_date'        => $dueDate,
            'subtotal'        => $total,
            'tax_amount'      => 0,
            'discount_amount' => 0,
            'total'           => $total,
            'currency'        => 'BDT',
            'status'          => 'draft',
        ]);
    }

    private function createBill(float $total = 100, string $dueDate = '2025-02-01'): Bill
    {
        $vendor = Vendor::factory()->create();

        return Bill::factory()->create([
            'vendor_id'       => $vendor->id,
            'bill_date'       => now()->toDateString(),
            'due_date'        => $dueDate,
            'subtotal'        => $total,
            'tax_amount'      => 0,
            'discount_amount' => 0,
            'total'           => $total,
            'currency'        => 'BDT',
            'status'          => 'draft',
        ]);
    }
}
