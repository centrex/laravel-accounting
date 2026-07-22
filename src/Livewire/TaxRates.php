<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Models\TaxRate;
use Illuminate\Support\Facades\Gate;
use Livewire\{Component, WithPagination};

class TaxRates extends Component
{
    use WithPagination;

    public string $search = '';

    public bool $showInactive = false;

    public bool $showModal = false;

    public ?int $taxRateId = null;

    public string $name = '';

    public string $code = '';

    public string $rate = '0';

    public bool $is_compound = false;

    public bool $is_active = true;

    protected array $queryString = ['search'];

    public function openModal(?int $id = null): void
    {
        $this->resetValidation();
        $this->reset(['taxRateId', 'name', 'code', 'rate']);
        $this->is_compound = false;
        $this->is_active = true;

        if ($id) {
            $taxRate = TaxRate::findOrFail($id);
            $this->taxRateId = $taxRate->id;
            $this->name = $taxRate->name;
            $this->code = $taxRate->code;
            $this->rate = (string) $taxRate->rate;
            $this->is_compound = $taxRate->is_compound;
            $this->is_active = $taxRate->is_active;
        }

        $this->showModal = true;
    }

    public function save(): void
    {
        if (Gate::denies('accounting.tax-rates.manage')) {
            $this->dispatch('notify', type: 'error', message: 'You are not authorized to manage tax rates.');

            return;
        }

        $prefix = config('accounting.table_prefix', 'acct_');
        $table = $prefix . 'tax_rates';
        $id = $this->taxRateId;

        $this->validate([
            'name' => 'required|min:2',
            'code' => "required|unique:{$table},code,{$id}",
            'rate' => 'required|numeric|min:0|max:100',
        ]);

        $data = [
            'name'        => $this->name,
            'code'        => $this->code,
            'rate'        => $this->rate,
            'is_compound' => $this->is_compound,
            'is_active'   => $this->is_active,
        ];

        if ($this->taxRateId) {
            TaxRate::findOrFail($this->taxRateId)->update($data);
            $this->dispatch('notify', type: 'success', message: 'Tax rate updated!');
        } else {
            TaxRate::create($data);
            $this->dispatch('notify', type: 'success', message: 'Tax rate created!');
        }

        $this->showModal = false;
    }

    public function toggleStatus(int $id): void
    {
        if (Gate::denies('accounting.tax-rates.manage')) {
            $this->dispatch('notify', type: 'error', message: 'You are not authorized to manage tax rates.');

            return;
        }

        $taxRate = TaxRate::findOrFail($id);
        $taxRate->update(['is_active' => !$taxRate->is_active]);
        $this->dispatch('notify', type: 'info', message: 'Tax rate status updated.');
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $taxRates = TaxRate::query()
            ->when($this->search, fn ($q) => $q->where(function ($q): void {
                $q->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('code', 'like', '%' . $this->search . '%');
            }))
            ->when(!$this->showInactive, fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->paginate(config('accounting.per_page.tax_rates', 20));

        $layout = view()->exists('layouts.app')
        ? 'layouts.app'
        : 'components.layouts.app';

        return view('accounting::livewire.tax-rates', ['taxRates' => $taxRates])->layout($layout, ['title' => __('Tax Rates')]);
    }
}
