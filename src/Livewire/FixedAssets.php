<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Concerns\WithCurrency;
use Centrex\Accounting\Models\{FixedAsset, JournalEntryLine};
use Livewire\{Component, WithPagination};

class FixedAssets extends Component
{
    use WithCurrency;
    use WithPagination;

    public string $search = '';

    public string $classFilter = '';

    // Create asset form
    public bool $showCreateModal = false;

    public string $name = '';

    public string $asset_class = '';

    public string $acquisition_cost = '';

    public string $salvage_value = '0';

    public string $useful_life_months = '';

    public string $sbu_code = '';

    public string $acquired_at = '';

    public string $location = '';

    public string $serial_number = '';

    public string $notes = '';

    // Capitalize form
    public bool $showCapitalizeModal = false;

    public ?int $capitalizeAssetId = null;

    public string $capitalize_date = '';

    public string $capitalize_reference = '';

    // Dispose form
    public bool $showDisposeModal = false;

    public ?int $disposeAssetId = null;

    public string $dispose_date = '';

    public string $dispose_proceeds = '0';

    public string $dispose_reference = '';

    // Edit form
    public bool $showEditModal = false;

    public ?int $editAssetId = null;

    public bool $editCostLocked = false;

    public string $edit_name = '';

    public string $edit_asset_class = '';

    public string $edit_acquisition_cost = '';

    public string $edit_salvage_value = '';

    public string $edit_useful_life_months = '';

    public string $edit_sbu_code = '';

    public string $edit_location = '';

    public string $edit_serial_number = '';

    public string $edit_notes = '';

    protected array $queryString = ['search', 'classFilter'];

