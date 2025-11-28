<div class="chart-of-accounts">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-3xl font-bold text-gray-800">Chart of Accounts</h2>
        <button wire:click="openModal()" class="btn btn-primary">
            <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            New Account
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
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                <input type="text" wire:model.live.debounce.300ms="search" 
                       placeholder="Code or name..."
                       class="w-full px-3 py-2 border border-gray-300 rounded-md">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Account Type</label>
                <select wire:model.live="typeFilter" class="w-full px-3 py-2 border border-gray-300 rounded-md">
                    <option value="">All Types</option>
                    <option value="asset">Asset</option>
                    <option value="liability">Liability</option>
                    <option value="equity">Equity</option>
                    <option value="revenue">Revenue</option>
                    <option value="expense">Expense</option>
                </select>
            </div>
            <div class="flex items-end">
                <button wire:click="$set('typeFilter', '')" class="w-full px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50">
                    Clear Filters
                </button>
            </div>
        </div>
    </div>

    <!-- Accounts Table -->
    <div class="bg-white rounded-lg shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Code</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Subtype</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Currency</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($accounts as $account)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-sm font-medium text-blue-600">{{ $account->code }}</span>
                                @if($account->is_system)
                                    <span class="ml-2 px-2 py-0.5 text-xs bg-purple-100 text-purple-800 rounded">System</span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-gray-900">{{ $account->name }}</div>
                                @if($account->description)
                                    <div class="text-xs text-gray-500">{{ Str::limit($account->description, 50) }}</div>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 py-1 text-xs font-semibold rounded-full
                                    {{ $account->type === 'asset' ? 'bg-green-100 text-green-800' : '' }}
                                    {{ $account->type === 'liability' ? 'bg-red-100 text-red-800' : '' }}
                                    {{ $account->type === 'equity' ? 'bg-blue-100 text-blue-800' : '' }}
                                    {{ $account->type === 'revenue' ? 'bg-purple-100 text-purple-800' : '' }}
                                    {{ $account->type === 'expense' ? 'bg-orange-100 text-orange-800' : '' }}">
                                    {{ ucfirst($account->type) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                {{ $account->subtype ? ucfirst(str_replace('_', ' ', $account->subtype)) : '-' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                {{ $account->currency }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <button wire:click="toggleStatus({{ $account->id }})" 
                                        class="text-sm {{ $account->is_active ? 'text-green-600' : 'text-gray-400' }}">
                                    {{ $account->is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <button wire:click="openModal({{ $account->id }})" class="text-blue-600 hover:text-blue-900">
                                    Edit
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                No accounts found
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-6 py-4 border-t">
            {{ $accounts->links() }}
        </div>
    </div>

    <!-- Create/Edit Modal -->
    @if($showModal)
        <div class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
            <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-2xl font-bold">{{ $accountId ? 'Edit' : 'New' }} Account</h3>
                    <button wire:click="$set('showModal', false)" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <form wire:submit.prevent="save">
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Code *</label>
                            <input type="text" wire:model="code" placeholder="e.g., 1000" 
                                   class="w-full px-3 py-2 border rounded-md @error('code') border-red-500 @enderror">
                            @error('code') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Currency *</label>
                            <input type="text" wire:model="currency" placeholder="USD" maxlength="3"
                                   class="w-full px-3 py-2 border rounded-md @error('currency') border-red-500 @enderror">
                            @error('currency') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Name *</label>
                        <input type="text" wire:model="name" placeholder="Account name" 
                               class="w-full px-3 py-2 border rounded-md @error('name') border-red-500 @enderror">
                        @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Type *</label>
                            <select wire:model="type" class="w-full px-3 py-2 border rounded-md @error('type') border-red-500 @enderror">
                                <option value="">Select Type</option>
                                <option value="asset">Asset</option>
                                <option value="liability">Liability</option>
                                <option value="equity">Equity</option>
                                <option value="revenue">Revenue</option>
                                <option value="expense">Expense</option>
                            </select>
                            @error('type') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Subtype</label>
                            <select wire:model="subtype" class="w-full px-3 py-2 border rounded-md">
                                <option value="">Select Subtype</option>
                                @if($type === 'asset')
                                    <option value="current_asset">Current Asset</option>
                                    <option value="fixed_asset">Fixed Asset</option>
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
                            </select>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Parent Account</label>
                        <select wire:model="parent_id" class="w-full px-3 py-2 border rounded-md">
                            <option value="">None (Top Level)</option>
                            @foreach($parentAccounts as $parent)
                                <option value="{{ $parent->id }}">{{ $parent->code }} - {{ $parent->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                        <textarea wire:model="description" rows="3" 
                                  class="w-full px-3 py-2 border rounded-md"></textarea>
                    </div>

                    <div class="mb-4">
                        <label class="inline-flex items-center">
                            <input type="checkbox" wire:model="is_active" class="rounded border-gray-300">
                            <span class="ml-2 text-sm text-gray-700">Active</span>
                        </label>
                    </div>

                    <div class="flex justify-end gap-3 pt-4 border-t">
                        <button type="button" wire:click="$set('showModal', false)" 
                                class="px-4 py-2 border rounded-md hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            {{ $accountId ? 'Update' : 'Create' }} Account
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

<style>
    .btn { padding: 0.5rem 1rem; border-radius: 0.375rem; font-weight: 500; transition: all 0.2s; display: inline-flex; align-items: center; }
    .btn-primary { background-color: #3b82f6; color: white; }
    .btn-primary:hover { background-color: #2563eb; }
    .alert { padding: 1rem; border-radius: 0.375rem; margin-bottom: 1rem; }
    .alert-success { background-color: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
    .alert-error { background-color: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
</style>