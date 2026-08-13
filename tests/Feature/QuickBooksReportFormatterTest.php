<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Enums\AccountSubtype;
use Centrex\Accounting\Models\Account;
use Centrex\Accounting\QuickBooks\QuickBooksReportFormatter;
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class QuickBooksReportFormatterTest extends TestCase
{
    use RefreshDatabase;

    private QuickBooksReportFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = app(QuickBooksReportFormatter::class);
    }

    public function test_profit_and_loss_splits_revenue_and_expenses_into_qbo_sections(): void
    {
        $revenue = Account::factory()->create(['type' => 'revenue', 'subtype' => AccountSubtype::OPERATING_REVENUE->value]);
        $cogs = Account::factory()->create(['type' => 'expense', 'subtype' => AccountSubtype::COST_OF_GOODS_SOLD->value]);
        $opex = Account::factory()->create(['type' => 'expense', 'subtype' => AccountSubtype::OFFICE_EXPENSE->value]);

        $result = $this->formatter->profitAndLoss([
            'revenue'  => ['accounts' => [['account' => $revenue, 'balance' => 1000.0]]],
            'expenses' => [
                'accounts' => [
                    ['account' => $cogs, 'balance' => 400.0],
                    ['account' => $opex, 'balance' => 200.0],
                ],
            ],
        ]);

        $this->assertSame(1000.0, $result['income']['total']);
        $this->assertSame(400.0, $result['cost_of_goods_sold']['total']);
        $this->assertSame(600.0, $result['gross_profit']);
        $this->assertSame(200.0, $result['expenses']['total']);
        $this->assertSame(400.0, $result['net_operating_income']);
        $this->assertSame(400.0, $result['net_income']);
    }

    public function test_balance_sheet_groups_accounts_into_qbo_current_asset_sections(): void
    {
        $bank = Account::factory()->create(['type' => 'asset', 'subtype' => AccountSubtype::CASH->value]);
        $ar = Account::factory()->create(['type' => 'asset', 'subtype' => AccountSubtype::ACCOUNTS_RECEIVABLE->value]);
        $ap = Account::factory()->create(['type' => 'liability', 'subtype' => AccountSubtype::ACCOUNTS_PAYABLE->value]);

        $result = $this->formatter->balanceSheet([
            'assets' => ['accounts' => [
                ['account' => $bank, 'balance' => 5000.0],
                ['account' => $ar, 'balance' => 1500.0],
            ]],
            'liabilities' => ['accounts' => [
                ['account' => $ap, 'balance' => 800.0],
            ]],
            'equity'      => ['accounts' => [], 'net_income' => 200.0],
            'is_balanced' => true,
        ]);

        $this->assertSame(5000.0, $result['assets']['current_assets']['bank_accounts']['total']);
        $this->assertSame(1500.0, $result['assets']['current_assets']['accounts_receivable']['total']);
        $this->assertSame(6500.0, $result['assets']['total']);
        $this->assertSame(800.0, $result['liabilities_and_equity']['liabilities']['current_liabilities']['accounts_payable']['total']);
        $this->assertSame(200.0, $result['liabilities_and_equity']['equity']['net_income']);
    }

    public function test_cash_flow_reformats_into_qbo_statement_of_cash_flows_structure(): void
    {
        $result = $this->formatter->cashFlow([
            'operating_activities' => 500.0,
            'investing_activities' => -100.0,
            'financing_activities' => 50.0,
            'net_change'           => 450.0,
        ]);

        $this->assertSame(500.0, $result['operating_activities']['net_cash']);
        $this->assertSame(-100.0, $result['investing_activities']['net_cash']);
        $this->assertSame(450.0, $result['net_change_in_cash']);
    }

    public function test_ar_aging_reformats_rows_into_qbo_aged_receivables_structure(): void
    {
        $result = $this->formatter->arAging([
            'as_of_date' => '2025-06-01',
            'rows'       => [
                ['name' => 'Acme Corp', 'current' => 100.0, '1_30' => 50.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0, 'total' => 150.0],
            ],
        ]);

        $this->assertSame('AgedReceivables', $result['report_name']);
        $this->assertSame('Acme Corp', $result['rows'][0]['Customer']);
        $this->assertSame(150.0, $result['totals']['total']);
    }
}
