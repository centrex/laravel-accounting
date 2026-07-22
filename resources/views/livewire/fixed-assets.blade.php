<div>
<x-tallui-notification />

<x-tallui-page-header title="Fixed Assets" subtitle="Asset register, capitalization, depreciation and disposal" icon="o-cube">
    <x-slot:actions>
        @can('accounting.fixed-assets.manage')
            <x-tallui-button wire:click="openCreate" icon="o-plus" class="btn-primary btn-sm">New Fixed Asset</x-tallui-button>
        @endcan
    </x-slot:actions>
</x-tallui-page-header>

{{-- Filters --}}
<x-tallui-card class="mb-4" padding="compact">
    <div class="flex flex-wrap gap-3 items-end p-1">
        <div class="flex-1 min-w-52">
            <x-tallui-form-group label="Search">
                <x-tallui-input wire:model.live.debounce.300ms="search" placeholder="Asset name…" class="input-sm" />
            </x-tallui-form-group>
        </div>
        <div class="w-48">
            <x-tallui-form-group label="Class">
                <x-tallui-input wire:model.live.debounce.300ms="classFilter" placeholder="e.g. vehicle" class="input-sm" />
            </x-tallui-form-group>
        </div>
    </div>
</x-tallui-card>

{{-- Assets Table --}}
<x-tallui-card padding="none">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                    <th class="pl-5">Asset</th>
                    <th>Class</th>
                    <th class="text-right">Cost</th>
                    <th class="text-right">Accum. Depreciation</th>
                    <th class="text-right">Net Book Value</th>
                    <th>Status</th>
                    <th class="pr-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse($assets as $asset)
                    <tr class="even:bg-base-200/50 hover:bg-base-200">
                        <td class="pl-5">
                            <div class="text-sm font-semibold">{{ $asset->name }}</div>
                            <div class="text-xs text-base-content/50">{{ $asset->asset_code }}</div>
                        </td>
                        <td class="text-sm text-base-content/70">{{ $asset->asset_class ? str($asset->asset_class)->replace('_', ' ')->title() : '—' }}</td>
                        <td class="text-right text-sm font-mono">{{ number_format((float) $asset->acquisition_cost, 2) }}</td>
                        <td class="text-right text-sm font-mono text-base-content/70">{{ number_format($asset->accumulatedDepreciation(), 2) }}</td>
                        <td class="text-right text-sm font-mono font-medium">{{ number_format($asset->netBookValue(), 2) }}</td>
                        <td>
                            @if($asset->isDisposed())
                                <x-tallui-badge type="ghost" size="sm">Disposed</x-tallui-badge>
                            @else
                                <x-tallui-badge type="{{ $asset->is_active ? 'success' : 'ghost' }}" size="sm">
                                    {{ $asset->is_active ? 'Active' : 'Inactive' }}
                                </x-tallui-badge>
                            @endif
                        </td>
                        <td class="pr-5">
                            @can('accounting.fixed-assets.manage')
                                @if(!$asset->isDisposed())
                                    <div class="flex justify-end flex-wrap gap-1">
                                        <x-tallui-button wire:click="openCapitalize({{ $asset->id }})" class="btn-ghost btn-xs">Capitalize</x-tallui-button>
                                        <x-tallui-button wire:click="depreciate({{ $asset->id }})" wire:confirm="Post this month's depreciation for {{ $asset->name }}?" class="btn-ghost btn-xs">Depreciate</x-tallui-button>
                                        <x-tallui-button wire:click="openDispose({{ $asset->id }})" class="btn-ghost btn-xs">Dispose</x-tallui-button>
                                        <x-tallui-button wire:click="toggleActive({{ $asset->id }})" wire:confirm="{{ $asset->is_active ? 'Mark this asset inactive?' : 'Reactivate this asset?' }}" icon="{{ $asset->is_active ? 'o-pause' : 'o-play' }}" class="btn-ghost btn-xs" title="{{ $asset->is_active ? 'Mark Inactive' : 'Reactivate' }}" />
                                    </div>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <x-tallui-empty-state title="No fixed assets yet" description="Register an asset to start tracking capitalization and depreciation" icon="o-cube">
                                @can('accounting.fixed-assets.manage')
                                    <x-tallui-button wire:click="openCreate" icon="o-plus" class="btn-primary">New Fixed Asset</x-tallui-button>
                                @endcan
                            </x-tallui-empty-state>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-base-200">{{ $assets->links() }}</div>
</x-tallui-card>

