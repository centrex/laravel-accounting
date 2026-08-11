<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Models\Account;
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CashBookTest extends TestCase
{
    use RefreshDatabase;

    private Accounting $accounting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounting = app(Accounting::class);
        $this->seedMinimalAccounts();
    }

    public function test_cash_book_computes_opening_balance_receipts_payments_and_running_balance(): void
    {
        $cash = Account::query()->where('code', '1000')->firstOrFail();
        $revenue = Account::query()->where('code', '4000')->firstOrFail();
        $rent = Account::query()->where('code', '6100')->firstOrFail();

        $this->postEntry('2025-01-01', $cash->id, $revenue->id, 100); // opening: receipt before period
        $this->postEntry('2025-01-05', $cash->id, $revenue->id, 50);  // in-period receipt
        $this->postEntry('2025-01-06', $rent->id, $cash->id, 30);     // in-period payment
        $this->createDraftEntry('2025-01-07', $cash->id, $revenue->id, 999); // excluded (not posted)

        $book = $this->accounting->getCashBook($cash->id, '2025-01-02', '2025-01-31');

        $this->assertSame(100.0, $book['opening_balance']);
        $this->assertSame(50.0, $book['total_receipts']);
        $this->assertSame(30.0, $book['total_payments']);
        $this->assertSame(120.0, $book['closing_balance']);
        $this->assertCount(2, $book['entries']);
        $this->assertSame(150.0, $book['entries'][0]['running_balance']);
        $this->assertSame(120.0, $book['entries'][1]['running_balance']);
        $this->assertSame(50.0, $book['entries'][0]['receipt']);
        $this->assertSame(30.0, $book['entries'][1]['payment']);
    }

    public function test_cash_book_without_account_id_combines_every_cash_and_bank_account(): void
    {
        $cash = Account::query()->where('code', '1000')->firstOrFail();
        $bank = Account::query()->create(['code' => '1100', 'name' => 'Bank Account', 'type' => 'asset', 'subtype' => 'current_asset']);
        $revenue = Account::query()->where('code', '4000')->firstOrFail();

        $this->postEntry('2025-01-05', $cash->id, $revenue->id, 40);
        $this->postEntry('2025-01-06', $bank->id, $revenue->id, 60);

        $book = $this->accounting->getCashBook(null, '2025-01-01', '2025-01-31');

        $this->assertCount(2, $book['accounts']);
        $this->assertSame(100.0, $book['total_receipts']);
        $this->assertSame(100.0, $book['closing_balance']);
        $this->assertCount(2, $book['entries']);
        // chronological across accounts, not grouped per-account
        $this->assertSame('2025-01-05', substr((string) $book['entries'][0]['date'], 0, 10));
        $this->assertSame('2025-01-06', substr((string) $book['entries'][1]['date'], 0, 10));
    }

    public function test_cash_book_ignores_non_cash_accounts_when_no_account_id_given(): void
    {
        $cash = Account::query()->where('code', '1000')->firstOrFail();
        $revenue = Account::query()->where('code', '4000')->firstOrFail();

        $this->postEntry('2025-01-05', $cash->id, $revenue->id, 40);

        $book = $this->accounting->getCashBook(null, '2025-01-01', '2025-01-31');

        $this->assertCount(1, $book['accounts']);
        $this->assertSame('1000', $book['accounts'][0]['code']);
    }

    public function test_cash_book_returns_zeroed_structure_when_no_cash_accounts_exist(): void
    {
        Account::query()->where('code', '1000')->delete();

        $book = $this->accounting->getCashBook(null, '2025-01-01', '2025-01-31');

        $this->assertSame([], $book['accounts']);
        $this->assertSame([], $book['entries']);
        $this->assertSame(0.0, $book['opening_balance']);
        $this->assertSame(0.0, $book['closing_balance']);
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
            'date'        => $date,
            'reference'   => 'CB-' . $date . '-' . $amount,
            'description' => 'Cash book test entry',
            'lines'       => [
                ['account_id' => $debitAccountId, 'type' => 'debit', 'amount' => $amount, 'description' => 'Debit line'],
                ['account_id' => $creditAccountId, 'type' => 'credit', 'amount' => $amount, 'description' => 'Credit line'],
            ],
        ]);

        $entry->post();
    }

    private function createDraftEntry(string $date, int $debitAccountId, int $creditAccountId, float $amount): void
    {
        $this->accounting->createJournalEntry([
            'date'        => $date,
            'reference'   => 'DRAFT-' . $date . '-' . $amount,
            'description' => 'Draft cash book test entry',
            'lines'       => [
                ['account_id' => $debitAccountId, 'type' => 'debit', 'amount' => $amount, 'description' => 'Debit line'],
                ['account_id' => $creditAccountId, 'type' => 'credit', 'amount' => $amount, 'description' => 'Credit line'],
            ],
        ]);
    }
}
