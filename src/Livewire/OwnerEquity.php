<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Concerns\WithCurrency;
use Centrex\Accounting\Models\{Account, JournalEntryLine};
use Livewire\{Component, WithPagination};

class OwnerEquity extends Component
{
    use WithCurrency;
    use WithPagination;

    // Capital contribution form
    public bool $showContributionModal = false;

    public string $contribution_amount = '';

    public string $contribution_date = '';

    public string $contribution_account_code = '1100';

    public string $contribution_description = '';

    // Owner drawing form
    public bool $showDrawingModal = false;

    public string $drawing_amount = '';

    public string $drawing_date = '';

    public string $drawing_account_code = '1100';

    public string $drawing_description = '';

    public function mount(): void
    {
        $this->contribution_account_code = config('accounting.accounts.bank', '1100');
        $this->drawing_account_code = config('accounting.accounts.bank', '1100');
    }

    public function cashBankAccounts(): \Illuminate\Database\Eloquent\Collection
    {
        return Account::where('is_active', true)
            ->where(fn ($q) => $q->where('code', 'like', '10%')->orWhere('code', 'like', '11%'))
            ->orderBy('code')
            ->get();
    }

    public function capitalAccount(): ?Account
    {
        return Account::where('code', config('accounting.accounts.capital', '3000'))->first();
    }

    public function drawingsAccount(): ?Account
    {
        return Account::where('code', config('accounting.accounts.owner_drawings', '3200'))->first();
    }

    public function retainedEarningsAccount(): ?Account
    {
        return Account::where('code', config('accounting.accounts.retained_earnings', '3100'))->first();
    }

    public function openContribution(): void
    {
        $this->reset(['contribution_amount', 'contribution_description']);
        $this->contribution_date = now()->format('Y-m-d');
        $this->contribution_account_code = config('accounting.accounts.bank', '1100');
        $this->showContributionModal = true;
    }

    public function recordContribution(): void
    {
        $this->validate([
            'contribution_amount'       => 'required|numeric|min:0.01',
            'contribution_date'         => 'required|date',
            'contribution_account_code' => 'required|string',
        ]);

        $capital = $this->capitalAccount();
        $depositAccount = Account::where('code', $this->contribution_account_code)->where('is_active', true)->first();

        if (!$capital) {
            $this->dispatch('notify', type: 'error', message: 'Capital account (' . config('accounting.accounts.capital', '3000') . ') not found. Run Accounting::initializeChartOfAccounts() first.');

            return;
        }

        if (!$depositAccount) {
            $this->dispatch('notify', type: 'error', message: "Deposit account {$this->contribution_account_code} not found or inactive.");

            return;
        }

        try {
            $entry = app(Accounting::class)->createJournalEntry([
                'date'        => $this->contribution_date,
                'reference'   => 'CAP-' . now()->format('YmdHis'),
                'type'        => 'general',
                'description' => $this->contribution_description ?: 'Owner capital contribution',
                'currency'    => self::getCurrency(),
                'lines'       => [
                    ['account_id' => $depositAccount->id, 'type' => 'debit',  'amount' => (float) $this->contribution_amount],
                    ['account_id' => $capital->id,         'type' => 'credit', 'amount' => (float) $this->contribution_amount],
                ],
            ]);
            $entry->post();

            $this->dispatch('notify', type: 'success', message: 'Capital contribution recorded.');
            $this->showContributionModal = false;
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function openDrawing(): void
    {
        $this->reset(['drawing_amount', 'drawing_description']);
        $this->drawing_date = now()->format('Y-m-d');
        $this->drawing_account_code = config('accounting.accounts.bank', '1100');
        $this->showDrawingModal = true;
    }

    public function recordDrawing(): void
    {
        $this->validate([
            'drawing_amount'       => 'required|numeric|min:0.01',
            'drawing_date'         => 'required|date',
            'drawing_account_code' => 'required|string',
        ]);

        $drawings = $this->drawingsAccount();
        $sourceAccount = Account::where('code', $this->drawing_account_code)->where('is_active', true)->first();

        if (!$drawings) {
            $this->dispatch('notify', type: 'error', message: "Owner Drawings account ({$this->drawing_account_code}) not found. Run Accounting::initializeChartOfAccounts() first.");

            return;
        }

        if (!$sourceAccount) {
            $this->dispatch('notify', type: 'error', message: "Source account {$this->drawing_account_code} not found or inactive.");

            return;
        }

        try {
            $entry = app(Accounting::class)->createJournalEntry([
                'date'        => $this->drawing_date,
                'reference'   => 'DRAW-' . now()->format('YmdHis'),
                'type'        => 'general',
                'description' => $this->drawing_description ?: 'Owner drawing',
                'currency'    => self::getCurrency(),
                'lines'       => [
                    ['account_id' => $drawings->id,      'type' => 'debit',  'amount' => (float) $this->drawing_amount],
                    ['account_id' => $sourceAccount->id, 'type' => 'credit', 'amount' => (float) $this->drawing_amount],
                ],
            ]);
            $entry->post();

            $this->dispatch('notify', type: 'success', message: 'Owner drawing recorded.');
            $this->showDrawingModal = false;
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $equityAccountIds = array_filter([
            $this->capitalAccount()?->id,
            $this->drawingsAccount()?->id,
            $this->retainedEarningsAccount()?->id,
        ]);

        $entries = JournalEntryLine::query()
            ->with(['journalEntry', 'account'])
            ->whereIn('account_id', $equityAccountIds)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'))
            ->orderByDesc('id')
            ->paginate(config('accounting.per_page.equity_entries', 15));

        $layout = view()->exists('layouts.app')
        ? 'layouts.app'
        : 'components.layouts.app';

        return view('accounting::livewire.owner-equity', [
            'entries'                 => $entries,
            'capitalAccount'          => $this->capitalAccount(),
            'drawingsAccount'         => $this->drawingsAccount(),
            'retainedEarningsAccount' => $this->retainedEarningsAccount(),
            'cashBankAccounts'        => $this->cashBankAccounts(),
        ])->layout($layout, ['title' => __("Owner's Equity")]);
    }
}
