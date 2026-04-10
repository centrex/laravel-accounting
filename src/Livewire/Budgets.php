<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Concerns\WithCurrency;
use Centrex\Accounting\Models\{Account, Budget, BudgetItem, FiscalYear};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\{Component, WithPagination};

class Budgets extends Component
{
    use WithCurrency;
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public bool $showModal = false;

    public bool $showDetailModal = false;

    public ?int $budgetId = null;

    public string $name = '';

    public ?int $fiscal_year_id = null;

    public string $period_start = '';

    public string $period_end = '';

    public string $total_amount = '';

    public string $notes = '';

    public array $items = [];

    public ?int $viewingBudgetId = null;

    protected array $queryString = ['search', 'statusFilter'];

    public function mount(): void
    {
        $this->period_start = now()->startOfYear()->format('Y-m-d');
        $this->period_end = now()->endOfYear()->format('Y-m-d');
        $this->addItem();
    }

    public function addItem(): void
    {
        $this->items[] = [
            'account_id'  => '',
            'description' => '',
            'amount'      => 0,
        ];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function openCreate(): void
    {
        $this->reset(['budgetId', 'name', 'fiscal_year_id', 'notes', 'items']);
        $this->period_start = now()->startOfYear()->format('Y-m-d');
        $this->period_end = now()->endOfYear()->format('Y-m-d');
        $this->addItem();
        $this->showModal = true;
    }

    public function openDetail(int $id): void
    {
        $this->viewingBudgetId = $id;
        $this->showDetailModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'name'               => 'required|string|max:255',
            'period_start'       => 'required|date',
            'period_end'         => 'required|date|after_or_equal:period_start',
            'total_amount'       => 'required|numeric|min:0',
            'items'              => 'required|array|min:1',
            'items.*.account_id' => ['required', Rule::exists((new Account())->getTable(), 'id')],
            'items.*.amount'     => 'required|numeric|min:0',
        ]);

        $totalAllocated = collect($this->items)->sum('amount');

        if (abs((float) $this->total_amount - $totalAllocated) > 0.01) {
            $this->addError('total_amount', 'Total amount must equal sum of item amounts.');

            return;
        }

        DB::transaction(function (): void {
            $budget = Budget::create([
                'name'           => $this->name,
                'fiscal_year_id' => $this->fiscal_year_id,
                'period_start'   => $this->period_start,
                'period_end'     => $this->period_end,
                'total_amount'   => $this->total_amount,
                'currency'       => self::getCurrency(),
                'status'         => 'draft',
                'notes'          => $this->notes ?: null,
            ]);

            foreach ($this->items as $item) {
                BudgetItem::create([
                    'budget_id'    => $budget->id,
                    'account_id'   => $item['account_id'],
                    'description'  => $item['description'] ?: null,
                    'amount'       => $item['amount'],
                    'period_start' => $this->period_start,
                    'period_end'   => $this->period_end,
                ]);
            }
        });

        $this->dispatch('notify', type: 'success', message: 'Budget created successfully!');
        $this->showModal = false;
        $this->reset(['budgetId', 'name', 'fiscal_year_id', 'notes', 'items']);
    }

    public function approveBudget(int $id): void
    {
        $budget = Budget::findOrFail($id);

        try {
            app(Accounting::class)->approveBudget($budget);
            $this->dispatch('notify', type: 'success', message: "Budget {$budget->budget_number} approved.");
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function deleteBudget(int $id): void
    {
        $budget = Budget::findOrFail($id);

        if ($budget->status === 'approved') {
            $this->dispatch('notify', type: 'error', message: 'Cannot delete an approved budget.');

            return;
        }

        $budget->delete();
        $this->dispatch('notify', type: 'success', message: 'Budget deleted.');
    }

    public function getSubtotalProperty(): float
    {
        return collect($this->items)->sum('amount');
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $budgets = Budget::query()
            ->with(['fiscalYear', 'items.account'])
            ->when($this->search, fn ($q) => $q->where(function ($q): void {
                $q->where('budget_number', 'like', '%' . $this->search . '%')
                    ->orWhere('name', 'like', '%' . $this->search . '%');
            }))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->latest('created_at')
            ->paginate(config('accounting.per_page.budgets', 15));

        $fiscalYears = FiscalYear::orderBy('name', 'desc')->get();

        $expenseAccounts = Account::where('type', 'expense')
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $layout = view()->exists('layouts.app')
        ? 'layouts.app'
        : 'components.layouts.app';

        return view('accounting::livewire.budgets', [
            'budgets'         => $budgets,
            'fiscalYears'     => $fiscalYears,
            'expenseAccounts' => $expenseAccounts,
        ])->layout($layout, ['title' => __('Budgets')]);
    }
}
