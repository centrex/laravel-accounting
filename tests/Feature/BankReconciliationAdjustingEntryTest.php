<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Models\{Account, BankReconciliation, BankStatementLine};
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class BankReconciliationAdjustingEntryTest extends TestCase
{
    use RefreshDatabase;

    private Accounting $accounting;

    private Account $bankAccount;

    private Account $bankFeesExpense;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounting = app(Accounting::class);
        $this->bankAccount = Account::factory()->create(['code' => '1100', 'type' => 'asset', 'subtype' => 'checking_account']);
        $this->bankFeesExpense = Account::factory()->create(['code' => '6800', 'type' => 'expense', 'subtype' => 'bank_fees_expense']);
    }

    public function test_adjusting_entry_creates_balanced_journal_and_auto_matches_statement_line(): void
    {
        $reconciliation = BankReconciliation::factory()->create(['account_id' => $this->bankAccount->id]);
        $statementLine = BankStatementLine::factory()->create([
            'bank_reconciliation_id' => $reconciliation->id,
            'type'                   => 'credit',
            'amount'                 => 25,
            'description'            => 'Monthly service charge',
        ]);

        $entry = $this->accounting->createAdjustingJournalEntryForStatementLine($statementLine, [
            'offset_account_id' => $this->bankFeesExpense->id,
        ]);

        $debits = $entry->lines->where('type', 'debit')->sum('amount');
        $credits = $entry->lines->where('type', 'credit')->sum('amount');
        $this->assertEquals($debits, $credits);
        $this->assertEquals(25.0, $debits);

        $this->assertNotNull($statementLine->fresh()->matched_journal_entry_line_id);
        $this->assertNotNull($statementLine->fresh()->matched_at);

        $bankLine = $entry->lines()->where('account_id', $this->bankAccount->id)->first();
        $this->assertEquals('credit', $bankLine->type);
        $this->assertEquals($statementLine->fresh()->matched_journal_entry_line_id, $bankLine->id);
    }
}
