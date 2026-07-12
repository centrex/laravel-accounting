<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Models\Expense;
use Centrex\TallUi\Concerns\WithFilters;
use Centrex\TallUi\DataTable\{Column, Filter};
use Centrex\TallUi\Livewire\DataTable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

class ExpenseTable extends DataTable
{
    use WithFilters;

    public string $defaultSortBy = 'expense_date';

    public string $defaultSortDirection = 'desc';

    /** Re-render after the parent page posts/pays/deletes an expense. */
    #[On('expense-table:refresh')]
    public function refreshTable(): void {}

    public function columns(): array
    {
        $currency = (string) config('accounting.base_currency', 'BDT');

        return [
            Column::make('Expense #', 'expense_number')->searchable()->sortable()
                ->view('accounting::livewire.partials.expense-table.number'),
            Column::make('Document', 'chargeable_type')
                ->view('accounting::livewire.partials.expense-table.document')
                ->excludeFromExport(),
            Column::make('Account', 'account.name')->relation('account')
                ->view('accounting::livewire.partials.expense-table.account'),
            Column::make('Vendor', 'vendor_name')->searchable()->sortable(),
            Column::make('Date', 'expense_date')->sortable()->format('date'),
            Column::make('Due Date', 'due_date')->sortable()->format('date')->hideOnMobile(),
            Column::make('Total', 'total')->currency($currency)->summable(),
            Column::make('Balance', 'balance')->currency($currency)->excludeFromExport()
                ->view('accounting::livewire.partials.expense-table.balance'),
            Column::make('Status', 'status')->badge('neutral', [
                'draft'    => 'neutral',
                'approved' => 'info',
                'partial'  => 'warning',
                'paid'     => 'success',
            ]),
            Column::make('Actions')
                ->view('accounting::livewire.partials.expense-table.actions'),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::select('Status', 'status', [
                'draft'    => 'Draft',
                'approved' => 'Approved',
                'partial'  => 'Partial',
                'paid'     => 'Paid',
            ]),
            Filter::dateRange('Expense Date', 'expense_date'),
        ];
    }

    public function query(): Builder
    {
        return Expense::query()->with(['account', 'chargeable']);
    }
}
