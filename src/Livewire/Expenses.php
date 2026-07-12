<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Concerns\ShowsAuditTrail;
use Centrex\Accounting\Facades\Accounting;
use Centrex\Accounting\Models\{Account, Expense, ExpenseItem};
use Illuminate\Support\Facades\{DB, Gate};
use Livewire\Attributes\{Computed, On};
use Livewire\Component;

class Expenses extends Component
{
    use ShowsAuditTrail;

    public bool $showModal = false;

    public bool $showPayModal = false;

    public ?int $expenseId = null;

    public ?int $account_id = null;

    public string $expense_date = '';

    public string $due_date = '';

    public string $notes = '';

    public string $payment_method = 'cash';

    public string $payment_account_code = '1100';

    public string $reference = '';

    public string $vendor_name = '';

    public array $items = [];

    public ?int $payingExpenseId = null;

    public string $pay_date = '';

    public string $pay_amount = '';

    public string $pay_method = 'cash';

    public string $pay_reference = '';

    public string $pay_notes = '';

    public function mount(): void
    {
        $this->expense_date = now()->format('Y-m-d');
        $this->due_date = now()->addDays(30)->format('Y-m-d');
        $this->addItem();
    }

    public function addItem(): void
    {
        $this->items[] = [
            'description' => '',
            'quantity'    => 1,
            'unit_price'  => 0,
            'tax_rate'    => 0,
        ];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    #[Computed]
    public function bankAccounts(): \Illuminate\Database\Eloquent\Collection
    {
        return Account::where('is_active', true)
            ->where('type', 'asset')
            ->where(fn ($q) => $q->where('code', 'like', '10%')->orWhere('code', 'like', '11%'))
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    public function openCreate(): void
    {
        $this->reset(['expenseId', 'account_id', 'notes', 'reference', 'vendor_name', 'items']);
        $this->expense_date = now()->format('Y-m-d');
        $this->due_date = now()->addDays(30)->format('Y-m-d');
        $this->payment_account_code = '1100';
        $this->addItem();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'account_id'           => 'nullable|integer',
            'expense_date'         => 'required|date',
            'due_date'             => 'nullable|date|after_or_equal:expense_date',
            'payment_method'       => 'required|string|in:cash,check,bank_transfer,card,credit',
            'payment_account_code' => 'required_unless:payment_method,credit|nullable|string',
            'items'                => 'required|array|min:1',
            'items.*.description'  => 'required|string',
            'items.*.quantity'     => 'required|numeric|min:0.01',
            'items.*.unit_price'   => 'required|numeric|min:0',
            'items.*.tax_rate'     => 'nullable|numeric|min:0|max:100',
        ]);

        DB::transaction(function (): void {
            $subtotal = 0;
            $taxAmount = 0;

            foreach ($this->items as $item) {
                $amount = $item['quantity'] * $item['unit_price'];
                $itemTax = $amount * (($item['tax_rate'] ?? 0) / 100);
                $subtotal += $amount;
                $taxAmount += $itemTax;
            }

            $expense = Expense::create([
                'account_id'           => $this->account_id,
                'expense_date'         => $this->expense_date,
                'due_date'             => $this->due_date ?: null,
                'subtotal'             => $subtotal,
                'tax_amount'           => $taxAmount,
                'total'                => $subtotal + $taxAmount,
                'currency'             => config('accounting.base_currency', 'BDT'),
                'status'               => 'draft',
                'payment_method'       => $this->payment_method,
                'payment_account_code' => $this->payment_method !== 'credit' ? $this->payment_account_code : null,
                'reference'            => $this->reference ?: null,
                'vendor_name'          => $this->vendor_name ?: null,
                'notes'                => $this->notes ?: null,
            ]);

            foreach ($this->items as $item) {
                $amount = $item['quantity'] * $item['unit_price'];
                $tax = $amount * (($item['tax_rate'] ?? 0) / 100);

                ExpenseItem::create([
                    'expense_id'  => $expense->id,
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'],
                    'unit_price'  => $item['unit_price'],
                    'amount'      => $amount,
                    'tax_rate'    => $item['tax_rate'] ?? 0,
                    'tax_amount'  => $tax,
                ]);
            }
        });

        $this->dispatch('notify', type: 'success', message: 'Expense recorded successfully!');
        $this->showModal = false;
        $this->reset(['expenseId', 'account_id', 'items', 'notes', 'reference', 'vendor_name']);
        $this->payment_method = 'cash';
        $this->payment_account_code = '1100';
        $this->expense_date = now()->format('Y-m-d');
        $this->due_date = now()->addDays(30)->format('Y-m-d');
        $this->addItem();
        $this->dispatch('expense-table:refresh');
    }

