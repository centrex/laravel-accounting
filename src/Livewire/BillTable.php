<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Models\Bill;
use Centrex\TallUi\Concerns\WithFilters;
use Centrex\TallUi\DataTable\{Column, Filter};
use Centrex\TallUi\Livewire\DataTable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

class BillTable extends DataTable
{
    use WithFilters;

    public string $defaultSortBy = 'bill_date';

    public string $defaultSortDirection = 'desc';

    /** Re-render after the parent page posts a bill or records a payment. */
    #[On('bill-table:refresh')]
    public function refreshTable(): void {}

    public function columns(): array
    {
        $currency = (string) config('accounting.base_currency', 'BDT');

        return [
            Column::make('Bill #', 'bill_number')->searchable()->sortable(),
            Column::make('Vendor', 'vendor.name')->relation('vendor')->searchable(),
            Column::make('Bill Date', 'bill_date')->sortable()->format('date'),
            Column::make('Due Date', 'due_date')->sortable()->format('date')->hideOnMobile()
                ->view('accounting::livewire.partials.bill-table.due-date'),
            Column::make('Total', 'base_total')->currency($currency),
            Column::make('Balance', 'base_balance')->currency($currency),
            Column::make('Status', 'status')->badge('neutral', [
                'settled'           => 'success',
                'sent'              => 'info',
                'issued'            => 'info',
                'partially_settled' => 'warning',
                'overdue'           => 'error',
                'void'              => 'error',
            ]),
            Column::make('Actions')
                ->view('accounting::livewire.partials.bill-table.actions'),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::select('Status', 'status', [
                'draft'             => 'Draft',
                'sent'              => 'Sent',
                'issued'            => 'Issued',
                'partially_settled' => 'Partially Settled',
                'settled'           => 'Settled',
                'overdue'           => 'Overdue',
                'void'              => 'Void',
            ]),
            Filter::dateRange('Bill Date', 'bill_date'),
        ];
    }

    public function query(): Builder
    {
        return Bill::query()->with('vendor');
    }
}
