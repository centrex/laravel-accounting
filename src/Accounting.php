<?php

declare(strict_types = 1);

namespace Centrex\Accounting;

use Centrex\Accounting\Enums\BankReconciliationStatus;
use Centrex\Accounting\Exceptions\{
    AccountingException,
    AmountToleranceExceededException,
    ReconciliationBalanceMismatchException,
    StatementLineAlreadyMatchedException,
    StatementLinePolarityMismatchException
};
use Centrex\Accounting\Models\{
    Account,
    BankReconciliation,
    BankStatementLine,
    Bill,
    Invoice,
    JournalEntry,
    JournalEntryLine
};
use Illuminate\Support\{Collection};
use Illuminate\Support\Facades\DB;

class Accounting
{
    use Concerns\GeneratesFinancialReports;
    use Concerns\HasSharedAccountingHelpers;
    use Concerns\ManagesBills;
    use Concerns\ManagesBudgets;
    use Concerns\ManagesChartOfAccounts;
    use Concerns\ManagesCreditMemos;
    use Concerns\ManagesExpenses;
    use Concerns\ManagesFiscalYear;
    use Concerns\ManagesFixedAssets;
    use Concerns\ManagesInventoryFinancing;
    use Concerns\ManagesInvoices;
    use Concerns\ManagesJournalEntries;
    use Concerns\ManagesLoanFacilities;
    use Concerns\ManagesOwnerEquity;
    use Concerns\ManagesPeriodClosing;
    use Concerns\ManagesRequisitions;

    // -------------------------------------------------------------------------
    // A/R & A/P Aging (QuickBooks-style buckets)
    // -------------------------------------------------------------------------

    /**
     * Accounts Receivable Aging Summary.
     *
     * Groups open/partially-settled invoices by customer into aging buckets:
     *   current (not yet due), 1–30, 31–60, 61–90, 91+ days past due.
     */
    public function getArAging(mixed $asOfDate = null, ?string $sbuCode = null): array
    {
        $asOf = $asOfDate ? \Illuminate\Support\Carbon::parse($asOfDate) : now();

        $invoices = Invoice::with('customer')
            ->whereIn('status', ['sent', 'issued', 'partially_settled', 'overdue'])
            ->where('due_date', '<=', $asOf->toDateString())
            ->orWhere(fn ($q) => $q->whereIn('status', ['sent', 'issued', 'partially_settled', 'overdue']))
            ->get();

        $rows = [];
        $totals = ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0, 'total' => 0.0];

        foreach ($invoices->groupBy(fn ($inv) => (string) ($inv->customer?->name ?? 'Unknown')) as $name => $group) {
            $row = ['name' => $name, 'current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0, 'total' => 0.0];

            foreach ($group as $invoice) {
                $outstanding = (float) $invoice->total - (float) $invoice->paid_amount;

                if ($outstanding <= $this->tolerance()) {
                    continue;
                }

                $dueDate = \Illuminate\Support\Carbon::parse($invoice->due_date);
                $daysOverdue = $dueDate->isPast() ? (int) $dueDate->diffInDays($asOf) : 0;

                $bucket = match (true) {
                    $daysOverdue === 0 => 'current',
                    $daysOverdue <= 30 => '1_30',
                    $daysOverdue <= 60 => '31_60',
                    $daysOverdue <= 90 => '61_90',
                    default            => 'over_90',
                };

                $row[$bucket] += $outstanding;
                $row['total'] += $outstanding;
            }

            if ($row['total'] > $this->tolerance()) {
                $rows[] = $row;

                foreach (['current', '1_30', '31_60', '61_90', 'over_90', 'total'] as $b) {
                    $totals[$b] += $row[$b];
                }
            }
        }

        return [
            'as_of_date' => $asOf->toDateString(),
            'sbu_code'   => $this->normalizeSbuCode($sbuCode),
            'rows'       => $rows,
            'totals'     => $totals,
        ];
    }

    /**
     * Accounts Payable Aging Summary.
     *
     * Groups open/partially-settled bills by vendor into aging buckets:
     *   current (not yet due), 1–30, 31–60, 61–90, 91+ days past due.
     */
    public function getApAging(mixed $asOfDate = null, ?string $sbuCode = null): array
    {
        $asOf = $asOfDate ? \Illuminate\Support\Carbon::parse($asOfDate) : now();

        $bills = Bill::with('vendor')
            ->whereIn('status', ['issued', 'partially_settled', 'overdue'])
            ->get();

        $rows = [];
        $totals = ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0, 'total' => 0.0];