    #[On('expense-table:delete')]
    public function delete(int $id): void
    {
        if (Gate::denies('accounting.expense.delete')) {
            $this->dispatch('notify', type: 'error', message: 'You are not authorized to delete expenses.');

            return;
        }

        $expense = Expense::findOrFail($id);

        if ($expense->status !== 'draft') {
            $this->dispatch('notify', type: 'error', message: 'Only draft expenses can be deleted.');

            return;
        }

        $expense->delete();
        $this->dispatch('notify', type: 'success', message: 'Expense deleted.');
        $this->dispatch('expense-table:refresh');
    }

    #[On('expense-table:post')]
    public function postExpense(int $id): void
    {
        $expense = Expense::findOrFail($id);

        try {
            Accounting::postExpense($expense);
            $this->dispatch('notify', type: 'success', message: "Expense {$expense->expense_number} posted.");
            $this->dispatch('expense-table:refresh');
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    #[On('expense-table:audit')]
    public function openExpenseAuditTrail(int $id): void
    {
        $expense = Expense::findOrFail($id);
        $this->openAuditTrail($expense::class, $expense->getKey(), $expense->reference ?: ('Expense #' . $expense->getKey()));
    }

    #[On('expense-table:pay')]
    public function openPayModal(int $id): void
    {
        $expense = Expense::findOrFail($id);
        $this->payingExpenseId = $id;
        $this->pay_date = now()->format('Y-m-d');
        $this->pay_amount = number_format($expense->balance, 2, '.', '');
        $this->pay_method = 'cash';
        $this->pay_reference = '';
        $this->pay_notes = '';
        $this->showPayModal = true;
    }

    public function recordPayment(): void
    {
        $this->validate([
            'pay_date'   => 'required|date',
            'pay_amount' => 'required|numeric|min:0.01',
            'pay_method' => 'required|string',
        ]);

        $expense = Expense::findOrFail($this->payingExpenseId);

        try {
            Accounting::recordExpensePayment($expense, [
                'date'      => $this->pay_date,
                'amount'    => $this->pay_amount,
                'method'    => $this->pay_method,
                'reference' => $this->pay_reference ?: null,
                'notes'     => $this->pay_notes ?: null,
            ]);

            $this->dispatch('notify', type: 'success', message: 'Payment recorded successfully!');
            $this->showPayModal = false;
            $this->dispatch('expense-table:refresh');
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function getSubtotalProperty(): float
    {
        return collect($this->items)->sum(fn ($i): int|float => ($i['quantity'] ?? 0) * ($i['unit_price'] ?? 0));
    }

    public function getTaxTotalProperty(): float
    {
        return collect($this->items)->sum(function ($i): float {
            $amount = ($i['quantity'] ?? 0) * ($i['unit_price'] ?? 0);

            return $amount * (($i['tax_rate'] ?? 0) / 100);
        });
    }

    public function getTotalProperty(): float
    {
        return $this->subtotal + $this->taxTotal;
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $accounts = Account::where('type', 'expense')
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $layout = view()->exists('layouts.app')
            ? 'layouts.app'
            : 'components.layouts.app';

        return view('accounting::livewire.expenses', [
            'accounts' => $accounts,
        ])->layout($layout, ['title' => __('Expenses')]);
    }
}
