<div>
<x-tallui-notification />

<x-tallui-page-header title="Owners" subtitle="Owners/partners and their per-owner Capital &amp; Drawings sub-accounts" icon="o-user-circle">
    <x-slot:actions>
        <x-tallui-toggle wire:model.live="showInactive" label="Show inactive" class="toggle-sm" />
        <x-tallui-button :link="route('accounting.equity')" icon="o-arrow-left" class="btn-ghost btn-sm">Back to Equity</x-tallui-button>
        <x-tallui-button wire:click="openModal()" icon="o-plus" class="btn-primary btn-sm">New Owner</x-tallui-button>
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

{{-- Owners Table --}}
<x-tallui-card padding="none">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                    <th class="pl-5">Code</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th class="text-right">Ownership %</th>
                    <th class="text-right">Capital</th>
                    <th class="text-right">Drawings</th>
                    <th class="text-right">Net Equity</th>
                    <th>Status</th>
                    <th class="pr-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse($owners as $owner)
                    <tr class="even:bg-base-200/50 hover:bg-base-200 {{ !$owner->is_active ? 'opacity-60' : '' }}">
                        <td class="pl-5 font-mono text-sm font-semibold">{{ $owner->code }}</td>
                        <td class="text-sm font-medium">{{ $owner->name }}</td>
                        <td class="text-sm text-base-content/60">{{ $owner->email ?? '—' }}</td>
                        <td class="text-right text-sm font-mono">{{ $owner->ownership_percentage !== null ? number_format($owner->ownership_percentage, 2) . '%' : '—' }}</td>
                        <td class="text-right text-sm font-mono">{{ number_format($owner->capitalAccount->getCurrentBalance(), 2) }}</td>
                        <td class="text-right text-sm font-mono">{{ number_format($owner->drawingsAccount->getCurrentBalance(), 2) }}</td>
                        <td class="text-right text-sm font-mono font-semibold">{{ number_format($owner->equityBalance(), 2) }}</td>
                        <td>
                            <x-tallui-badge :type="$owner->is_active ? 'success' : 'neutral'">
                                {{ $owner->is_active ? 'Active' : 'Inactive' }}
                            </x-tallui-badge>
                        </td>
                        <td class="pr-5">
                            <div class="flex justify-end gap-1">
                                <x-tallui-button wire:click="openAuditTrail({{ \Illuminate\Support\Js::from($owner::class) }}, {{ $owner->getKey() }}, {{ \Illuminate\Support\Js::from($owner->name) }})" icon="o-clock" class="btn-ghost btn-xs" title="Audit trail" />
                                <x-tallui-button wire:click="openModal({{ $owner->id }})" icon="o-pencil" class="btn-ghost btn-xs" />
                                <x-tallui-button wire:click="toggleStatus({{ $owner->id }})" icon="{{ $owner->is_active ? 'o-eye-slash' : 'o-eye' }}" class="btn-ghost btn-xs" />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9">
                            <x-tallui-empty-state title="No owners yet" description="Add an owner to start tracking their capital and drawings separately from the aggregate account" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-base-200">{{ $owners->links() }}</div>
</x-tallui-card>

{{-- Owner Modal --}}
<x-tallui-modal id="owner-modal" :title="$ownerId ? 'Edit Owner' : 'New Owner'" icon="o-user-circle" size="md">
    <x-slot:trigger>
        <span
            x-effect="if ($wire.showModal) $dispatch('open-modal', 'owner-modal'); else $dispatch('close-modal', 'owner-modal')"
            @modal-closed.window="if ($event.detail === 'owner-modal') $wire.showModal = false"
        ></span>
    </x-slot:trigger>

    <form wire:submit.prevent="save" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Code *" :error="$errors->first('code')">
                <x-tallui-input wire:model="code" placeholder="ALICE" :disabled="(bool) $ownerId" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Ownership %" :error="$errors->first('ownership_percentage')">
                <x-tallui-input type="number" step="0.01" wire:model="ownership_percentage" class="text-right" placeholder="50.00" />
            </x-tallui-form-group>
        </div>
        @if ($ownerId)
            <p class="text-xs text-base-content/50 -mt-2">Code and linked GL sub-accounts can't be changed after creation — historical journal entries reference them.</p>
        @endif

        <x-tallui-form-group label="Name *" :error="$errors->first('name')">
            <x-tallui-input wire:model="name" placeholder="Owner / partner name" />
        </x-tallui-form-group>

        <x-tallui-form-group label="Email" :error="$errors->first('email')">
            <x-tallui-input type="email" wire:model="email" placeholder="owner@example.com" />
        </x-tallui-form-group>

        <x-tallui-form-group label="Notes">
            <x-tallui-textarea wire:model="notes" rows="2" placeholder="Optional" />
        </x-tallui-form-group>

        <x-tallui-toggle wire:model="is_active" label="Active owner" />
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="save" spinner="save" class="btn-primary">
            {{ $ownerId ? 'Update' : 'Create' }} Owner
        </x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>
@include('accounting::livewire.partials.audit-trail-modal')
</div>