    public function openCreate(): void
    {
        $this->reset([
            'name', 'asset_class', 'acquisition_cost', 'salvage_value',
            'useful_life_months', 'sbu_code', 'acquired_at', 'location', 'serial_number', 'notes',
        ]);
        $this->salvage_value = '0';
        $this->acquired_at = now()->format('Y-m-d');
        $this->showCreateModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'name'               => 'required|string|max:255',
            'acquisition_cost'   => 'required|numeric|min:0.01',
            'salvage_value'      => 'nullable|numeric|min:0',
            'useful_life_months' => 'required|integer|min:1',
            'sbu_code'           => 'nullable|string|max:32',
            'acquired_at'        => 'required|date',
            'asset_class'        => 'nullable|string|max:100',
            'location'           => 'nullable|string|max:255',
            'serial_number'      => 'nullable|string|max:255',
            'notes'              => 'nullable|string',
        ]);

        try {
            app(Accounting::class)->addFixedAsset(
                name: $this->name,
                acquisitionCost: (float) $this->acquisition_cost,
                usefulLifeMonths: (int) $this->useful_life_months,
                salvageValue: $this->salvage_value !== '' ? (float) $this->salvage_value : 0.0,
                acquiredAt: $this->acquired_at ?: null,
                assetClass: $this->asset_class ?: null,
                sbuCode: $this->sbu_code ?: null,
                location: $this->location ?: null,
                serialNumber: $this->serial_number ?: null,
                notes: $this->notes ?: null,
            );

            $this->dispatch('notify', type: 'success', message: 'Fixed asset registered.');
            $this->showCreateModal = false;
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function openCapitalize(int $id): void
    {
        $this->capitalizeAssetId = $id;
        $this->capitalize_date = now()->format('Y-m-d');
        $this->capitalize_reference = 'FA-CAP-' . now()->format('YmdHis');
        $this->showCapitalizeModal = true;
    }

    public function submitCapitalize(): void
    {
        $this->validate([
            'capitalize_date'      => 'required|date',
            'capitalize_reference' => 'required|string|max:255',
        ]);

        $asset = FixedAsset::findOrFail($this->capitalizeAssetId);

        try {
            $entry = app(Accounting::class)->capitalizeFixedAsset(
                $asset,
                $this->capitalize_date,
                $this->capitalize_reference,
            );
            $entry->post();

            $this->dispatch('notify', type: 'success', message: 'Asset capitalized.');
            $this->showCapitalizeModal = false;
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function depreciate(int $id): void
    {
        $asset = FixedAsset::findOrFail($id);

        try {
            $entry = app(Accounting::class)->depreciateAsset($asset);
            $entry?->post();

            $this->dispatch('notify', type: $entry ? 'success' : 'info', message: $entry
                ? 'Depreciation posted for ' . $asset->name . '.'
                : 'Nothing to depreciate — asset is inactive, disposed, or fully depreciated.');
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function openDispose(int $id): void
    {
        $this->disposeAssetId = $id;
        $this->dispose_date = now()->format('Y-m-d');
        $this->dispose_proceeds = '0';
        $this->dispose_reference = 'FA-DISPOSAL-' . now()->format('YmdHis');
        $this->showDisposeModal = true;
    }

    public function submitDispose(): void
    {
        $this->validate([
            'dispose_date'      => 'required|date',
            'dispose_proceeds'  => 'nullable|numeric|min:0',
            'dispose_reference' => 'nullable|string|max:255',
        ]);

        $asset = FixedAsset::findOrFail($this->disposeAssetId);

        try {
            $entry = app(Accounting::class)->disposeAsset(
                $asset,
                $this->dispose_date,
                $this->dispose_proceeds !== '' ? (float) $this->dispose_proceeds : 0.0,
                $this->dispose_reference ?: null,
            );
            $entry->post();

            $this->dispatch('notify', type: 'success', message: 'Asset disposed.');
            $this->showDisposeModal = false;
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function toggleActive(int $id): void
    {
        $asset = FixedAsset::findOrFail($id);
        $asset->update(['is_active' => !$asset->is_active]);

        $this->dispatch('notify', type: 'success', message: $asset->is_active ? 'Asset reactivated.' : 'Asset marked inactive.');
    }

    public function openEdit(int $id): void
    {
        $this->resetValidation();
        $asset = FixedAsset::findOrFail($id);

        $this->editAssetId = $asset->id;
        $this->editCostLocked = $this->hasPostedActivity($asset);
        $this->edit_name = $asset->name;
        $this->edit_asset_class = $asset->asset_class ?? '';
        $this->edit_acquisition_cost = (string) $asset->acquisition_cost;
        $this->edit_salvage_value = (string) $asset->salvage_value;
        $this->edit_useful_life_months = (string) $asset->useful_life_months;
        $this->edit_sbu_code = $asset->sbu_code ?? '';
        $this->edit_location = $asset->location ?? '';
        $this->edit_serial_number = $asset->serial_number ?? '';
        $this->edit_notes = $asset->notes ?? '';
        $this->showEditModal = true;
    }

    public function submitEdit(): void
    {
        $asset = FixedAsset::findOrFail($this->editAssetId);
        $locked = $this->hasPostedActivity($asset);

        $rules = [
            'edit_name'               => 'required|string|max:255',
            'edit_asset_class'        => 'nullable|string|max:100',
            'edit_salvage_value'      => 'nullable|numeric|min:0',
            'edit_useful_life_months' => 'required|integer|min:1',
            'edit_sbu_code'           => 'nullable|string|max:32',
            'edit_location'           => 'nullable|string|max:255',
            'edit_serial_number'      => 'nullable|string|max:255',
            'edit_notes'              => 'nullable|string',
        ];

        if (!$locked) {
            $rules['edit_acquisition_cost'] = 'required|numeric|min:0.01';
        }

        $this->validate($rules);

        $data = [
            'name'               => $this->edit_name,
            'asset_class'        => $this->edit_asset_class ?: null,
            'salvage_value'      => $this->edit_salvage_value !== '' ? (float) $this->edit_salvage_value : 0.0,
            'useful_life_months' => (int) $this->edit_useful_life_months,
            'sbu_code'           => $this->edit_sbu_code ?: null,
            'location'           => $this->edit_location ?: null,
            'serial_number'      => $this->edit_serial_number ?: null,
            'notes'              => $this->edit_notes ?: null,
        ];

        // acquisition_cost is only safe to edit before it's been baked into any posted JE —
        // capitalizeFixedAsset()/depreciateAsset()/disposeAsset() all read it live off this
        // model, so a change after any of those has posted would desync the record from GL
        // history that already reflects the old cost.
        if (!$locked) {
            $data['acquisition_cost'] = (float) $this->edit_acquisition_cost;
        }

        $asset->update($data);

        $this->dispatch('notify', type: 'success', message: 'Fixed asset updated.');
        $this->showEditModal = false;
    }

    /**
     * True once this asset's dedicated GL accounts have any posted activity against them
     * (capitalized and/or depreciated), or the asset has been disposed — at that point
     * acquisition_cost is locked to keep the record consistent with what's already posted.
     */
    private function hasPostedActivity(FixedAsset $asset): bool
    {
        if ($asset->isDisposed()) {
            return true;
        }

        return JournalEntryLine::query()
            ->whereIn('account_id', array_filter([$asset->asset_account_id, $asset->accumulated_depreciation_account_id]))
            ->whereHas('journalEntry', fn ($query) => $query->where('status', 'posted'))
            ->exists();
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $assets = FixedAsset::query()
            ->with(['assetAccount', 'accumulatedDepreciationAccount'])
            ->when($this->search, fn ($q) => $q->where('name', 'like', '%' . $this->search . '%'))
            ->when($this->classFilter, fn ($q) => $q->where('asset_class', $this->classFilter))
            ->orderBy('name')
            ->paginate(config('accounting.per_page.fixed_assets', 15));

        $layout = view()->exists('layouts.app')
        ? 'layouts.app'
        : 'components.layouts.app';

        return view('accounting::livewire.fixed-assets', [
            'assets' => $assets,
        ])->layout($layout, ['title' => __('Fixed Assets')]);
    }
}
