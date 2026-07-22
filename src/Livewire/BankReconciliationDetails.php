<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Enums\BankReconciliationStatus;
use Centrex\Accounting\Models\{Account, BankReconciliation, BankStatementLine, JournalEntryLine};
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

class BankReconciliationDetails extends Component
{
    public BankReconciliation $bankReconciliation;

    public string $csvInput = '';

    public bool $showAdjustModal = false;

    public ?int $adjustingStatementLineId = null;

    public string $adjust_description = '';

    public ?int $adjust_offset_account_id = null;

    public function mount(BankReconciliation $bankReconciliation): void
    {
        $this->bankReconciliation = $bankReconciliation;
    }

    #[Computed]
    public function unreconciledGlLines(): \Illuminate\Support\Collection
    {
        return app(Accounting::class)->getUnreconciledLines($this->bankReconciliation->account_id);
    }

    #[Computed]
    public function statementLines(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->bankReconciliation->statementLines()->orderBy('transaction_date')->get();
    }

    #[Computed]
    public function offsetAccounts(): \Illuminate\Database\Eloquent\Collection
    {
        return Account::where('is_active', true)
            ->whereIn('type', ['expense', 'revenue'])
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    public function isCompleted(): bool
    {
        return $this->bankReconciliation->status === BankReconciliationStatus::COMPLETED;
    }

    /**
     * Parse pasted CSV (date,description,amount,type,reference — one row per line) and
     * import valid rows. Malformed rows are skipped and reported, not all-or-nothing.
     */
    public function importCsv(): void
    {
        $lines = array_filter(array_map('trim', explode("\n", $this->csvInput)), fn ($l) => $l !== '');
        $rows = [];
        $errors = [];

        foreach ($lines as $lineNumber => $line) {
            $columns = str_getcsv($line);

            if (count($columns) < 4) {
                $errors[] = 'Line ' . ($lineNumber + 1) . ': expected at least date, description, amount, type.';

                continue;
            }

            [$date, $description, $amount, $type] = array_map('trim', array_slice($columns, 0, 4));
            $reference = isset($columns[4]) ? trim((string) $columns[4]) : null;

            if (!is_numeric($amount) || !in_array(strtolower($type), ['debit', 'credit'], true) || strtotime($date) === false) {
                $errors[] = 'Line ' . ($lineNumber + 1) . ': invalid date, amount, or type — skipped.';

                continue;
            }

            $rows[] = [
                'transaction_date'    => $date,
                'description'         => $description,
                'amount'              => (float) $amount,
                'type'                => strtolower($type),
                'external_reference'  => $reference ?: null,
            ];
        }

        if ($rows !== []) {
            app(Accounting::class)->importBankStatementLines($this->bankReconciliation, $rows);
            $this->dispatch('notify', type: 'success', message: count($rows) . ' statement line(s) imported.');
            $this->csvInput = '';
        }

        foreach ($errors as $error) {
            $this->dispatch('notify', type: 'error', message: $error);
        }
    }

    public function matchLine(int $statementLineId, int $glLineId): void
    {
        try {
            app(Accounting::class)->matchStatementLine(
                BankStatementLine::findOrFail($statementLineId),
                JournalEntryLine::findOrFail($glLineId),
            );
            $this->dispatch('notify', type: 'success', message: 'Lines matched.');
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function unmatchLine(int $statementLineId): void
    {
        try {
            app(Accounting::class)->unmatchStatementLine(BankStatementLine::findOrFail($statementLineId));
            $this->dispatch('notify', type: 'info', message: 'Match cleared.');
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function openAdjustModal(int $statementLineId): void
    {
        $this->adjustingStatementLineId = $statementLineId;
        $this->adjust_description = '';
        $this->adjust_offset_account_id = null;
        $this->showAdjustModal = true;
    }

    public function saveAdjustingEntry(): void
    {
        $this->validate([
            'adjust_offset_account_id' => 'required|integer|exists:' . (new Account())->getTable() . ',id',
        ]);

        try {
            app(Accounting::class)->createAdjustingJournalEntryForStatementLine(
                BankStatementLine::findOrFail($this->adjustingStatementLineId),
                [
                    'description'        => $this->adjust_description ?: null,
                    'offset_account_id'  => $this->adjust_offset_account_id,
                ],
            );

            $this->dispatch('notify', type: 'success', message: 'Adjusting entry posted and matched.');
            $this->showAdjustModal = false;
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function complete(): void
    {
        if (Gate::denies('accounting.bank-reconciliation.reconcile')) {
            $this->dispatch('notify', type: 'error', message: 'You are not authorized to complete bank reconciliations.');

            return;
        }

        try {
            app(Accounting::class)->completeBankReconciliation($this->bankReconciliation);
            $this->dispatch('notify', type: 'success', message: 'Reconciliation completed.');
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function render(): View
    {
        $this->bankReconciliation->load('account');

        $layout = view()->exists('layouts.app') ? 'layouts.app' : 'components.layouts.app';

        return view('accounting::livewire.bank-reconciliation-details')
            ->layout($layout, ['title' => __('Bank Reconciliation')]);
    }
}
