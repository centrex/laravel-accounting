<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Concerns\ShowsAuditTrail;
use Centrex\Accounting\Models\Owner;
use Livewire\{Component, WithPagination};

class Owners extends Component
{
    use ShowsAuditTrail;
    use WithPagination;

    public string $search = '';

    public bool $showInactive = false;

    public bool $showModal = false;

    // Form fields
    public ?int $ownerId = null;

    public string $code = '';

    public string $name = '';

    public string $email = '';

    public string $ownership_percentage = '';

    public string $notes = '';

    public bool $is_active = true;

    protected array $queryString = ['search'];

    public function openModal(?int $id = null): void
    {
        $this->resetValidation();
        $this->reset(['ownerId', 'code', 'name', 'email', 'ownership_percentage', 'notes']);
        $this->is_active = true;

        if ($id) {
            $owner = Owner::findOrFail($id);
            $this->ownerId = $owner->id;
            $this->code = $owner->code;
            $this->name = $owner->name;
            $this->email = $owner->email ?? '';
            $this->ownership_percentage = (string) ($owner->ownership_percentage ?? '');
            $this->notes = $owner->notes ?? '';
            $this->is_active = $owner->is_active;
        }

        $this->showModal = true;
    }

    public function save(): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $table = $prefix . 'owners';
        $id = $this->ownerId;

        $this->validate([
            'code'                 => "required|unique:{$table},code,{$id}",
            'name'                 => 'required|min:2',
            'email'                => 'nullable|email',
            'ownership_percentage' => 'nullable|numeric|min:0|max:100',
        ]);

        $data = [
            'name'                 => $this->name,
            'email'                => $this->email ?: null,
            'ownership_percentage' => $this->ownership_percentage !== '' ? (float) $this->ownership_percentage : null,
            'notes'                => $this->notes ?: null,
            'is_active'            => $this->is_active,
        ];

        if ($this->ownerId) {
            // code/capital_account_id/drawings_account_id are immutable after creation —
            // changing them would orphan the historical journal lines already posted
            // against those sub-accounts.
            Owner::findOrFail($this->ownerId)->update($data);
            $this->dispatch('notify', type: 'success', message: 'Owner updated!');
        } else {
            app(Accounting::class)->addOwner(['code' => $this->code, ...$data]);
            $this->dispatch('notify', type: 'success', message: 'Owner created — Capital and Drawings sub-accounts provisioned.');
        }

        $this->showModal = false;
    }

    public function toggleStatus(int $id): void
    {
        $owner = Owner::findOrFail($id);
        $owner->update(['is_active' => !$owner->is_active]);
        $this->dispatch('notify', type: 'info', message: 'Owner status updated.');
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $owners = Owner::query()
            ->with(['capitalAccount', 'drawingsAccount'])
            ->when($this->search, fn ($q) => $q->where(function ($q): void {
                $q->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('code', 'like', '%' . $this->search . '%')
                    ->orWhere('email', 'like', '%' . $this->search . '%');
            }))
            ->when(!$this->showInactive, fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->paginate(config('accounting.per_page.owners', 20));

        $layout = view()->exists('layouts.app')
        ? 'layouts.app'
        : 'components.layouts.app';

        return view('accounting::livewire.owners', ['owners' => $owners])->layout($layout, ['title' => __('Owners')]);
    }
}
