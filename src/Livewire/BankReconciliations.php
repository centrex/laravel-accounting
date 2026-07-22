<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Models\{Account, BankReconciliation};
use Illuminate\Support\Facades\Gate;
use Livewire\{Component, WithPagination};

class BankReconciliations extends Component
{
    use WithPagination;

    public bool $showModal = false;

    public ?int $account_id = null;

    public string $statement_date = '';

    public string $opening_balance = '0';

    public string $statement_ending_balance = '0';

    public string $notes = '';

    public function openCreate(): void
    {
        $this->reset(['account_id', 'notes']);
        $this->statement_date = now()->format('Y-m-d');
        $this->opening_balance = '0';
        $this->statement_ending_balance = '0';
        $this->showModal = true;
    }

    public function updatedAccountId(): void
    {
        if (!$this->account_id) {
            return;
        }

        $account = Account::find($this->account_id);
        $this->opening_balance = $account ? number_format($account->getCurrentBalance(), 2, '.', '') : '0';
    }

    public function save(): void
    {
        if (Gate::denies('accounting.bank-reconciliation.create')) {
            $this->dispatch('notify', type: 'error', message: 'You are not authorized to create bank reconciliations.');

            return;
        }

        $this->validate([
            'account_id'               => 'required|integer|exists:' . (new Account())->getTable() . ',id',
            'statement_date'           => 'required|date',
            'opening_balance'          => 'required|numeric',
            'statement_ending_balance' => 'required|numeric',
        ]);

        try {
            $reconciliation = app(Accounting::class)->createBankReconciliation([
                'account_id'               => $this->account_id,
                'statement_date'           => $this->statement_date,
                'opening_balance'          => $this->opening_balance,
                'statement_ending_balance' => $this->statement_ending_balance,
                'notes'                    => $this->notes ?: null,
            ]);

            $this->showModal = false;
            $this->redirect(route('accounting.bank-reconciliations.show', $reconciliation), navigate: true);
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    #[\Livewire\Attributes\Computed]
    public function bankAccounts(): \Illuminate\Database\Eloquent\Collection
    {
        return Account::where('is_active', true)
            ->whereIn('subtype', ['checking_account', 'savings_account', 'cash', 'money_market_account', 'petty_cash_account', 'escrow_account'])
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $reconciliations = BankReconciliation::query()
            ->with('account')
            ->latest('statement_date')
            ->paginate(config('accounting.per_page.bank_reconciliations', 15));

        $layout = view()->exists('layouts.app')
        ? 'layouts.app'
        : 'components.layouts.app';

        return view('accounting::livewire.bank-reconciliations', ['reconciliations' => $reconciliations])
            ->layout($layout, ['title' => __('Bank Reconciliations')]);
    }
}
