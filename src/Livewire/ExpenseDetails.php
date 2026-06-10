<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Facades\Accounting;
use Centrex\Accounting\Models\Expense;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ExpenseDetails extends Component
{
    public Expense $expense;

    public bool $showPayModal = false;

    public string $pay_date = '';

    public string $pay_amount = '';

    public string $pay_method = 'cash';

    public string $pay_reference = '';

    public string $pay_notes = '';

    public function mount(Expense $expense): void
    {
        $this->expense = $expense;
        $this->pay_date = now()->format('Y-m-d');
    }

    public function postExpense(): void
    {
        try {
            Accounting::postExpense($this->expense);
            $this->dispatch('notify', type: 'success', message: "Expense {$this->expense->expense_number} posted.");
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function openPayModal(): void
    {
        $this->pay_date      = now()->format('Y-m-d');
        $this->pay_amount    = number_format((float) $this->expense->balance, 2, '.', '');
        $this->pay_method    = 'cash';
        $this->pay_reference = '';
        $this->pay_notes     = '';
        $this->showPayModal  = true;
    }

    public function recordPayment(): void
    {
        $this->validate([
            'pay_date'   => 'required|date',
            'pay_amount' => 'required|numeric|min:0.01',
            'pay_method' => 'required|string',
        ]);

        try {
            Accounting::recordExpensePayment($this->expense, [
                'date'      => $this->pay_date,
                'amount'    => $this->pay_amount,
                'method'    => $this->pay_method,
                'reference' => $this->pay_reference ?: null,
                'notes'     => $this->pay_notes ?: null,
            ]);

            $this->dispatch('notify', type: 'success', message: 'Payment recorded successfully.');
            $this->showPayModal = false;
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function render(): View
    {
        $this->expense->load([
            'items',
            'account',
            'journalEntry.lines.account',
            'chargeable',
        ]);

        $layout = view()->exists('layouts.app')
            ? 'layouts.app'
            : 'components.layouts.app';

        return view('accounting::livewire.expense-details')
            ->layout($layout, ['title' => __('Expense Details')]);
    }
}
