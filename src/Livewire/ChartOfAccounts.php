<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Livewire;

use Centrex\LaravelAccounting\Models\{Account};
use Livewire\{Component, WithPagination};

class ChartOfAccounts extends Component
{
    use WithPagination;

    public $search = '';

    public $typeFilter = '';

    public $showModal = false;

    // Form fields
    public $accountId;

    public $code;

    public $name;

    public $type;

    public $subtype;

    public $parent_id;

    public $description;

    public $currency = 'USD';

    public $is_active = true;

    public function openModal($id = null): void
    {
        $this->reset(['code', 'name', 'type', 'subtype', 'parent_id', 'description']);

        if ($id) {
            $account = Account::findOrFail($id);
            $this->accountId = $account->id;
            $this->code = $account->code;
            $this->name = $account->name;
            $this->type = $account->type;
            $this->subtype = $account->subtype;
            $this->parent_id = $account->parent_id;
            $this->description = $account->description;
            $this->currency = $account->currency;
            $this->is_active = $account->is_active;
        }

        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'code'     => 'required|unique:accounts,code,' . $this->accountId,
            'name'     => 'required|min:3',
            'type'     => 'required|in:asset,liability,equity,revenue,expense',
            'currency' => 'required|size:3',
        ]);

        if ($this->accountId) {
            $account = Account::findOrFail($this->accountId);
            $account->update([
                'code'        => $this->code,
                'name'        => $this->name,
                'type'        => $this->type,
                'subtype'     => $this->subtype,
                'parent_id'   => $this->parent_id,
                'description' => $this->description,
                'currency'    => $this->currency,
                'is_active'   => $this->is_active,
            ]);
            $message = 'Account updated successfully!';
        } else {
            Account::create([
                'code'        => $this->code,
                'name'        => $this->name,
                'type'        => $this->type,
                'subtype'     => $this->subtype,
                'parent_id'   => $this->parent_id,
                'description' => $this->description,
                'currency'    => $this->currency,
                'is_active'   => $this->is_active,
            ]);
            $message = 'Account created successfully!';
        }

        session()->flash('message', $message);
        $this->showModal = false;
        $this->reset(['accountId', 'code', 'name', 'type']);
    }

    public function toggleStatus($id): void
    {
        $account = Account::findOrFail($id);

        if ($account->is_system) {
            session()->flash('error', 'Cannot deactivate system accounts');

            return;
        }

        $account->update(['is_active' => !$account->is_active]);
        session()->flash('message', 'Account status updated!');
    }

    public function render()
    {
        $accounts = Account::query()
            ->when($this->search, function ($q): void {
                $q->where(function ($query): void {
                    $query->where('code', 'like', '%' . $this->search . '%')
                        ->orWhere('name', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->typeFilter, fn ($q) => $q->where('type', $this->typeFilter))
            ->orderBy('code')
            ->paginate(20);

        $parentAccounts = Account::whereNull('parent_id')
            ->orderBy('code')
            ->get();

        return view('accounting::livewire.chart-of-accounts', [
            'accounts'       => $accounts,
            'parentAccounts' => $parentAccounts,
        ]);
    }
}
