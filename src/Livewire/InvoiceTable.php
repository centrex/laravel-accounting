<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Models\Invoice;
use Centrex\TallUi\Concerns\WithFilters;
use Centrex\TallUi\DataTable\{Column, Filter};
use Centrex\TallUi\Livewire\DataTable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

class InvoiceTable extends DataTable
{
    use WithFilters;

    public string $defaultSortBy = 'invoice_date';

    public string $defaultSortDirection = 'desc';

    /** Re-render after the parent page posts an invoice or records a payment. */
    #[On('invoice-table:refresh')]
    public function refreshTable(): void {}

    public function columns(): array
    {
        $currency = (string) config('accounting.base_currency', 'BDT');

        return [
            Column::make('Invoice #', 'invoice_number')->searchable()->sortable()
                ->view('accounting::livewire.partials.invoice-table.number'),
            Column::make('Customer', 'customer.name')->relation('customer')->searchable()
                ->view('accounting::livewire.partials.invoice-table.customer'),
            Column::make('Date', 'invoice_date')->sortable()->format('date'),
            Column::make('Due Date', 'due_date')->sortable()->format('date')->hideOnMobile(),
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
                ->view('accounting::livewire.partials.invoice-table.actions'),
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
            Filter::dateRange('Invoice Date', 'invoice_date'),
        ];
    }

    public function query(): Builder
    {
        return Invoice::query()
            ->with('customer')
            // Agent B2B invoices are internal cost records visible only via the
            // inventory-pro agent invoices page — exclude them from this list.
            ->where(fn ($q) => $q->whereNull('source_type')->orWhere('source_type', '!=', 'agent_b2b'));
    }

    protected function applySearchConstraint(Builder $query, string $column, string $search): void
    {
        // The customer cell displays organization_name when present, so the
        // search must match either name.
        if ($column === 'customer.name') {
            $query->orWhereHas('customer', function (Builder $q) use ($search): void {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('organization_name', 'like', '%' . $search . '%');
            });

            return;
        }

        parent::applySearchConstraint($query, $column, $search);
    }
}
