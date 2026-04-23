<div>
<x-tallui-notification />

<x-tallui-page-header title="Customers" subtitle="Manage your customer directory" icon="o-user-group">
    <x-slot:actions>
        <x-tallui-toggle wire:model.live="showInactive" label="Show inactive" class="toggle-sm" />
        <x-tallui-button wire:click="openModal()" icon="o-plus" class="btn-primary btn-sm">New Customer</x-tallui-button>
    </x-slot:actions>
</x-tallui-page-header>

{{-- Search --}}
<x-tallui-card class="mb-4" padding="compact">
    <div class="flex gap-3 items-end p-1">
        <div class="flex-1">
            <x-tallui-form-group label="Search">
                <x-tallui-input wire:model.live.debounce.300ms="search" placeholder="Name, code or email…" class="input-sm" />
            </x-tallui-form-group>
        </div>
    </div>
</x-tallui-card>

{{-- Customers Table --}}
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
                    <th class="text-right">Credit Limit</th>
                    <th class="text-right">Outstanding</th>
                    <th>Terms</th>
                    <th>Status</th>
                    <th class="pr-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse($customers as $customer)
                    <tr class="hover:bg-base-50 {{ !$customer->is_active ? 'opacity-60' : '' }}">
                        <td class="pl-5 font-mono text-sm font-semibold">{{ $customer->code }}</td>
                        <td class="text-sm font-medium">{{ $customer->name }}</td>
                        <td class="text-sm text-base-content/60">{{ $customer->email ?? '—' }}</td>
                        <td class="text-sm text-base-content/60">{{ $customer->phone ?? '—' }}</td>
                        <td class="text-sm text-base-content/60">{{ $customer->currency }}</td>
                        <td class="text-right text-sm font-mono">{{ number_format($customer->credit_limit, 2) }}</td>
                        <td class="text-right text-sm font-mono {{ $customer->outstanding > 0 ? 'text-warning font-semibold' : 'text-base-content/60' }}">
                            {{ number_format($customer->outstanding, 2) }}
                        </td>
                        <td class="text-sm text-base-content/60">{{ $customer->payment_terms }}d</td>
                        <td>
                            <x-tallui-badge :type="$customer->is_active ? 'success' : 'neutral'">
                                {{ $customer->is_active ? 'Active' : 'Inactive' }}
                            </x-tallui-badge>
                        </td>
                        <td class="pr-5">
                            <div class="flex justify-end gap-1">
                                <x-tallui-button :link="route('accounting.customers.ledger', $customer->id)" icon="o-book-open" class="btn-ghost btn-xs" title="Ledger" />
                                <x-tallui-button wire:click="openModal({{ $customer->id }})" icon="o-pencil" class="btn-ghost btn-xs" />
                                <x-tallui-button wire:click="toggleStatus({{ $customer->id }})" icon="{{ $customer->is_active ? 'o-eye-slash' : 'o-eye' }}" class="btn-ghost btn-xs" />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10">
                            <x-tallui-empty-state title="No customers found" description="Add your first customer to start invoicing" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-base-200">{{ $customers->links() }}</div>
</x-tallui-card>

{{-- Customer Modal --}}
<x-tallui-modal id="customer-modal" :title="$customerId ? 'Edit Customer' : 'New Customer'" icon="o-user-group" size="lg">
    <x-slot:trigger>
        <span x-effect="if ($wire.showModal) $dispatch('open-modal', 'customer-modal'); else $dispatch('close-modal', 'customer-modal')"></span>
    </x-slot:trigger>

    <form wire:submit.prevent="save" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Code *" :error="$errors->first('code')">
                <x-tallui-input wire:model="code" placeholder="CUST-001" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Currency *" :error="$errors->first('currency')">
                <x-tallui-input wire:model="currency" maxlength="3" placeholder="BDT" />
            </x-tallui-form-group>
        </div>

        <x-tallui-form-group label="Name *" :error="$errors->first('name')">
            <x-tallui-input wire:model="name" placeholder="Customer name" />
        </x-tallui-form-group>

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Email" :error="$errors->first('email')">
                <x-tallui-input type="email" wire:model="email" placeholder="contact@example.com" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Phone">
                <x-tallui-input wire:model="phone" placeholder="+880…" />
            </x-tallui-form-group>
        </div>

        <x-tallui-form-group label="Address">
            <x-tallui-textarea wire:model="address" rows="2" placeholder="Street address…" />
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

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Credit Limit" :error="$errors->first('credit_limit')">
                <x-tallui-input type="number" step="0.01" wire:model="credit_limit" class="text-right" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Payment Terms (days)">
                <x-tallui-input type="number" wire:model="payment_terms" class="text-right" />
            </x-tallui-form-group>
        </div>

        <x-tallui-toggle wire:model="is_active" label="Active customer" />
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="save" spinner="save" class="btn-primary">
            {{ $customerId ? 'Update' : 'Create' }} Customer
        </x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>
</div>