        foreach ($bills->groupBy(fn ($bill) => (string) ($bill->vendor?->name ?? 'Unknown')) as $name => $group) {
            $row = ['name' => $name, 'current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0, 'total' => 0.0];

            foreach ($group as $bill) {
                $outstanding = (float) $bill->total - (float) $bill->paid_amount;

                if ($outstanding <= $this->tolerance()) {
                    continue;
                }

                $dueDate = \Illuminate\Support\Carbon::parse($bill->due_date);
                $daysOverdue = $dueDate->isPast() ? (int) $dueDate->diffInDays($asOf) : 0;

                $bucket = match (true) {
                    $daysOverdue === 0 => 'current',
                    $daysOverdue <= 30 => '1_30',
                    $daysOverdue <= 60 => '31_60',
                    $daysOverdue <= 90 => '61_90',
                    default            => 'over_90',
                };

                $row[$bucket] += $outstanding;
                $row['total'] += $outstanding;
            }

            if ($row['total'] > $this->tolerance()) {
                $rows[] = $row;

                foreach (['current', '1_30', '31_60', '61_90', 'over_90', 'total'] as $b) {
                    $totals[$b] += $row[$b];
                }
            }
        }

        return [
            'as_of_date' => $asOf->toDateString(),
            'sbu_code'   => $this->normalizeSbuCode($sbuCode),
            'rows'       => $rows,
            'totals'     => $totals,
        ];
    }

    // -------------------------------------------------------------------------
    // Bank Reconciliation
    // -------------------------------------------------------------------------

    public function createBankReconciliation(array $data): BankReconciliation
    {
        return DB::transaction(fn (): BankReconciliation => BankReconciliation::create([
            'account_id'               => $data['account_id'],
            'statement_date'           => $data['statement_date'],
            'opening_balance'          => $data['opening_balance'] ?? 0,
            'statement_ending_balance' => $data['statement_ending_balance'] ?? 0,
            'status'                   => BankReconciliationStatus::DRAFT->value,
            'notes'                    => $data['notes'] ?? null,
        ]));
    }

    /**
     * Import already-parsed statement rows (CSV parsing happens in the Livewire layer).
     *
     * @param  array<int, array{transaction_date: string, description: string, amount: float,
     *               type: string, external_reference?: string|null}>  $rows
     */
    public function importBankStatementLines(BankReconciliation $reconciliation, array $rows): Collection
    {
        if ($reconciliation->status === BankReconciliationStatus::COMPLETED) {
            throw new AccountingException('Cannot import statement lines into a completed reconciliation.');
        }

        return DB::transaction(function () use ($reconciliation, $rows): Collection {
            $lines = new Collection();

            foreach ($rows as $row) {
                $lines->push(BankStatementLine::create([
                    'bank_reconciliation_id' => $reconciliation->id,
                    'transaction_date'       => $row['transaction_date'],
                    'description'            => $row['description'],
                    'amount'                 => $row['amount'],
                    'type'                   => strtolower((string) $row['type']),
                    'external_reference'     => $row['external_reference'] ?? null,
                ]));
            }

            return $lines;
        });
    }

