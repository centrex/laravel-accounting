<div>
<x-tallui-notification />

<x-tallui-page-header title="Chart of Accounts" subtitle="Manage your general ledger accounts" icon="o-rectangle-stack">
    <x-slot:actions>
        <x-tallui-button wire:click="exportPdf" spinner="exportPdf" icon="o-arrow-down-tray" class="btn-ghost btn-sm">Export PDF</x-tallui-button>
        <x-tallui-button wire:click="openModal()" icon="o-plus" class="btn-primary btn-sm">New Account</x-tallui-button>
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

{{--
    QuickBooks-style Chart of Accounts: grouped by major account type (Assets, Liabilities,
    Equity, Revenue, Expenses), with each parent account followed by its children, indented —
    rather than a flat alphabetical/coded list. The whole (filtered) tree renders at once instead
    of paginating, since splitting a hierarchy across pages would separate parents from children.
--}}
<x-tallui-card padding="none">
    @forelse($accountGroups as $group)
        <div class="border-b border-base-300 last:border-b-0">
            <div class="bg-base-200 px-5 py-2 text-xs font-semibold uppercase tracking-wide text-base-content/60">
                {{ match ($group['type']) {
                    'asset'     => 'Assets',
                    'liability' => 'Liabilities',
                    'equity'    => 'Equity',
                    'revenue'   => 'Revenue',
                    'expense'   => 'Expenses',
                    default     => ucfirst($group['type']),
                } }}
            </div>
            <div class="overflow-x-auto">
                <table class="table table-sm w-full">
                    <tbody class="divide-y divide-base-200">
                        @foreach($group['rows'] as $row)
                            @php [$account, $depth, $matched] = [$row['account'], $row['depth'], $row['matched']]; @endphp
                            <tr class="{{ $matched ? 'even:bg-base-200/50 hover:bg-base-200' : 'opacity-50' }}">
                                <td class="pl-5 w-32" style="padding-left: {{ 1.25 + $depth * 1.5 }}rem">
                                    <span class="font-mono text-sm font-semibold {{ $matched ? 'text-primary' : 'text-base-content/60' }}">{{ $account->code }}</span>
                                </td>
                                <td>
                                    <div class="flex items-center gap-1.5 text-sm font-medium">
                                        @if($depth > 0)
                                            <x-tallui-icon name="o-arrow-turn-down-right" class="h-3.5 w-3.5 shrink-0 text-base-content/30" />
                                        @endif
                                        {{ $account->name }}
                                        @if($account->is_system)
                                            <x-tallui-badge type="info" class="badge-xs">System</x-tallui-badge>
                                        @endif
                                    </div>
                                    @if($account->description)
                                        <div class="text-xs text-base-content/40 truncate max-w-xs">{{ $account->description }}</div>
                                    @endif
                                </td>
                                <td class="text-sm text-base-content/60">
                                    {{ $account->subtype ? ucwords(str_replace('_', ' ', $account->subtype->value ?? $account->subtype)) : '—' }}
                                </td>
                                <td class="text-sm text-base-content/60">{{ $account->currency }}</td>
                                <td>
                                    @if($matched)
                                        <button wire:click="toggleStatus({{ $account->id }})"
                                            class="text-sm {{ $account->is_active ? 'text-success' : 'text-base-content/30' }} hover:underline">
                                            {{ $account->is_active ? 'Active' : 'Inactive' }}
                                        </button>
                                    @else
                                        <span class="text-sm text-base-content/30">{{ $account->is_active ? 'Active' : 'Inactive' }}</span>
                                    @endif
                                </td>
                                <td class="pr-5 text-right">
                                    @if($matched)
                                        <x-tallui-button wire:click="openAuditTrail(@js($account::class), {{ $account->getKey() }}, @js($account->code . ' - ' . $account->name))" icon="o-clock" class="btn-ghost btn-sm" title="Audit trail" />
                                        <x-tallui-button wire:click="openModal({{ $account->id }})" icon="o-pencil" class="btn-ghost btn-sm" />
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <x-tallui-empty-state title="No accounts found" description="Create your first account or adjust your filters" />
    @endforelse
</x-tallui-card>

{{-- Create/Edit Modal --}}
<x-tallui-modal id="account-modal" :title="$accountId ? 'Edit Account' : 'New Account'" icon="o-rectangle-stack" size="lg">
    <x-slot:trigger>
        <span
            x-effect="if ($wire.showModal) $dispatch('open-modal', 'account-modal'); else $dispatch('close-modal', 'account-modal')"
            @modal-closed.window="if ($event.detail === 'account-modal') $wire.showModal = false"
        ></span>
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
@include('accounting::livewire.partials.audit-trail-modal')

<script>
    document.addEventListener('livewire:init', () => {
        Livewire.on('open-account-modal', () => window.dispatchEvent(new CustomEvent('open-modal', { detail: 'account-modal' })));
        Livewire.on('close-account-modal', () => window.dispatchEvent(new CustomEvent('close-modal', { detail: 'account-modal' })));
    });
</script>
</div>
