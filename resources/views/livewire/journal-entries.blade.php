<x-layouts.app :title="__('Accounting Journal Entries')">
<div class="accounting-journal-entries">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-3xl font-bold text-gray-800">Journal Entries</h2>
        <button wire:click="$set('showModal', true)" class="btn btn-primary">
            <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            New Entry
        </button>
    </div>

    @if (session()->has('message'))
        <div class="alert alert-success mb-4">{{ session('message') }}</div>
    @endif
    @if (session()->has('error'))
        <div class="alert alert-error mb-4">{{ session('error') }}</div>
    @endif

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                <input type="text" wire:model.live.debounce.300ms="search" 
                       placeholder="Entry #, description..."
                       class="w-full px-3 py-2 border border-gray-300 rounded-md">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                <select wire:model.live="statusFilter" class="w-full px-3 py-2 border border-gray-300 rounded-md">
                    <option value="">All Statuses</option>
                    <option value="draft">Draft</option>
                    <option value="posted">Posted</option>
                    <option value="void">Void</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Date From</label>
                <input type="date" wire:model.live="dateFrom" class="w-full px-3 py-2 border border-gray-300 rounded-md">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Date To</label>
                <input type="date" wire:model.live="dateTo" class="w-full px-3 py-2 border border-gray-300 rounded-md">
            </div>
        </div>
    </div>

    <!-- Entries Table -->
    <div class="bg-white rounded-lg shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Entry #</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($entries as $entry)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-sm font-medium text-blue-600">{{ $entry->entry_number }}</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                {{ $entry->date->format('M d, Y') }}
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-gray-900">{{ Str::limit($entry->description, 50) }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                {{ $entry->reference }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                ${{ number_format($entry->lines->where('type', 'debit')->sum('amount'), 2) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 py-1 text-xs font-semibold rounded-full
                                    {{ $entry->status === 'posted' ? 'bg-green-100 text-green-800' : '' }}
                                    {{ $entry->status === 'draft' ? 'bg-yellow-100 text-yellow-800' : '' }}
                                    {{ $entry->status === 'void' ? 'bg-red-100 text-red-800' : '' }}">
                                    {{ ucfirst($entry->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                @if($entry->status === 'draft')
                                    <button wire:click="postEntry({{ $entry->id }})" class="text-green-600 hover:text-green-900 mr-3">Post</button>
                                @endif
                                @if($entry->status === 'posted')
                                    <button wire:click="voidEntry({{ $entry->id }})" wire:confirm="Are you sure?" class="text-red-600 hover:text-red-900">Void</button>
                                @endif
                            </td>
                        </tr>
                        <!-- Entry Lines Detail -->
                        <tr class="bg-gray-50">
                            <td colspan="7" class="px-6 py-3">
                                <div class="text-xs">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <strong>Debits:</strong>
                                            @foreach($entry->lines->where('type', 'debit') as $line)
                                                <div class="ml-4">{{ $line->account->code }} - {{ $line->account->name }}: ${{ number_format($line->amount, 2) }}</div>
                                            @endforeach
                                        </div>
                                        <div>
                                            <strong>Credits:</strong>
                                            @foreach($entry->lines->where('type', 'credit') as $line)
                                                <div class="ml-4">{{ $line->account->code }} - {{ $line->account->name }}: ${{ number_format($line->amount, 2) }}</div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                No journal entries found
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-6 py-4 border-t">
            {{ $entries->links() }}
        </div>
    </div>

    <!-- Create Modal -->
    @if($showModal)
        <div class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
            <div class="relative top-20 mx-auto p-5 border w-full max-w-4xl shadow-lg rounded-md bg-white">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-2xl font-bold">New Journal Entry</h3>
                    <button wire:click="$set('showModal', false)" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <form wire:submit.prevent="create">
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Date *</label>
                            <input type="date" wire:model="date" class="w-full px-3 py-2 border rounded-md @error('date') border-red-500 @enderror">
                            @error('date') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Reference</label>
                            <input type="text" wire:model="reference" placeholder="e.g., INV-001" class="w-full px-3 py-2 border rounded-md">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Description *</label>
                        <textarea wire:model="description" rows="2" class="w-full px-3 py-2 border rounded-md @error('description') border-red-500 @enderror"></textarea>
                        @error('description') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="mb-4">
                        <div class="flex justify-between items-center mb-3">
                            <label class="block text-sm font-medium text-gray-700">Entry Lines</label>
                            <button type="button" wire:click="addLine" class="text-sm text-blue-600 hover:text-blue-800">+ Add Line</button>
                        </div>

                        <div class="space-y-2 max-h-64 overflow-y-auto">
                            @foreach($lines as $index => $line)
                                <div class="flex gap-2 items-start bg-gray-50 p-3 rounded">
                                    <div class="flex-1">
                                        <select wire:model="lines.{{ $index }}.account_id" class="w-full px-2 py-1 border rounded text-sm">
                                            <option value="">Select Account</option>
                                            @foreach($accounts as $account)
                                                <option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</option>
                                            @endforeach
                                        </select>
                                        @error("lines.{$index}.account_id") <p class="text-red-500 text-xs">{{ $message }}</p> @enderror
                                    </div>
                                    <div class="w-32">
                                        <select wire:model="lines.{{ $index }}.type" class="w-full px-2 py-1 border rounded text-sm">
                                            <option value="debit">Debit</option>
                                            <option value="credit">Credit</option>
                                        </select>
                                    </div>
                                    <div class="w-32">
                                        <input type="number" step="0.01" wire:model="lines.{{ $index }}.amount" placeholder="Amount" class="w-full px-2 py-1 border rounded text-sm">
                                        @error("lines.{$index}.amount") <p class="text-red-500 text-xs">{{ $message }}</p> @enderror
                                    </div>
                                    <button type="button" wire:click="removeLine({{ $index }})" class="text-red-600 hover:text-red-800">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                    </button>
                                </div>
                            @endforeach
                        </div>

                        <!-- Balance Summary -->
                        <div class="mt-3 p-3 bg-blue-50 rounded">
                            <div class="flex justify-between text-sm">
                                <span>Total Debits: <strong>${{ number_format($this->getTotalDebits(), 2) }}</strong></span>
                                <span>Total Credits: <strong>${{ number_format($this->getTotalCredits(), 2) }}</strong></span>
                                <span class="{{ abs($this->getTotalDebits() - $this->getTotalCredits()) < 0.01 ? 'text-green-600' : 'text-red-600' }}">
                                    Difference: <strong>${{ number_format(abs($this->getTotalDebits() - $this->getTotalCredits()), 2) }}</strong>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 pt-4 border-t">
                        <button type="button" wire:click="$set('showModal', false)" class="px-4 py-2 border rounded-md hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            Create Entry
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
</x-layouts.app>

<style>
    .btn { padding: 0.5rem 1rem; border-radius: 0.375rem; font-weight: 500; transition: all 0.2s; display: inline-flex; align-items: center; }
    .btn-primary { background-color: #3b82f6; color: white; }
    .btn-primary:hover { background-color: #2563eb; }
    .alert { padding: 1rem; border-radius: 0.375rem; margin-bottom: 1rem; }
    .alert-success { background-color: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
    .alert-error { background-color: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
</style>