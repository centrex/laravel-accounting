<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Exceptions\{AmountToleranceExceededException, StatementLineAlreadyMatchedException, StatementLinePolarityMismatchException};
use Centrex\Accounting\Models\{Account, BankReconciliation, BankStatementLine, JournalEntry};
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class BankReconciliationMatchingTest extends TestCase
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

    private function postedGlLine(string $type, float $amount): \Centrex\Accounting\Models\JournalEntryLine
    {
        $otherType = $type === 'debit' ? 'credit' : 'debit';

        $entry = $this->accounting->createJournalEntry([
            'date'  => now()->toDateString(),
            'lines' => [
                ['account_id' => $this->bankAccount->id, 'type' => $type, 'amount' => $amount],
                ['account_id' => $this->revenueAccount->id, 'type' => $otherType, 'amount' => $amount],
            ],
        ]);
        $entry->post();

        return $entry->lines()->where('account_id', $this->bankAccount->id)->first();
    }

    private function statementLine(string $type, float $amount): BankStatementLine
    {
        $reconciliation = BankReconciliation::factory()->create(['account_id' => $this->bankAccount->id]);

        return BankStatementLine::factory()->create([
            'bank_reconciliation_id' => $reconciliation->id,
            'type'                   => $type,
            'amount'                 => $amount,
        ]);
    }

    public function test_matching_updates_both_sides(): void
    {
        $glLine = $this->postedGlLine('debit', 100);
        $statementLine = $this->statementLine('debit', 100);

        $this->accounting->matchStatementLine($statementLine, $glLine);

        $this->assertEquals($glLine->id, $statementLine->fresh()->matched_journal_entry_line_id);
        $this->assertNotNull($statementLine->fresh()->matched_at);
        $this->assertEquals($statementLine->bank_reconciliation_id, $glLine->fresh()->bank_reconciliation_id);
        $this->assertNotNull($glLine->fresh()->reconciled_at);
    }

    public function test_matching_within_a_widened_tolerance_succeeds(): void
    {
        // Amounts are decimal:2, so the smallest representable difference is 0.01 — widen
        // the tolerance to 0.02 to exercise a genuine within-tolerance-but-nonzero variance
        // without the difference being rounded away (or amplified) by decimal:2 casting.
        config(['accounting.rounding_tolerance' => 0.02]);
        $glLine = $this->postedGlLine('debit', 100);
        $statementLine = $this->statementLine('debit', 100.01);

        $this->accounting->matchStatementLine($statementLine, $glLine);

        $this->assertNotNull($statementLine->fresh()->matched_journal_entry_line_id);
    }

    public function test_matching_over_tolerance_throws(): void
    {
        $glLine = $this->postedGlLine('debit', 100);
        $statementLine = $this->statementLine('debit', 105);

        $this->expectException(AmountToleranceExceededException::class);
        $this->accounting->matchStatementLine($statementLine, $glLine);
    }

    public function test_matching_wrong_polarity_is_rejected(): void
    {
        $glLine = $this->postedGlLine('debit', 100);
        $statementLine = $this->statementLine('credit', 100);

        $this->expectException(StatementLinePolarityMismatchException::class);
        $this->accounting->matchStatementLine($statementLine, $glLine);
    }

    public function test_matching_already_matched_statement_line_throws(): void
    {
        $glLineOne = $this->postedGlLine('debit', 100);
        $glLineTwo = $this->postedGlLine('debit', 100);
        $statementLine = $this->statementLine('debit', 100);

        $this->accounting->matchStatementLine($statementLine, $glLineOne);

        $this->expectException(StatementLineAlreadyMatchedException::class);
        $this->accounting->matchStatementLine($statementLine, $glLineTwo);
    }

    public function test_matching_already_reconciled_gl_line_throws(): void
    {
        $glLine = $this->postedGlLine('debit', 100);
        $statementLineOne = $this->statementLine('debit', 100);
        $statementLineTwo = $this->statementLine('debit', 100);

        $this->accounting->matchStatementLine($statementLineOne, $glLine);

        $this->expectException(StatementLineAlreadyMatchedException::class);
        $this->accounting->matchStatementLine($statementLineTwo, $glLine);
    }

    public function test_unmatch_clears_both_sides(): void
    {
        $glLine = $this->postedGlLine('debit', 100);
        $statementLine = $this->statementLine('debit', 100);
        $this->accounting->matchStatementLine($statementLine, $glLine);

        $this->accounting->unmatchStatementLine($statementLine->fresh());

        $this->assertNull($statementLine->fresh()->matched_journal_entry_line_id);
        $this->assertNull($glLine->fresh()->bank_reconciliation_id);
    }

    public function test_unmatch_after_completion_is_blocked(): void
    {
        $glLine = $this->postedGlLine('debit', 100);
        $statementLine = $this->statementLine('debit', 100);
        $this->accounting->matchStatementLine($statementLine, $glLine);

        $reconciliation = $statementLine->bankReconciliation;
        $reconciliation->update(['opening_balance' => 0, 'statement_ending_balance' => 100]);
        $this->accounting->completeBankReconciliation($reconciliation);

        $this->expectException(\Centrex\Accounting\Exceptions\AccountingException::class);
        $this->accounting->unmatchStatementLine($statementLine->fresh());
    }
}
