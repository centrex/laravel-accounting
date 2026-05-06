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

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounting = app(Accounting::class);
    }

    public function test_cash_flow_statement_returns_zeroes_when_cash_account_is_missing(): void
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
        $cash = Account::query()->create([
            'code' => '1000',
            'name' => 'Cash',
            'type' => 'asset',
            'subtype' => 'cash',
        ]);
        $revenue = Account::query()->create([
            'code' => '4000',
            'name' => 'Sales Revenue',
            'type' => 'revenue',
            'subtype' => 'operating_revenue',
        ]);

        $entry = $this->accounting->createJournalEntry([
            'date' => '2025-01-15',
            'reference' => 'CF-001',
            'description' => 'Cash flow test entry',
            'lines' => [
                ['account_id' => $cash->id, 'type' => 'debit', 'amount' => 125, 'description' => 'Cash received'],
                ['account_id' => $revenue->id, 'type' => 'credit', 'amount' => 125, 'description' => 'Revenue'],
            ],
        ]);

        $entry->post();

        $statement = $this->accounting->getCashFlowStatement('2025-01-01', '2025-01-31');

        $this->assertSame(125.0, $statement['operating_activities']);
        $this->assertSame(0.0, $statement['investing_activities']);
        $this->assertSame(0.0, $statement['financing_activities']);
        $this->assertSame(125.0, $statement['net_change']);
    }
}
