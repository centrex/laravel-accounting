<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Livewire;

use Centrex\LaravelAccounting\Models\Vendor;
use Livewire\{Component, WithPagination};

class Vendors extends Component
{
    use WithPagination;

    public string $search = '';

    public bool $showInactive = false;

    public bool $showModal = false;

    public ?int $vendorId = null;

    public string $code = '';

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $address = '';

    public string $city = '';

    public string $country = '';

    public string $tax_id = '';

    public string $currency = '';

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
        $this->reset(['vendorId', 'code', 'name', 'email', 'phone', 'address', 'city', 'country', 'tax_id']);
        $this->currency = config('accounting.base_currency', 'BDT');
        $this->payment_terms = 30;
        $this->is_active = true;

        if ($id) {
            $vendor = Vendor::findOrFail($id);
            $this->vendorId = $vendor->id;
            $this->code = $vendor->code;
            $this->name = $vendor->name;
            $this->email = $vendor->email ?? '';
            $this->phone = $vendor->phone ?? '';
            $this->address = $vendor->address ?? '';
            $this->city = $vendor->city ?? '';
            $this->country = $vendor->country ?? '';
            $this->tax_id = $vendor->tax_id ?? '';
            $this->currency = $vendor->currency;
            $this->payment_terms = $vendor->payment_terms;
            $this->is_active = $vendor->is_active;
        }

        $this->showModal = true;
    }

    public function save(): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $table = $prefix . 'vendors';
        $id = $this->vendorId;

        $this->validate([
            'code'          => "required|unique:{$table},code,{$id}",
            'name'          => 'required|min:2',
            'email'         => 'nullable|email',
            'currency'      => 'required|size:3',
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
            'payment_terms' => $this->payment_terms,
            'is_active'     => $this->is_active,
        ];

        if ($this->vendorId) {
            Vendor::findOrFail($this->vendorId)->update($data);
            $this->dispatch('notify', type: 'success', message: 'Vendor updated!');
        } else {
            Vendor::create($data);
            $this->dispatch('notify', type: 'success', message: 'Vendor created!');
        }

        $this->showModal = false;
    }

    public function toggleStatus(int $id): void
    {
        $vendor = Vendor::findOrFail($id);
        $vendor->update(['is_active' => !$vendor->is_active]);
        $this->dispatch('notify', type: 'info', message: 'Vendor status updated.');
    }

    public function render()
    {
        $vendors = Vendor::query()
            ->when($this->search, fn ($q) => $q->where(function ($q): void {
                $q->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('code', 'like', '%' . $this->search . '%')
                    ->orWhere('email', 'like', '%' . $this->search . '%');
            }))
            ->when(!$this->showInactive, fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->paginate(config('accounting.per_page.vendors', 20));

        $layout = view()->exists('layouts.app')
        ? 'layouts.app'
        : 'components.layouts.app';

        return view('accounting::livewire.vendors', ['vendors' => $vendors])->layout($layout, ['title' => __('Vendors')]);
    }
}
