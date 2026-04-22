<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Models\Account;
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GeneralLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Accounting $accounting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounting = app(Accounting::class);
        $this->seedMinimalAccounts();
    }

    public function test_general_ledger_returns_opening_and_running_balance_for_debit_normal_account(): void
    {
        $cash = Account::query()->where('code', '1000')->firstOrFail();
        $revenue = Account::query()->where('code', '4000')->firstOrFail();

        $this->postEntry('2025-01-01', $cash->id, $revenue->id, 100);
        $this->postEntry('2025-01-03', $cash->id, $revenue->id, 50);
        $this->createDraftEntry('2025-01-04', $cash->id, $revenue->id, 25);

        $ledger = $this->accounting->getGeneralLedger($cash->id, '2025-01-02', '2025-01-31');

        $this->assertCount(1, $ledger['accounts']);
        $section = $ledger['accounts'][0];

        $this->assertSame(100.0, $section['opening_balance']);
        $this->assertSame(150.0, $section['closing_balance']);
        $this->assertSame(50.0, $section['period_debits']);
        $this->assertSame(0.0, $section['period_credits']);
        $this->assertCount(1, $section['entries']);
        $this->assertSame(150.0, $section['entries'][0]['running_balance']);
    }

    public function test_general_ledger_uses_credit_normal_running_balance_for_revenue_account(): void
    {
        $cash = Account::query()->where('code', '1000')->firstOrFail();
        $revenue = Account::query()->where('code', '4000')->firstOrFail();

        $this->postEntry('2025-01-01', $cash->id, $revenue->id, 80);
        $this->postEntry('2025-01-02', $cash->id, $revenue->id, 20);

        $ledger = $this->accounting->getGeneralLedger($revenue->id, '2025-01-02', '2025-01-31');
        $section = $ledger['accounts'][0];

        $this->assertSame(80.0, $section['opening_balance']);
        $this->assertSame(100.0, $section['closing_balance']);
        $this->assertSame(0.0, $section['period_debits']);
        $this->assertSame(20.0, $section['period_credits']);
        $this->assertSame(100.0, $section['entries'][0]['running_balance']);
    }

    public function test_general_ledger_without_account_filter_returns_only_accounts_with_activity(): void
    {
        $cash = Account::query()->where('code', '1000')->firstOrFail();
        $revenue = Account::query()->where('code', '4000')->firstOrFail();

        $this->postEntry('2025-01-01', $cash->id, $revenue->id, 100);

        $ledger = $this->accounting->getGeneralLedger(null, '2025-01-01', '2025-01-31');

        $this->assertCount(2, $ledger['accounts']);
        $this->assertSame(['1000', '4000'], array_map(
            fn (array $row): string => $row['account']->code,
            $ledger['accounts']
        ));
    }

    private function seedMinimalAccounts(): void
    {
        foreach ([
            ['code' => '1000', 'name' => 'Cash', 'type' => 'asset', 'subtype' => 'current_asset'],
            ['code' => '4000', 'name' => 'Sales Revenue', 'type' => 'revenue', 'subtype' => 'operating_revenue'],
            ['code' => '6100', 'name' => 'Rent Expense', 'type' => 'expense', 'subtype' => 'rent_expense'],
        ] as $data) {
            Account::query()->create($data);
        }
    }

    private function postEntry(string $date, int $debitAccountId, int $creditAccountId, float $amount): void
    {
        $entry = $this->accounting->createJournalEntry([
            'date' => $date,
            'reference' => 'GL-'.$date.'-'.$amount,
            'description' => 'General ledger test entry',
            'lines' => [
                ['account_id' => $debitAccountId, 'type' => 'debit', 'amount' => $amount, 'description' => 'Debit line'],
                ['account_id' => $creditAccountId, 'type' => 'credit', 'amount' => $amount, 'description' => 'Credit line'],
            ],
        ]);

        $entry->post();
    }

    private function createDraftEntry(string $date, int $debitAccountId, int $creditAccountId, float $amount): void
    {
        $this->accounting->createJournalEntry([
            'date' => $date,
            'reference' => 'DRAFT-'.$date.'-'.$amount,
            'description' => 'Draft general ledger test entry',
            'lines' => [
                ['account_id' => $debitAccountId, 'type' => 'debit', 'amount' => $amount, 'description' => 'Debit line'],
                ['account_id' => $creditAccountId, 'type' => 'credit', 'amount' => $amount, 'description' => 'Credit line'],
            ],
        ]);
    }
}