{{-- Create Asset Modal --}}
<x-tallui-modal id="fixed-asset-modal" title="New Fixed Asset" icon="o-cube" size="lg">
    <x-slot:trigger>
        <span
            x-effect="if ($wire.showCreateModal) $dispatch('open-modal', 'fixed-asset-modal'); else $dispatch('close-modal', 'fixed-asset-modal')"
            @modal-closed.window="if ($event.detail === 'fixed-asset-modal') $wire.showCreateModal = false"
        ></span>
    </x-slot:trigger>

    <form wire:submit.prevent="save" class="space-y-4">
        <x-tallui-form-group label="Asset Name *" :error="$errors->first('name')">
            <x-tallui-input wire:model="name" placeholder="e.g., Dell Latitude Laptops (Batch 3)" />
        </x-tallui-form-group>

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Asset Class" :error="$errors->first('asset_class')">
                <x-tallui-input wire:model="asset_class" placeholder="e.g. computer_equipment" />
            </x-tallui-form-group>
            <x-tallui-form-group label="SBU Code" :error="$errors->first('sbu_code')">
                <x-tallui-input wire:model="sbu_code" placeholder="Optional" />
            </x-tallui-form-group>
        </div>

        <div class="grid grid-cols-3 gap-4">
            <x-tallui-form-group label="Acquisition Cost *" :error="$errors->first('acquisition_cost')">
                <x-tallui-input type="number" step="0.01" wire:model="acquisition_cost" class="text-right" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Salvage Value" :error="$errors->first('salvage_value')">
                <x-tallui-input type="number" step="0.01" wire:model="salvage_value" class="text-right" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Useful Life (months) *" :error="$errors->first('useful_life_months')">
                <x-tallui-input type="number" wire:model="useful_life_months" />
            </x-tallui-form-group>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Acquired On *" :error="$errors->first('acquired_at')">
                <x-tallui-input type="date" wire:model="acquired_at" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Location" :error="$errors->first('location')">
                <x-tallui-input wire:model="location" placeholder="Optional" />
            </x-tallui-form-group>
        </div>

        <x-tallui-form-group label="Serial Number" :error="$errors->first('serial_number')">
            <x-tallui-input wire:model="serial_number" placeholder="Optional" />
        </x-tallui-form-group>

        <x-tallui-form-group label="Notes" :error="$errors->first('notes')">
            <x-tallui-form-textarea wire:model="notes" :rows="2" />
        </x-tallui-form-group>
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showCreateModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="save" spinner="save" class="btn-primary">Save Asset</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>

{{-- Capitalize Modal --}}
<x-tallui-modal id="fixed-asset-capitalize-modal" title="Capitalize Asset" icon="o-cube" size="md">
    <x-slot:trigger>
        <span
            x-effect="if ($wire.showCapitalizeModal) $dispatch('open-modal', 'fixed-asset-capitalize-modal'); else $dispatch('close-modal', 'fixed-asset-capitalize-modal')"
            @modal-closed.window="if ($event.detail === 'fixed-asset-capitalize-modal') $wire.showCapitalizeModal = false"
        ></span>
    </x-slot:trigger>

    <form wire:submit.prevent="submitCapitalize" class="space-y-4">
        <p class="text-sm text-base-content/60">Records the acquisition outlay: debits the asset's own GL account, credits Bank.</p>

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Date *" :error="$errors->first('capitalize_date')">
                <x-tallui-input type="date" wire:model="capitalize_date" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Reference *" :error="$errors->first('capitalize_reference')">
                <x-tallui-input wire:model="capitalize_reference" />
            </x-tallui-form-group>
        </div>
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showCapitalizeModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="submitCapitalize" spinner="save" class="btn-primary">Capitalize</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>

{{-- Dispose Modal --}}
<x-tallui-modal id="fixed-asset-dispose-modal" title="Dispose Asset" icon="o-cube" size="md">
    <x-slot:trigger>
        <span
            x-effect="if ($wire.showDisposeModal) $dispatch('open-modal', 'fixed-asset-dispose-modal'); else $dispatch('close-modal', 'fixed-asset-dispose-modal')"
            @modal-closed.window="if ($event.detail === 'fixed-asset-dispose-modal') $wire.showDisposeModal = false"
        ></span>
    </x-slot:trigger>

    <form wire:submit.prevent="submitDispose" class="space-y-4">
        <p class="text-sm text-base-content/60">Removes the asset and its accumulated depreciation from the GL, records any proceeds, and posts the resulting gain or loss.</p>

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Date *" :error="$errors->first('dispose_date')">
                <x-tallui-input type="date" wire:model="dispose_date" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Proceeds" :error="$errors->first('dispose_proceeds')">
                <x-tallui-input type="number" step="0.01" wire:model="dispose_proceeds" class="text-right" />
            </x-tallui-form-group>
        </div>

        <x-tallui-form-group label="Reference" :error="$errors->first('dispose_reference')">
            <x-tallui-input wire:model="dispose_reference" placeholder="Optional" />
        </x-tallui-form-group>
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showDisposeModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="submitDispose" spinner="save" class="btn-primary">Dispose</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>
</div>
