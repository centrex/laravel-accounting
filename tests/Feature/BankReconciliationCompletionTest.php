<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Enums\BankReconciliationStatus;
use Centrex\Accounting\Exceptions\{AccountingException, ReconciliationBalanceMismatchException};
use Centrex\Accounting\Models\{Account, BankReconciliation, BankStatementLine};
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class BankReconciliationCompletionTest extends TestCase
{
    use RefreshDatabase;

    private Accounting $accounting;

    private Account $bankAccount;

    private Account $revenueAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounting = app(Accounting::class);
        $this->bankAccount = Account::factory()->create(['code' => '1100', 'type' => 'asset', 'subtype' => 'checking_account']);
        $this->revenueAccount = Account::factory()->create(['code' => '4000', 'type' => 'revenue', 'subtype' => 'operating_revenue']);
    }

    private function matchedReconciliation(float $openingBalance, float $endingBalance, float $lineAmount): BankReconciliation
    {
        $entry = $this->accounting->createJournalEntry([
            'date'  => now()->toDateString(),
            'lines' => [
                ['account_id' => $this->bankAccount->id, 'type' => 'debit', 'amount' => $lineAmount],
                ['account_id' => $this->revenueAccount->id, 'type' => 'credit', 'amount' => $lineAmount],
            ],
        ]);
        $entry->post();
        $glLine = $entry->lines()->where('account_id', $this->bankAccount->id)->first();

        $reconciliation = BankReconciliation::factory()->create([
            'account_id'               => $this->bankAccount->id,
            'opening_balance'          => $openingBalance,
            'statement_ending_balance' => $endingBalance,
        ]);
        $statementLine = BankStatementLine::factory()->create([
            'bank_reconciliation_id' => $reconciliation->id,
            'type'                   => 'debit',
            'amount'                 => $lineAmount,
        ]);

        $this->accounting->matchStatementLine($statementLine, $glLine);

        return $reconciliation;
    }

    public function test_complete_with_variance_throws(): void
    {
        // opening 0 + debit 100 = 100, but statement says ending balance is 500 -> mismatch
        $reconciliation = $this->matchedReconciliation(openingBalance: 0, endingBalance: 500, lineAmount: 100);

        $this->expectException(ReconciliationBalanceMismatchException::class);
        $this->accounting->completeBankReconciliation($reconciliation);
    }

    public function test_complete_within_tolerance_marks_completed_and_sets_timestamps(): void
    {
        $reconciliation = $this->matchedReconciliation(openingBalance: 0, endingBalance: 100, lineAmount: 100);

        $this->accounting->completeBankReconciliation($reconciliation);

        $fresh = $reconciliation->fresh();
        $this->assertEquals(BankReconciliationStatus::COMPLETED, $fresh->status);
        $this->assertNotNull($fresh->reconciled_at);
    }

    public function test_complete_blocked_while_unmatched_lines_remain(): void
    {
        $reconciliation = BankReconciliation::factory()->create(['account_id' => $this->bankAccount->id]);
        BankStatementLine::factory()->create(['bank_reconciliation_id' => $reconciliation->id]);

        $this->expectException(AccountingException::class);
        $this->accounting->completeBankReconciliation($reconciliation);
    }
}
