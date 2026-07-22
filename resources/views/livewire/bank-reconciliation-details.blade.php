<div class="space-y-6">
<x-tallui-notification />

<x-tallui-page-header
    title="Reconciling {{ $bankReconciliation->account->code }} — {{ $bankReconciliation->account->name }}"
    subtitle="Statement date {{ $bankReconciliation->statement_date->format('M d, Y') }}"
    icon="o-building-library"
>
    <x-slot:actions>
        <x-tallui-badge :type="$this->isCompleted() ? 'success' : 'neutral'">
            {{ ucfirst($bankReconciliation->status->value) }}
        </x-tallui-badge>
        @unless($this->isCompleted())
            <x-tallui-button wire:click="complete" spinner="complete" icon="o-check-circle" class="btn-primary btn-sm">
                Complete Reconciliation
            </x-tallui-button>
        @endunless
    </x-slot:actions>
</x-tallui-page-header>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <x-tallui-stat title="Opening Balance" value="{{ number_format($bankReconciliation->opening_balance, 2) }}" icon="o-banknotes" />
    <x-tallui-stat title="Statement Ending Balance" value="{{ number_format($bankReconciliation->statement_ending_balance, 2) }}" icon="o-document-text" />
    <x-tallui-stat title="Unmatched Statement Lines" value="{{ $this->statementLines->whereNull('matched_journal_entry_line_id')->count() }}" icon="o-exclamation-circle" />
</div>

@unless($this->isCompleted())
{{-- CSV Import --}}
<x-tallui-card title="Import Statement Lines" subtitle="Paste CSV rows: date,description,amount,type(debit|credit),reference">
    <div class="space-y-3">
        <x-tallui-textarea wire:model="csvInput" rows="4" placeholder="2025-06-01,Deposit,500.00,debit,REF001" />
        <x-tallui-button wire:click="importCsv" spinner="importCsv" icon="o-arrow-up-tray" class="btn-secondary btn-sm">Import</x-tallui-button>
    </div>
</x-tallui-card>
@endunless

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    {{-- Unreconciled GL Lines --}}
    <x-tallui-card title="Unreconciled GL Lines" padding="none">
        <div class="overflow-x-auto max-h-96 overflow-y-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                        <th class="pl-4">Type</th>
                        <th class="text-right">Amount</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-200">
                    @forelse($this->unreconciledGlLines as $glLine)
                        <tr>
                            <td class="pl-4 text-sm">
                                <x-tallui-badge :type="$glLine->type === 'debit' ? 'info' : 'warning'">{{ ucfirst($glLine->type) }}</x-tallui-badge>
                            </td>
                            <td class="text-right text-sm font-mono">{{ number_format($glLine->amount, 2) }}</td>
                            <td class="text-sm text-base-content/70">{{ $glLine->description ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-sm text-base-content/50 py-4">No unreconciled GL lines.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-tallui-card>

    {{-- Statement Lines --}}
    <x-tallui-card title="Statement Lines" padding="none">
        <div class="overflow-x-auto max-h-96 overflow-y-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                        <th class="pl-4">Date</th>
                        <th>Description</th>
                        <th class="text-right">Amount</th>
                        <th>Match</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-200">
                    @forelse($this->statementLines as $line)
                        <tr>
                            <td class="pl-4 text-sm">{{ $line->transaction_date->format('M d') }}</td>
                            <td class="text-sm">{{ $line->description }}</td>
                            <td class="text-right text-sm font-mono">{{ number_format($line->amount, 2) }}</td>
                            <td>
                                @if($line->matched_journal_entry_line_id)
                                    <div class="flex items-center gap-1">
                                        <x-tallui-badge type="success">Matched</x-tallui-badge>
                                        @unless($this->isCompleted())
                                            <x-tallui-button wire:click="unmatchLine({{ $line->id }})" icon="o-x-mark" class="btn-ghost btn-xs" title="Unmatch" />
                                        @endunless
                                    </div>
                                @elseif(!$this->isCompleted())
                                    <div class="flex items-center gap-1">
                                        <select class="select select-xs w-32" x-data x-on:change="$wire.matchLine({{ $line->id }}, $event.target.value)">
                                            <option value="">Match to…</option>
                                            @foreach($this->unreconciledGlLines as $glLine)
                                                <option value="{{ $glLine->id }}">{{ ucfirst($glLine->type) }} {{ number_format($glLine->amount, 2) }}</option>
                                            @endforeach
                                        </select>
                                        <x-tallui-button wire:click="openAdjustModal({{ $line->id }})" icon="o-adjustments-horizontal" class="btn-ghost btn-xs" title="Create adjusting entry" />
                                    </div>
                                @else
                                    <x-tallui-badge type="error">Unmatched</x-tallui-badge>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-sm text-base-content/50 py-4">No statement lines imported yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-tallui-card>
</div>

{{-- Adjusting Entry Modal --}}
<x-tallui-modal id="adjust-entry-modal" title="Create Adjusting Entry" icon="o-adjustments-horizontal" size="md">
    <x-slot:trigger>
        <span
            x-effect="if ($wire.showAdjustModal) $dispatch('open-modal', 'adjust-entry-modal'); else $dispatch('close-modal', 'adjust-entry-modal')"
            @modal-closed.window="if ($event.detail === 'adjust-entry-modal') $wire.showAdjustModal = false"
        ></span>
    </x-slot:trigger>

    <form wire:submit.prevent="saveAdjustingEntry" class="space-y-4">
        <x-tallui-form-group label="Offset Account *" :error="$errors->first('adjust_offset_account_id')">
            <x-tallui-select wire:model="adjust_offset_account_id">
                <option value="">Select an account…</option>
                @foreach($this->offsetAccounts as $account)
                    <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                @endforeach
            </x-tallui-select>
        </x-tallui-form-group>

        <x-tallui-form-group label="Description">
            <x-tallui-input wire:model="adjust_description" placeholder="e.g. Monthly bank service charge" />
        </x-tallui-form-group>
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showAdjustModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="saveAdjustingEntry" spinner="saveAdjustingEntry" class="btn-primary">Post &amp; Match</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>
</div>
