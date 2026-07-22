<div>
<x-tallui-notification />

<x-tallui-page-header title="Bank Reconciliations" subtitle="Reconcile GL bank/cash balances against statement activity" icon="o-building-library">
    <x-slot:actions>
        <x-tallui-button wire:click="openCreate" icon="o-plus" class="btn-primary btn-sm">New Reconciliation</x-tallui-button>
    </x-slot:actions>
</x-tallui-page-header>

<x-tallui-card padding="none">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                    <th class="pl-5">Account</th>
                    <th>Statement Date</th>
                    <th class="text-right">Opening Balance</th>
                    <th class="text-right">Ending Balance</th>
                    <th>Status</th>
                    <th class="pr-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse($reconciliations as $reconciliation)
                    <tr class="even:bg-base-200/50 hover:bg-base-200">
                        <td class="pl-5 text-sm font-medium">
                            <span class="font-mono text-primary text-xs mr-1">{{ $reconciliation->account->code }}</span>
                            {{ $reconciliation->account->name }}
                        </td>
                        <td class="text-sm">{{ $reconciliation->statement_date->format('M d, Y') }}</td>
                        <td class="text-right text-sm font-mono">{{ number_format($reconciliation->opening_balance, 2) }}</td>
                        <td class="text-right text-sm font-mono">{{ number_format($reconciliation->statement_ending_balance, 2) }}</td>
                        <td>
                            <x-tallui-badge :type="$reconciliation->status->value === 'completed' ? 'success' : 'neutral'">
                                {{ ucfirst($reconciliation->status->value) }}
                            </x-tallui-badge>
                        </td>
                        <td class="pr-5 text-right">
                            <x-tallui-button :link="route('accounting.bank-reconciliations.show', $reconciliation->id)" icon="o-arrow-right" class="btn-ghost btn-xs" title="Open" />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-tallui-empty-state title="No reconciliations yet" description="Start a new reconciliation for one of your bank or cash accounts" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-base-200">{{ $reconciliations->links() }}</div>
</x-tallui-card>

{{-- New Reconciliation Modal --}}
<x-tallui-modal id="bank-reconciliation-modal" title="New Bank Reconciliation" icon="o-building-library" size="md">
    <x-slot:trigger>
        <span
            x-effect="if ($wire.showModal) $dispatch('open-modal', 'bank-reconciliation-modal'); else $dispatch('close-modal', 'bank-reconciliation-modal')"
            @modal-closed.window="if ($event.detail === 'bank-reconciliation-modal') $wire.showModal = false"
        ></span>
    </x-slot:trigger>

    <form wire:submit.prevent="save" class="space-y-4">
        <x-tallui-form-group label="Account *" :error="$errors->first('account_id')">
            <x-tallui-select wire:model.live="account_id">
                <option value="">Select an account…</option>
                @foreach($this->bankAccounts as $account)
                    <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                @endforeach
            </x-tallui-select>
        </x-tallui-form-group>

        <x-tallui-form-group label="Statement Date *" :error="$errors->first('statement_date')">
            <x-tallui-input type="date" wire:model="statement_date" />
        </x-tallui-form-group>

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Opening Balance *" :error="$errors->first('opening_balance')">
                <x-tallui-input type="number" step="0.01" wire:model="opening_balance" class="text-right" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Statement Ending Balance *" :error="$errors->first('statement_ending_balance')">
                <x-tallui-input type="number" step="0.01" wire:model="statement_ending_balance" class="text-right" />
            </x-tallui-form-group>
        </div>

        <x-tallui-form-group label="Notes">
            <x-tallui-textarea wire:model="notes" rows="2" />
        </x-tallui-form-group>
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="save" spinner="save" class="btn-primary">Start Reconciliation</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>
</div>
