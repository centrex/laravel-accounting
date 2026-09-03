<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Models\Account;
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Sales returns/discounts (6130-6134) are contra-revenue (IFRS 15 variable
 * consideration), not operating expenses — they must net against Sales
 * Revenue above Gross Profit, not appear as an expense below it. See
 * AccountSubtype::CONTRA_REVENUE and the accounts reclassified in
 * Accounting::initializeChartOfAccounts()/AccountingSeeder.
 */
class IncomeStatementContraRevenueTest extends TestCase
{
    use RefreshDatabase;

    private Accounting $accounting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounting = app(Accounting::class);
    }

    public function test_income_statement_nets_returns_against_revenue_not_as_an_expense(): void
    {
        $ar = Account::factory()->create(['code' => '1200', 'type' => 'asset', 'subtype' => 'accounts_receivable']);
        $inventory = Account::factory()->create(['code' => '1300', 'type' => 'asset', 'subtype' => 'current_asset']);
        $revenue = Account::factory()->create(['code' => '4000', 'type' => 'revenue', 'subtype' => 'operating_revenue']);
        $cogs = Account::factory()->create(['code' => '5000', 'type' => 'expense', 'subtype' => 'cost_of_goods_sold']);
        $salesReturns = Account::factory()->create(['code' => '6134', 'type' => 'revenue', 'subtype' => 'contra_revenue']);
        $rent = Account::factory()->create(['code' => '6100', 'type' => 'expense', 'subtype' => 'rent_expense']);

        $date = now()->toDateString();

        // Original sale: 1000 revenue, 600 COGS.
        $this->postAndFetch([
            ['account_id' => $ar->id, 'type' => 'debit', 'amount' => 1000],
            ['account_id' => $revenue->id, 'type' => 'credit', 'amount' => 1000],
        ], $date);
        $this->postAndFetch([
            ['account_id' => $cogs->id, 'type' => 'debit', 'amount' => 600],
            ['account_id' => $inventory->id, 'type' => 'credit', 'amount' => 600],
        ], $date);

        // A genuine operating expense, unrelated to the return — should still
        // land below Gross Profit, unlike the return.
        $this->postAndFetch([
            ['account_id' => $rent->id, 'type' => 'debit', 'amount' => 50],
            ['account_id' => $ar->id, 'type' => 'credit', 'amount' => 50],
        ], $date);

        // Customer returns a third of the sale: 300 of revenue, 200 of COGS reversed.
        $this->postAndFetch([
            ['account_id' => $salesReturns->id, 'type' => 'debit', 'amount' => 300],
            ['account_id' => $ar->id, 'type' => 'credit', 'amount' => 300],
        ], $date);
        $this->postAndFetch([
            ['account_id' => $inventory->id, 'type' => 'debit', 'amount' => 200],
            ['account_id' => $cogs->id, 'type' => 'credit', 'amount' => 200],
        ], $date);

        $statement = $this->accounting->getIncomeStatement($date, $date);

        // Net revenue: 1000 gross - 300 returned = 700, not the gross 1000.
        $this->assertEquals(700.0, $statement['revenue']['total']);
        // COGS correctly reversed: 600 - 200 = 400.
        $this->assertEquals(400.0, $statement['cogs']['total']);
        // Gross profit = Net Revenue - COGS = 700 - 400 = 300, NOT
        // Gross Revenue - COGS (1000 - 400 = 600, the old, overstated figure).
        $this->assertEquals(300.0, $statement['gross_profit']);
        // The return never appears as an expense — only the real operating expense does.
        $this->assertEquals(50.0, $statement['expenses']['total']);
        $accountCodesInExpenses = collect($statement['expenses']['accounts'])
            ->map(fn (array $row): string => $row['account']->code)
            ->all();
        $this->assertNotContains('6134', $accountCodesInExpenses);
        // Net income: 300 gross profit - 50 rent = 250. Algebraically identical to what
        // the old type:'expense' classification would have produced (grossRevenue - cogs -
        // otherExpenses - returnAmount = 1000 - 400 - 50 - 300 = 250) — this is a
        // presentation fix, not a bottom-line change.
        $this->assertEquals(250.0, $statement['net_income']);
    }

    /** @param list<array{account_id: int, type: string, amount: float}> $lines */
    private function postAndFetch(array $lines, string $date): void
    {
        $entry = $this->accounting->createJournalEntry([
            'date'  => $date,
            'type'  => 'general',
            'lines' => $lines,
        ]);
        $entry->post();
    }
}
