<div>
<x-tallui-notification />

<x-tallui-page-header title="Chart of Accounts" subtitle="Manage your general ledger accounts" icon="heroicon-o-rectangle-stack">
    <x-slot:actions>
        <x-tallui-button wire:click="openModal()" icon="heroicon-o-plus" class="btn-primary btn-sm">New Account</x-tallui-button>
    </x-slot:actions>
</x-tallui-page-header>

{{-- Filters --}}
<x-tallui-card class="mb-4" padding="compact">
    <div class="flex flex-wrap gap-3 items-end p-1">
        <div class="flex-1 min-w-52">
            <x-tallui-form-group label="Search">
                <x-tallui-input wire:model.live.debounce.300ms="search" placeholder="Code or name…" class="input-sm" />
            </x-tallui-form-group>
        </div>
        <div class="w-48">
            <x-tallui-form-group label="Account Type">
                <x-tallui-select wire:model.live="typeFilter" class="select-sm">
                    <option value="">All Types</option>
                    <option value="asset">Asset</option>
                    <option value="liability">Liability</option>
                    <option value="equity">Equity</option>
                    <option value="revenue">Revenue</option>
                    <option value="expense">Expense</option>
                </x-tallui-select>
            </x-tallui-form-group>
        </div>
        @if($typeFilter || $search)
            <x-tallui-button wire:click="$set('typeFilter', ''); $set('search', '')" class="btn-ghost btn-sm mb-0.5">Clear</x-tallui-button>
        @endif
    </div>
</x-tallui-card>

{{-- Accounts Table --}}
<x-tallui-card padding="none">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                    <th class="pl-5">Code</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Subtype</th>
                    <th>Currency</th>
                    <th>Status</th>
                    <th class="pr-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse($accounts as $account)
                    <tr class="hover:bg-base-50">
                        <td class="pl-5">
                            <span class="font-mono text-sm text-primary font-semibold">{{ $account->code }}</span>
                            @if($account->is_system)
                                <x-tallui-badge type="info" class="ml-1 badge-xs">System</x-tallui-badge>
                            @endif
                        </td>
                        <td>
                            <div class="text-sm font-medium">{{ $account->name }}</div>
                            @if($account->description)
                                <div class="text-xs text-base-content/40 truncate max-w-xs">{{ $account->description }}</div>
                            @endif
                        </td>
                        <td>
                            <x-tallui-badge :type="match($account->type->value ?? $account->type) {
                                'asset'     => 'success',
                                'liability' => 'error',
                                'equity'    => 'info',
                                'revenue'   => 'success',
                                'expense'   => 'warning',
                                default     => 'neutral',
                            }">
                                {{ ucfirst($account->type->value ?? $account->type) }}
                            </x-tallui-badge>
                        </td>
                        <td class="text-sm text-base-content/60">
                            {{ $account->subtype ? ucwords(str_replace('_', ' ', $account->subtype->value ?? $account->subtype)) : '—' }}
                        </td>
                        <td class="text-sm text-base-content/60">{{ $account->currency }}</td>
                        <td>
                            <button wire:click="toggleStatus({{ $account->id }})"
                                class="text-sm {{ $account->is_active ? 'text-success' : 'text-base-content/30' }} hover:underline">
                                {{ $account->is_active ? 'Active' : 'Inactive' }}
                            </button>
                        </td>
                        <td class="pr-5 text-right">
                            <x-tallui-button wire:click="openModal({{ $account->id }})" icon="o-pencil" class="btn-ghost btn-xs" />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <x-tallui-empty-state title="No accounts found" description="Create your first account or adjust your filters" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-base-200">{{ $accounts->links() }}</div>
</x-tallui-card>

{{-- Create/Edit Modal --}}
<x-tallui-modal id="account-modal" :title="$accountId ? 'Edit Account' : 'New Account'" icon="heroicon-o-rectangle-stack" size="lg">
    <x-slot:trigger>
        <span x-effect="if ($wire.showModal) $dispatch('open-modal', 'account-modal'); else $dispatch('close-modal', 'account-modal')"></span>
    </x-slot:trigger>

    <form wire:submit.prevent="save" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Account Code *" :error="$errors->first('code')">
                <x-tallui-input wire:model="code" placeholder="e.g. 1000" class="{{ $errors->has('code') ? 'input-error' : '' }}" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Currency *" :error="$errors->first('currency')">
                <x-tallui-input wire:model="currency" placeholder="BDT" maxlength="3" class="{{ $errors->has('currency') ? 'input-error' : '' }}" />
            </x-tallui-form-group>
        </div>

        <x-tallui-form-group label="Account Name *" :error="$errors->first('name')">
            <x-tallui-input wire:model="name" placeholder="Account name" class="{{ $errors->has('name') ? 'input-error' : '' }}" />
        </x-tallui-form-group>

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Type *" :error="$errors->first('type')">
                <x-tallui-select wire:model.live="type" class="{{ $errors->has('type') ? 'select-error' : '' }}">
                    <option value="">Select Type</option>
                    <option value="asset">Asset</option>
                    <option value="liability">Liability</option>
                    <option value="equity">Equity</option>
                    <option value="revenue">Revenue</option>
                    <option value="expense">Expense</option>
                </x-tallui-select>
            </x-tallui-form-group>
            <x-tallui-form-group label="Subtype">
                <x-tallui-select wire:model="subtype">
                    <option value="">Select Subtype</option>
                    @if($type === 'asset')
                        <option value="current_asset">Current Asset</option>
                        <option value="fixed_asset">Fixed Asset</option>
                        <option value="other_asset">Other Asset</option>
                    @elseif($type === 'liability')
                        <option value="current_liability">Current Liability</option>
                        <option value="long_term_liability">Long-term Liability</option>
                    @elseif($type === 'equity')
                        <option value="equity">Equity</option>
                    @elseif($type === 'revenue')
                        <option value="operating_revenue">Operating Revenue</option>
                        <option value="non_operating_revenue">Non-operating Revenue</option>
                    @elseif($type === 'expense')
                        <option value="cost_of_goods_sold">Cost of Goods Sold</option>
                        <option value="operating_expense">Operating Expense</option>
                        <option value="non_operating_expense">Non-operating Expense</option>
                    @endif
                </x-tallui-select>
            </x-tallui-form-group>
        </div>

        <x-tallui-form-group label="Parent Account">
            <x-tallui-select wire:model="parent_id">
                <option value="">None (Top Level)</option>
                @foreach($parentAccounts as $parent)
                    <option value="{{ $parent->id }}">{{ $parent->code }} — {{ $parent->name }}</option>
                @endforeach
            </x-tallui-select>
        </x-tallui-form-group>

        <x-tallui-form-group label="Description">
            <x-tallui-textarea wire:model="description" rows="2" />
        </x-tallui-form-group>

        <x-tallui-toggle wire:model="is_active" label="Active account" />
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="save" spinner="save" class="btn-primary">
            {{ $accountId ? 'Update' : 'Create' }} Account
        </x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>

<script>
    document.addEventListener('livewire:init', () => {
        Livewire.on('open-account-modal', () => window.dispatchEvent(new CustomEvent('open-modal', { detail: 'account-modal' })));
        Livewire.on('close-account-modal', () => window.dispatchEvent(new CustomEvent('close-modal', { detail: 'account-modal' })));
    });
</script>
</div>
