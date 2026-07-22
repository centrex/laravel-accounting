<div>
<x-tallui-notification />

<x-tallui-page-header title="Tax Rates" subtitle="Manage the tax rates available on invoice and bill line items" icon="o-receipt-percent">
    <x-slot:actions>
        <x-tallui-toggle wire:model.live="showInactive" label="Show inactive" class="toggle-sm" />
        <x-tallui-button wire:click="openModal()" icon="o-plus" class="btn-primary btn-sm">New Tax Rate</x-tallui-button>
    </x-slot:actions>
</x-tallui-page-header>

{{-- Search --}}
<x-tallui-card class="mb-4" padding="compact">
    <div class="p-1">
        <x-tallui-form-group label="Search">
            <x-tallui-input wire:model.live.debounce.300ms="search" placeholder="Name or code…" class="input-sm" />
        </x-tallui-form-group>
    </div>
</x-tallui-card>

{{-- Tax Rates Table --}}
<x-tallui-card padding="none">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                    <th class="pl-5">Code</th>
                    <th>Name</th>
                    <th class="text-right">Rate</th>
                    <th>Compound</th>
                    <th>Status</th>
                    <th class="pr-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse($taxRates as $taxRate)
                    <tr class="even:bg-base-200/50 hover:bg-base-200 {{ !$taxRate->is_active ? 'opacity-60' : '' }}">
                        <td class="pl-5 font-mono text-sm font-semibold">{{ $taxRate->code }}</td>
                        <td class="text-sm font-medium">{{ $taxRate->name }}</td>
                        <td class="text-right text-sm font-mono">{{ number_format($taxRate->rate, 2) }}%</td>
                        <td>
                            <x-tallui-badge :type="$taxRate->is_compound ? 'info' : 'neutral'">
                                {{ $taxRate->is_compound ? 'Yes' : 'No' }}
                            </x-tallui-badge>
                        </td>
                        <td>
                            <x-tallui-badge :type="$taxRate->is_active ? 'success' : 'neutral'">
                                {{ $taxRate->is_active ? 'Active' : 'Inactive' }}
                            </x-tallui-badge>
                        </td>
                        <td class="pr-5">
                            <div class="flex justify-end gap-1">
                                <x-tallui-button wire:click="openModal({{ $taxRate->id }})" icon="o-pencil" class="btn-ghost btn-xs" />
                                <x-tallui-button wire:click="toggleStatus({{ $taxRate->id }})" icon="{{ $taxRate->is_active ? 'o-eye-slash' : 'o-eye' }}" class="btn-ghost btn-xs" />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-tallui-empty-state title="No tax rates found" description="Add your first tax rate to use it on invoices and bills" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-base-200">{{ $taxRates->links() }}</div>
</x-tallui-card>

{{-- Tax Rate Modal --}}
<x-tallui-modal id="tax-rate-modal" :title="$taxRateId ? 'Edit Tax Rate' : 'New Tax Rate'" icon="o-receipt-percent" size="md">
    <x-slot:trigger>
        <span
            x-effect="if ($wire.showModal) $dispatch('open-modal', 'tax-rate-modal'); else $dispatch('close-modal', 'tax-rate-modal')"
            @modal-closed.window="if ($event.detail === 'tax-rate-modal') $wire.showModal = false"
        ></span>
    </x-slot:trigger>

    <form wire:submit.prevent="save" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Code *" :error="$errors->first('code')">
                <x-tallui-input wire:model="code" placeholder="VAT" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Rate % *" :error="$errors->first('rate')">
                <x-tallui-input type="number" step="0.01" wire:model="rate" class="text-right" />
            </x-tallui-form-group>
        </div>

        <x-tallui-form-group label="Name *" :error="$errors->first('name')">
            <x-tallui-input wire:model="name" placeholder="VAT Standard" />
        </x-tallui-form-group>

        <x-tallui-toggle wire:model="is_compound" label="Compound (applies on top of previously taxed amounts)" />
        <x-tallui-toggle wire:model="is_active" label="Active" />
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="save" spinner="save" class="btn-primary">
            {{ $taxRateId ? 'Update' : 'Create' }} Tax Rate
        </x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>
</div>
