<div>
<x-tallui-notification />

<x-tallui-page-header title="Vendors" subtitle="Manage your vendor / supplier directory" icon="o-building-storefront">
    <x-slot:actions>
        <x-tallui-toggle wire:model.live="showInactive" label="Show inactive" class="toggle-sm" />
        <x-tallui-button wire:click="openModal()" icon="o-plus" class="btn-primary btn-sm">New Vendor</x-tallui-button>
    </x-slot:actions>
</x-tallui-page-header>

{{-- Search --}}
<x-tallui-card class="mb-4" padding="compact">
    <div class="p-1">
        <x-tallui-form-group label="Search">
            <x-tallui-input wire:model.live.debounce.300ms="search" placeholder="Name, code or email…" class="input-sm" />
        </x-tallui-form-group>
    </div>
</x-tallui-card>

{{-- Vendors Table --}}
<x-tallui-card padding="none">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                    <th class="pl-5">Code</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Currency</th>
                    <th class="text-right">Outstanding</th>
                    <th>Terms</th>
                    <th>Status</th>
                    <th class="pr-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse($vendors as $vendor)
                    <tr class="hover:bg-base-50 {{ !$vendor->is_active ? 'opacity-60' : '' }}">
                        <td class="pl-5 font-mono text-sm font-semibold">{{ $vendor->code }}</td>
                        <td class="text-sm font-medium">{{ $vendor->name }}</td>
                        <td class="text-sm text-base-content/60">{{ $vendor->email ?? '—' }}</td>
                        <td class="text-sm text-base-content/60">{{ $vendor->phone ?? '—' }}</td>
                        <td class="text-sm text-base-content/60">{{ $vendor->currency }}</td>
                        <td class="text-right text-sm font-mono {{ $vendor->outstanding > 0 ? 'text-warning font-semibold' : 'text-base-content/60' }}">
                            {{ number_format($vendor->outstanding, 2) }}
                        </td>
                        <td class="text-sm text-base-content/60">{{ $vendor->payment_terms }}d</td>
                        <td>
                            <x-tallui-badge :type="$vendor->is_active ? 'success' : 'neutral'">
                                {{ $vendor->is_active ? 'Active' : 'Inactive' }}
                            </x-tallui-badge>
                        </td>
                        <td class="pr-5">
                            <div class="flex justify-end gap-1">
                                <x-tallui-button :link="route('accounting.vendors.ledger', $vendor->id)" icon="o-book-open" class="btn-ghost btn-xs" title="Ledger" />
                                <x-tallui-button wire:click="openModal({{ $vendor->id }})" icon="o-pencil" class="btn-ghost btn-xs" />
                                <x-tallui-button wire:click="toggleStatus({{ $vendor->id }})" icon="{{ $vendor->is_active ? 'o-eye-slash' : 'o-eye' }}" class="btn-ghost btn-xs" />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9">
                            <x-tallui-empty-state title="No vendors found" description="Add your first vendor to start tracking bills" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-base-200">{{ $vendors->links() }}</div>
</x-tallui-card>

{{-- Vendor Modal --}}
<x-tallui-modal id="vendor-modal" :title="$vendorId ? 'Edit Vendor' : 'New Vendor'" icon="o-building-storefront" size="lg">
    <x-slot:trigger>
        <span x-effect="if ($wire.showModal) $dispatch('open-modal', 'vendor-modal'); else $dispatch('close-modal', 'vendor-modal')"></span>
    </x-slot:trigger>

    <form wire:submit.prevent="save" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Code *" :error="$errors->first('code')">
                <x-tallui-input wire:model="code" placeholder="VEND-001" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Currency *" :error="$errors->first('currency')">
                <x-tallui-input wire:model="currency" maxlength="3" placeholder="BDT" />
            </x-tallui-form-group>
        </div>

        <x-tallui-form-group label="Name *" :error="$errors->first('name')">
            <x-tallui-input wire:model="name" placeholder="Vendor company name" />
        </x-tallui-form-group>

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Email" :error="$errors->first('email')">
                <x-tallui-input type="email" wire:model="email" placeholder="vendor@example.com" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Phone">
                <x-tallui-input wire:model="phone" />
            </x-tallui-form-group>
        </div>

        <x-tallui-form-group label="Address">
            <x-tallui-textarea wire:model="address" rows="2" />
        </x-tallui-form-group>

        <div class="grid grid-cols-3 gap-4">
            <x-tallui-form-group label="City">
                <x-tallui-input wire:model="city" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Country">
                <x-tallui-input wire:model="country" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Tax ID">
                <x-tallui-input wire:model="tax_id" placeholder="VAT/TIN" />
            </x-tallui-form-group>
        </div>

        <x-tallui-form-group label="Payment Terms (days)">
            <x-tallui-input type="number" wire:model="payment_terms" class="text-right" />
        </x-tallui-form-group>

        <x-tallui-toggle wire:model="is_active" label="Active vendor" />
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="save" spinner="save" class="btn-primary">
            {{ $vendorId ? 'Update' : 'Create' }} Vendor
        </x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>
</div>
