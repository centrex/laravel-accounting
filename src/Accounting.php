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
    FixedAsset,
    Invoice,
    JournalEntry,
    JournalEntryLine,
    Payment
};
use Centrex\Accounting\Models\Expense;
use Illuminate\Support\{Collection, Str};
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
    use Concerns\ManagesInventoryFinancing;
    use Concerns\ManagesInvoices;
    use Concerns\ManagesJournalEntries;
    use Concerns\ManagesLoanFacilities;
    use Concerns\ManagesOwnerEquity;
    use Concerns\ManagesPeriodClosing;
    use Concerns\ManagesRequisitions;

    // -------------------------------------------------------------------------
    // Fixed Assets (Property, Plant & Equipment — IAS 16)
    // -------------------------------------------------------------------------

    /**
     * Register a fixed asset and auto-create its dedicated GL sub-accounts.
     *
     * Cost sub-accounts allocate under parent 1700 ("Fixed Assets"), range 1701–1799.
     * Accumulated-depreciation sub-accounts allocate under parent 1800, range 1801–1899.
     * Does not post a journal entry — see capitalizeFixedAsset() for that.
     */
    public function addFixedAsset(
        string $name,
        float $acquisitionCost,
        int $usefulLifeMonths,
        float $salvageValue = 0.0,
        ?string $acquiredAt = null,
        ?string $assetClass = null,
        ?string $sbuCode = null,
        ?string $location = null,
        ?string $serialNumber = null,
        ?string $notes = null,
    ): FixedAsset {
        return DB::transaction(function () use (
            $name, $acquisitionCost, $usefulLifeMonths, $salvageValue,
            $acquiredAt, $assetClass, $sbuCode, $location, $serialNumber, $notes,
        ): FixedAsset {
            $assetParent = $this->requireAccount('1700');
            $contraParent = $this->requireAccount('1800');

            $assetCode = $this->nextSubAccountCode('1700', '1799');
            $contraCode = $this->nextSubAccountCode('1800', '1899');

            $shortName = Str::limit($name, 40, '');

            $assetAccount = Account::create([
                'code'      => $assetCode,
                'name'      => $shortName,
                'type'      => 'asset',
                'subtype'   => 'fixed_asset',
                'parent_id' => $assetParent->id,
                'is_system' => false,
            ]);

            $contraAccount = Account::create([
                'code'      => $contraCode,
                'name'      => "Accum. Depr. — {$shortName}",
                'type'      => 'asset',
                'subtype'   => 'contra_account',
                'parent_id' => $contraParent->id,
                'is_system' => false,
            ]);

            return FixedAsset::create([
                'name'                                => $name,
                'asset_class'                         => $assetClass,
                'sbu_code'                            => $sbuCode ? strtoupper(trim($sbuCode)) : null,
                'asset_account_id'                    => $assetAccount->id,
                'accumulated_depreciation_account_id' => $contraAccount->id,
                'acquisition_cost'                    => $acquisitionCost,
                'salvage_value'                       => $salvageValue,
                'useful_life_months'                  => $usefulLifeMonths,
                'depreciation_method'                 => 'straight_line',
                'acquired_at'                         => $acquiredAt ?? now()->toDateString(),
                'location'                            => $location,
                'serial_number'                       => $serialNumber,
                'notes'                               => $notes,
                'is_active'                           => true,
                'created_by'                          => auth()->id(),
            ]);
        });
    }

    /**
     * Capitalize the asset: record the outlay against its GL cost account.
     *
     * DR asset account (its own 170x code) / CR payment source (bank by default,
     * or another account code such as accounts_payable for credit purchases).
     */
    public function capitalizeFixedAsset(
        FixedAsset $asset,
        string $date,
        string $reference,
        ?string $paymentAccountCode = null,
        ?string $description = null,
    ): JournalEntry {
        $paymentAccount = $this->requireAccount($paymentAccountCode ?? $this->accountCode('bank'));

        return $this->createJournalEntry([
            'date'        => $date,
            'reference'   => $reference,
            'type'        => 'general',
            'sbu_code'    => $asset->sbu_code,
            'description' => $description ?? "Fixed asset capitalized — {$asset->name} ({$asset->asset_code})",
            'lines'       => [
                ['account_id' => $asset->asset_account_id, 'type' => 'debit',  'amount' => (float) $asset->acquisition_cost],
                ['account_id' => $paymentAccount->id,       'type' => 'credit', 'amount' => (float) $asset->acquisition_cost],
            ],
        ]);
    }

    /**
     * Post one period's straight-line depreciation for a single asset.
     *
     * DR Depreciation Expense (config: depreciation_expense, default 6600) / CR the
     * asset's own accumulated-depreciation account. Returns null when the asset is
     * inactive, already disposed, or fully depreciated. The final period is capped so
     * accumulated depreciation never exceeds the depreciable base.
     */
    public function depreciateAsset(FixedAsset $asset, ?string $date = null): ?JournalEntry
    {
        if (!$asset->is_active || $asset->isDisposed() || $asset->isFullyDepreciated()) {
            return null;
        }

        $remaining = round($asset->depreciableBase() - $asset->accumulatedDepreciation(), 2);
        $amount = min($asset->monthlyDepreciationAmount(), $remaining);

        if ($amount <= 0.0) {
            return null;
        }

        $date ??= now()->endOfMonth()->toDateString();
        $expenseAccount = $this->requireAccount($this->accountCode('depreciation_expense'));

        return $this->createJournalEntry([
            'date'        => $date,
            'reference'   => 'FA-DEPR-' . now()->format('Y-m') . '-' . $asset->id,
            'type'        => 'general',
            'sbu_code'    => $asset->sbu_code,
            'description' => sprintf(
                'Depreciation — %s (%s) — %s',
                $asset->name,
                $asset->asset_code,
                now()->format('F Y'),
            ),
            'lines' => [
                ['account_id' => $expenseAccount->id,                          'type' => 'debit',  'amount' => $amount],
                ['account_id' => $asset->accumulated_depreciation_account_id,  'type' => 'credit', 'amount' => $amount],
            ],
        ]);
    }

    /**
     * Depreciate all active, non-disposed assets.
     * Returns array keyed by asset id → JournalEntry|null.
     */
    public function depreciateAllAssets(?string $date = null): array
    {
        $results = [];

        FixedAsset::where('is_active', true)->whereNull('disposed_at')
            ->each(function (FixedAsset $asset) use ($date, &$results): void {
                $results[$asset->id] = $this->depreciateAsset($asset, $date);
            });

        return $results;
    }

    /**
     * Dispose of an asset: remove it and its accumulated depreciation from the GL,
     * record any cash proceeds, and plug the gain or loss on disposal.
     *
     * netBookValue = acquisition_cost − accumulated_depreciation
     * gainOrLoss   = proceeds − netBookValue   (positive = gain, negative = loss)
     *
     * Lines: CR asset account (acquisition_cost) / DR accumulated-depreciation account
     * (its balance, if any) / DR bank (proceeds, if any) / plug the gain (credit) or
     * loss (debit) to config('accounting.accounts.gain_loss_on_disposal') (default 4910).
     */
    public function disposeAsset(
        FixedAsset $asset,
        string $date,
        float $proceeds = 0.0,
        ?string $reference = null,
    ): JournalEntry {
        if ($asset->isDisposed()) {
            throw new \RuntimeException("Fixed asset '{$asset->asset_code}' has already been disposed.");
        }

        return DB::transaction(function () use ($asset, $date, $proceeds, $reference): JournalEntry {
            $accumulatedDepreciation = $asset->accumulatedDepreciation();
            $acquisitionCost = (float) $asset->acquisition_cost;
            $bookValue = round($acquisitionCost - $accumulatedDepreciation, 2);
            $gainOrLoss = round($proceeds - $bookValue, 2);

            $lines = [
                ['account_id' => $asset->asset_account_id, 'type' => 'credit', 'amount' => $acquisitionCost],
            ];

            if ($accumulatedDepreciation > 0) {
                $lines[] = ['account_id' => $asset->accumulated_depreciation_account_id, 'type' => 'debit', 'amount' => $accumulatedDepreciation];
            }

            if ($proceeds > 0) {
                $bank = $this->requireAccount($this->accountCode('bank'));
                $lines[] = ['account_id' => $bank->id, 'type' => 'debit', 'amount' => $proceeds];
            }

            if (abs($gainOrLoss) > 0.001) {
                $gainLossAccount = $this->requireAccount($this->accountCode('gain_loss_on_disposal'));
                $lines[] = $gainOrLoss > 0
                    ? ['account_id' => $gainLossAccount->id, 'type' => 'credit', 'amount' => $gainOrLoss]
                    : ['account_id' => $gainLossAccount->id, 'type' => 'debit',  'amount' => abs($gainOrLoss)];
            }

            $entry = $this->createJournalEntry([
                'date'        => $date,
                'reference'   => $reference ?? 'FA-DISPOSAL-' . now()->format('Y-m') . '-' . $asset->id,
                'type'        => 'general',
                'sbu_code'    => $asset->sbu_code,
                'description' => sprintf(
                    'Disposal of %s (%s) — book value %s, proceeds %s, %s %s',
                    $asset->name,
                    $asset->asset_code,
                    number_format($bookValue, 2),
                    number_format($proceeds, 2),
                    $gainOrLoss >= 0 ? 'gain' : 'loss',
                    number_format(abs($gainOrLoss), 2),
                ),
                'lines' => $lines,
            ]);

            $asset->update([
                'disposed_at'               => $date,
                'disposal_proceeds'         => $proceeds,
                'disposal_journal_entry_id' => $entry->id,
                'is_active'                 => false,
                'disposed_by'               => auth()->id(),
            ]);

            return $entry;
        });
    }

    /**
     * Fixed asset register: all assets with live GL-computed depreciation figures,
     * optionally filtered by SBU.
     */
    public function getFixedAssetRegister(?string $sbuCode = null): array
    {
        $query = FixedAsset::with(['assetAccount', 'accumulatedDepreciationAccount'])
            ->orderBy('asset_class')
            ->orderBy('name');

        if ($sbuCode !== null) {
            $query->where('sbu_code', strtoupper(trim($sbuCode)));
        }

        return $query->get()
            ->map(fn (FixedAsset $a): array => [
                'id'                               => $a->id,
                'asset_code'                       => $a->asset_code,
                'name'                             => $a->name,
                'asset_class'                      => $a->asset_class,
                'sbu_code'                         => $a->sbu_code,
                'is_active'                        => $a->is_active,
                'acquisition_cost'                 => (float) $a->acquisition_cost,
                'salvage_value'                    => (float) $a->salvage_value,
                'useful_life_months'               => $a->useful_life_months,
                'depreciation_method'              => $a->depreciation_method,
                'acquired_at'                      => $a->acquired_at?->toDateString(),
                'disposed_at'                      => $a->disposed_at?->toDateString(),
                'accumulated_depreciation'         => $a->accumulatedDepreciation(),
                'monthly_depreciation'             => $a->monthlyDepreciationAmount(),
                'net_book_value'                   => $a->netBookValue(),
                'is_fully_depreciated'             => $a->isFullyDepreciated(),
                'asset_account'                    => $a->assetAccount?->code . ' ' . $a->assetAccount?->name,
                'accumulated_depreciation_account' => $a->accumulatedDepreciationAccount?->code . ' ' . $a->accumulatedDepreciationAccount?->name,
            ])
            ->all();
    }

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