    /** GL lines for an account that haven't yet been reconciled against a bank statement. */
    public function getUnreconciledLines(int $accountId): Collection
    {
        return Account::findOrFail($accountId)->journalEntryLines()
            ->whereNull('bank_reconciliation_id')
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Match a statement line to a GL line: validates neither side is already matched,
     * the amounts agree within tolerance, and the debit/credit polarity matches exactly.
     */
    public function matchStatementLine(BankStatementLine $statementLine, JournalEntryLine $glLine): void
    {
        DB::transaction(function () use ($statementLine, $glLine): void {
            $statementLine = BankStatementLine::lockForUpdate()->findOrFail($statementLine->id);
            $glLine = JournalEntryLine::lockForUpdate()->findOrFail($glLine->id);

            if ($statementLine->matched_journal_entry_line_id !== null) {
                throw StatementLineAlreadyMatchedException::forLine($statementLine->id);
            }

            if ($glLine->bank_reconciliation_id !== null) {
                throw StatementLineAlreadyMatchedException::forGlLine($glLine->id);
            }

            $statementType = strtolower((string) $statementLine->type);
            $glType = strtolower((string) $glLine->type);

            if ($statementType !== $glType) {
                throw StatementLinePolarityMismatchException::make($statementType, $glType);
            }

            $variance = abs((float) $statementLine->amount - (float) $glLine->amount);

            if ($variance > $this->tolerance()) {
                throw AmountToleranceExceededException::make((float) $statementLine->amount, (float) $glLine->amount, $this->tolerance());
            }

            $now = now();

            $statementLine->update(['matched_journal_entry_line_id' => $glLine->id, 'matched_at' => $now]);
            $glLine->update(['bank_reconciliation_id' => $statementLine->bank_reconciliation_id, 'reconciled_at' => $now]);
        });
    }

    public function unmatchStatementLine(BankStatementLine $statementLine): void
    {
        DB::transaction(function () use ($statementLine): void {
            $statementLine = BankStatementLine::lockForUpdate()->findOrFail($statementLine->id);
            $reconciliation = BankReconciliation::findOrFail($statementLine->bank_reconciliation_id);

            if ($reconciliation->status === BankReconciliationStatus::COMPLETED) {
                throw new AccountingException('Cannot unmatch a line on a completed reconciliation.');
            }

            if ($statementLine->matched_journal_entry_line_id === null) {
                return;
            }

            $glLine = JournalEntryLine::lockForUpdate()->find($statementLine->matched_journal_entry_line_id);

            $statementLine->update(['matched_journal_entry_line_id' => null, 'matched_at' => null]);
            $glLine?->update(['bank_reconciliation_id' => null, 'reconciled_at' => null]);
        });
    }

    /**
     * Wraps createJournalEntry() with a bank-account leg + an offset leg (bank fees,
     * interest, etc.), posts it, then matches the bank leg to the unmatched statement
     * line in the same transaction — resolving lines that have no counterpart GL entry.
     */
    public function createAdjustingJournalEntryForStatementLine(BankStatementLine $statementLine, array $data): JournalEntry
    {
        return DB::transaction(function () use ($statementLine, $data): JournalEntry {
            $statementLine = BankStatementLine::lockForUpdate()->findOrFail($statementLine->id);

            if ($statementLine->matched_journal_entry_line_id !== null) {
                throw StatementLineAlreadyMatchedException::forLine($statementLine->id);
            }

            $reconciliation = BankReconciliation::findOrFail($statementLine->bank_reconciliation_id);
            $bankAccount = Account::findOrFail($reconciliation->account_id);
            $offsetAccount = $this->requireAccountById((int) $data['offset_account_id']);

            $type = strtolower((string) $statementLine->type);
            $amount = (float) $statementLine->amount;

            $entry = $this->createJournalEntry([
                'date'        => $statementLine->transaction_date,
                'reference'   => $statementLine->external_reference,
                'type'        => 'general',
                'description' => $data['description'] ?? "Bank reconciliation adjustment — {$statementLine->description}",
                'source_type' => BankReconciliation::class,
                'source_id'   => $reconciliation->id,
                'lines'       => [
                    ['account_id' => $bankAccount->id, 'type' => $type, 'amount' => $amount, 'description' => $statementLine->description],
                    ['account_id' => $offsetAccount->id, 'type' => $type === 'debit' ? 'credit' : 'debit', 'amount' => $amount, 'description' => $data['description'] ?? $statementLine->description],
                ],
            ]);

            $entry->post();

            $bankLine = $entry->lines()->where('account_id', $bankAccount->id)->first();
            $this->matchStatementLine($statementLine, $bankLine);

            return $entry;
        });
    }

    /**
     * Completes a reconciliation: every statement line must already be matched, and the
     * opening balance plus reconciled debits/credits must agree with the statement's
     * ending balance within tolerance.
     */
    public function completeBankReconciliation(BankReconciliation $reconciliation): void
    {
        DB::transaction(function () use ($reconciliation): void {
            $reconciliation = BankReconciliation::lockForUpdate()->findOrFail($reconciliation->id);

            if ($reconciliation->statementLines()->whereNull('matched_journal_entry_line_id')->exists()) {
                throw new AccountingException('All statement lines must be matched or resolved via an adjusting entry before completing.');
            }

            $reconciledDebits = (float) $reconciliation->reconciledLines()->where('type', 'debit')->sum('amount');
            $reconciledCredits = (float) $reconciliation->reconciledLines()->where('type', 'credit')->sum('amount');

            $expected = round((float) $reconciliation->opening_balance + $reconciledDebits - $reconciledCredits, 2);
            $actual = round((float) $reconciliation->statement_ending_balance, 2);
            $variance = round(abs($expected - $actual), 2);

            if ($variance > $this->tolerance()) {
                throw ReconciliationBalanceMismatchException::make($expected, $actual, $variance);
            }

            $reconciliation->update([
                'status'        => BankReconciliationStatus::COMPLETED->value,
                'reconciled_by' => auth()->id(),
                'reconciled_at' => now(),
            ]);
        });
    }
}
