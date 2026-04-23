<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Models\{Customer, Invoice};
use Livewire\{Component, WithPagination};

class Customers extends Component
{
    use WithPagination;

    public string $search = '';

    public bool $showInactive = false;

    public bool $showModal = false;

    // Form fields
    public ?int $customerId = null;

    public string $code = '';

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $address = '';

    public string $city = '';

    public string $country = '';

    public string $tax_id = '';

    public string $currency = '';

    public string $credit_limit = '0';

    public int $payment_terms = 30;

    public bool $is_active = true;

    protected array $queryString = ['search'];

    public function mount(): void
    {
        $this->currency = config('accounting.base_currency', 'BDT');
    }

    public function openModal(?int $id = null): void
    {
        $this->resetValidation();
        $this->reset(['customerId', 'code', 'name', 'email', 'phone', 'address', 'city', 'country', 'tax_id']);
        $this->currency = config('accounting.base_currency', 'BDT');
        $this->credit_limit = '0';
        $this->payment_terms = 30;
        $this->is_active = true;

        if ($id) {
            $customer = Customer::findOrFail($id);
            $this->customerId = $customer->id;
            $this->code = $customer->code;
            $this->name = $customer->name;
            $this->email = $customer->email ?? '';
            $this->phone = $customer->phone ?? '';
            $this->address = $customer->address ?? '';
            $this->city = $customer->city ?? '';
            $this->country = $customer->country ?? '';
            $this->tax_id = $customer->tax_id ?? '';
            $this->currency = $customer->currency;
            $this->credit_limit = $customer->credit_limit;
            $this->payment_terms = $customer->payment_terms;
            $this->is_active = $customer->is_active;
        }

        $this->showModal = true;
    }

    public function save(): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $table = $prefix . 'customers';
        $id = $this->customerId;

        $this->validate([
            'code'          => "required|unique:{$table},code,{$id}",
            'name'          => 'required|min:2',
            'email'         => 'nullable|email',
            'currency'      => 'required|size:3',
            'credit_limit'  => 'nullable|numeric|min:0',
            'payment_terms' => 'nullable|integer|min:0',
        ]);

        $data = [
            'code'          => $this->code,
            'name'          => $this->name,
            'email'         => $this->email ?: null,
            'phone'         => $this->phone ?: null,
            'address'       => $this->address ?: null,
            'city'          => $this->city ?: null,
            'country'       => $this->country ?: null,
            'tax_id'        => $this->tax_id ?: null,
            'currency'      => $this->currency,
            'credit_limit'  => $this->credit_limit,
            'payment_terms' => $this->payment_terms,
            'is_active'     => $this->is_active,
        ];

        if ($this->customerId) {
            Customer::findOrFail($this->customerId)->update($data);
            $this->dispatch('notify', type: 'success', message: 'Customer updated!');
        } else {
            Customer::create($data);
            $this->dispatch('notify', type: 'success', message: 'Customer created!');
        }

        $this->showModal = false;
    }

    public function toggleStatus(int $id): void
    {
        $customer = Customer::findOrFail($id);
        $customer->update(['is_active' => !$customer->is_active]);
        $this->dispatch('notify', type: 'info', message: 'Customer status updated.');
    }

    public function render()
    {
        $customerTable = (new Customer)->getTable();

        $customers = Customer::query()
            ->select("{$customerTable}.*")
            ->selectSub(
                Invoice::query()
                    ->selectRaw('COALESCE(SUM(total - paid_amount), 0)')
                    ->whereColumn('customer_id', "{$customerTable}.id")
                    ->whereIn('status', ['issued', 'partially_settled', 'overdue']),
                'outstanding',
            )
            ->when($this->search, fn ($q) => $q->where(function ($q): void {
                $q->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('code', 'like', '%' . $this->search . '%')
                    ->orWhere('email', 'like', '%' . $this->search . '%');
            }))
            ->when(!$this->showInactive, fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->paginate(config('accounting.per_page.customers', 20));

        $layout = view()->exists('layouts.app')
        ? 'layouts.app'
        : 'components.layouts.app';

        return view('accounting::livewire.customers', ['customers' => $customers])->layout($layout, ['title' => __('Customers')]);
    }
}
