<x-layouts.app :title="__('Journal Entries')">
<x-tallui-notification />

<x-tallui-page-header title="Journal Entries" subtitle="Double-entry bookkeeping transactions" icon="o-pencil-square">
    <x-slot:actions>
        <x-tallui-button wire:click="$set('showModal', true)" icon="o-plus" class="btn-primary btn-sm">New Entry</x-tallui-button>
    </x-slot:actions>
</x-tallui-page-header>

{{-- Filters --}}
<x-tallui-card class="mb-4" padding="compact">
    <div class="flex flex-wrap gap-3 items-end p-1">
        <div class="flex-1 min-w-52">
            <x-tallui-form-group label="Search">
                <x-tallui-input wire:model.live.debounce.300ms="search" placeholder="Entry #, description, reference…" class="input-sm" />
            </x-tallui-form-group>
        </div>
        <div class="w-36">
            <x-tallui-form-group label="Status">
                <x-tallui-select wire:model.live="statusFilter" class="select-sm">
                    <option value="">All</option>
                    <option value="draft">Draft</option>
                    <option value="posted">Posted</option>
                    <option value="void">Void</option>
                </x-tallui-select>
            </x-tallui-form-group>
        </div>
        <div class="w-40">
            <x-tallui-form-group label="From">
                <x-tallui-input type="date" wire:model.live="dateFrom" class="input-sm" />
            </x-tallui-form-group>
        </div>
        <div class="w-40">
            <x-tallui-form-group label="To">
                <x-tallui-input type="date" wire:model.live="dateTo" class="input-sm" />
            </x-tallui-form-group>
        </div>
    </div>
</x-tallui-card>

{{-- Entries Table --}}
<x-tallui-card padding="none">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                    <th class="pl-5">Entry #</th>
                    <th>Date</th>
                    <th>Description</th>
                    <th>Reference</th>
                    <th class="text-right">Amount</th>
                    <th>Status</th>
                    <th class="pr-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse($entries as $entry)
                    <tr class="hover:bg-base-50">
                        <td class="pl-5 font-mono text-sm text-primary font-semibold">{{ $entry->entry_number }}</td>
                        <td class="text-sm text-base-content/60">{{ $entry->date->format('M d, Y') }}</td>
                        <td>
                            <div class="text-sm max-w-xs truncate">{{ $entry->description }}</div>
                            {{-- Collapsed lines preview --}}
                            <div class="text-xs text-base-content/40 mt-0.5">
                                @foreach($entry->lines->take(2) as $line)
                                    <span class="{{ $line->type === 'debit' ? 'text-success' : 'text-error' }}">
                                        {{ $line->account->code }}
                                    </span>
                                    @if(!$loop->last) · @endif
                                @endforeach
                                @if($entry->lines->count() > 2)
                                    +{{ $entry->lines->count() - 2 }} more
                                @endif
                            </div>
                        </td>
                        <td class="text-sm text-base-content/50">{{ $entry->reference ?? '—' }}</td>
                        <td class="text-right text-sm font-medium">
                            {{ number_format($entry->lines->where('type', 'debit')->sum('amount'), 2) }}
                        </td>
                        <td>
                            <x-tallui-badge :type="match($entry->status->value ?? $entry->status) {
                                'posted' => 'success',
                                'void'   => 'error',
                                default  => 'warning',
                            }">
                                {{ ucfirst($entry->status->value ?? $entry->status) }}
                            </x-tallui-badge>
                        </td>
                        <td class="pr-5">
                            <div class="flex justify-end gap-1">
                                @if(($entry->status->value ?? $entry->status) === 'draft')
                                    <x-tallui-button wire:click="postEntry({{ $entry->id }})" wire:confirm="Post this journal entry?" class="btn-success btn-xs" spinner>
                                        Post
                                    </x-tallui-button>
                                @endif
                                @if(($entry->status->value ?? $entry->status) === 'posted')
                                    <x-tallui-button wire:click="voidEntry({{ $entry->id }})" wire:confirm="Void this entry? This cannot be undone." class="btn-error btn-xs btn-outline" spinner>
                                        Void
                                    </x-tallui-button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <x-tallui-empty-state title="No journal entries" description="Create your first journal entry" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-base-200">{{ $entries->links() }}</div>
</x-tallui-card>

{{-- Create Modal --}}
<x-tallui-modal id="journal-modal" title="New Journal Entry" icon="o-pencil-square" size="xl">
    <x-slot:trigger>
        <span x-effect="if ($wire.showModal) $dispatch('open-modal', 'journal-modal'); else $dispatch('close-modal', 'journal-modal')"></span>
    </x-slot:trigger>

    <form wire:submit.prevent="create" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Date *" :error="$errors->first('date')">
                <x-tallui-input type="date" wire:model="date" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Reference">
                <x-tallui-input wire:model="reference" placeholder="e.g. INV-001" />
            </x-tallui-form-group>
        </div>

        <x-tallui-form-group label="Description *" :error="$errors->first('description')">
            <x-tallui-textarea wire:model="description" rows="2" placeholder="Describe this transaction…" />
        </x-tallui-form-group>

        {{-- Lines --}}
        <div>
            <div class="flex items-center justify-between mb-2">
                <label class="text-sm font-semibold text-base-content/70">Entry Lines</label>
                <x-tallui-button wire:click="addLine" icon="o-plus" class="btn-ghost btn-xs">Add Line</x-tallui-button>
            </div>

            <div class="space-y-2 max-h-64 overflow-y-auto pr-1">
                @foreach($lines as $index => $line)
                    <div class="flex gap-2 items-start bg-base-50 border border-base-200 p-2 rounded-xl">
                        <div class="flex-1">
                            <select wire:model="lines.{{ $index }}.account_id" class="select select-sm w-full border-base-300">
                                <option value="">Select Account</option>
                                @foreach($accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                                @endforeach
                            </select>
                            @error("lines.{$index}.account_id") <p class="text-error text-xs mt-0.5">{{ $message }}</p> @enderror
                        </div>
                        <select wire:model="lines.{{ $index }}.type" class="select select-sm w-28 border-base-300">
                            <option value="debit">Debit</option>
                            <option value="credit">Credit</option>
                        </select>
                        <input type="number" step="0.01" wire:model.lazy="lines.{{ $index }}.amount"
                               placeholder="0.00" class="input input-sm w-28 border-base-300 text-right" />
                        <x-tallui-button wire:click="removeLine({{ $index }})" icon="o-trash" class="btn-ghost btn-sm text-error" />
                    </div>
                @endforeach
            </div>

            {{-- Balance indicator --}}
            @php $diff = abs($this->getTotalDebits() - $this->getTotalCredits()); @endphp
            <div class="mt-3 p-3 rounded-xl {{ $diff < 0.01 ? 'bg-success/10 border border-success/20' : 'bg-warning/10 border border-warning/20' }}">
                <div class="flex justify-between text-sm">
                    <span>Debits: <strong>{{ number_format($this->getTotalDebits(), 2) }}</strong></span>
                    <span>Credits: <strong>{{ number_format($this->getTotalCredits(), 2) }}</strong></span>
                    <span class="{{ $diff < 0.01 ? 'text-success' : 'text-warning' }} font-medium">
                        {{ $diff < 0.01 ? '✓ Balanced' : 'Difference: ' . number_format($diff, 2) }}
                    </span>
                </div>
            </div>
        </div>
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="create" spinner="create" class="btn-primary">Create Entry</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>
</x-layouts.app>
