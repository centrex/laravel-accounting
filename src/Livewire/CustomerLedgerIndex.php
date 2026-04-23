<?php

declare(strict_types=1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Concerns\WithCurrency;
use Centrex\Accounting\Models\Customer;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class CustomerLedgerIndex extends Component
{
    use WithCurrency;
    use WithPagination;

    public string $search = '';

    public string $currency;

    protected array $queryString = ['search'];

    public function mount(): void
    {
        $this->currency = self::getCurrency();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $customers = Customer::query()
            ->when($this->search, fn ($q) => $q->where(function ($q): void {
                $q->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('code', 'like', '%' . $this->search . '%')
                    ->orWhere('email', 'like', '%' . $this->search . '%');
            }))
            ->where('is_active', true)
            ->withSum(
                ['invoices as invoiced_sum' => fn ($q) => $q->whereIn('status', ['issued', 'partially_settled', 'overdue'])],
                'total',
            )
            ->withSum(
                ['invoices as paid_sum' => fn ($q) => $q->whereIn('status', ['issued', 'partially_settled', 'overdue'])],
                'paid_amount',
            )
            ->orderBy('name')
            ->paginate(config('accounting.per_page.invoices', 15));

        $layout = view()->exists('layouts.app') ? 'layouts.app' : 'components.layouts.app';

        return view('accounting::livewire.customer-ledger-index', [
            'customers' => $customers,
        ])->layout($layout, ['title' => __('Customer Ledger')]);
    }
}
