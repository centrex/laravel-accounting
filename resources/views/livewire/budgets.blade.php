<div>
<x-tallui-notification />

<x-tallui-page-header title="Budgets" subtitle="Plan and track expenses against budgets" icon="heroicon-o-chart-pie">
    <x-slot:actions>
        <x-tallui-button wire:click="openCreate" icon="heroicon-o-plus" class="btn-primary btn-sm">New Budget</x-tallui-button>
    </x-slot:actions>
</x-tallui-page-header>

{{-- Filters --}}
<x-tallui-card class="mb-4" padding="compact">
    <div class="flex flex-wrap gap-3 items-end p-1">
        <div class="flex-1 min-w-52">
            <x-tallui-form-group label="Search">
                <x-tallui-input wire:model.live.debounce.300ms="search" placeholder="Budget # or name…" class="input-sm" />
            </x-tallui-form-group>
        </div>
        <div class="w-36">
            <x-tallui-form-group label="Status">
                <x-tallui-select wire:model.live="statusFilter" class="select-sm">
                    <option value="">All</option>
                    <option value="draft">Draft</option>
                    <option value="approved">Approved</option>
                </x-tallui-select>
            </x-tallui-form-group>
        </div>
    </div>
</x-tallui-card>

{{-- Budgets Table --}}
<x-tallui-card padding="none">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                    <th class="pl-5">Budget #</th>
                    <th>Name</th>
                    <th>Period</th>
                    <th class="text-right">Total Budget</th>
                    <th class="text-right">Allocated</th>
                    <th>Status</th>
                    <th class="pr-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse($budgets as $budget)
                    <tr class="hover:bg-base-50">
                        <td class="pl-5 font-mono text-sm text-primary font-semibold">{{ $budget->budget_number }}</td>
                        <td class="text-sm font-medium">{{ $budget->name }}</td>
                        <td class="text-sm text-base-content/60">
                            {{ $budget->period_start->format('M d') }} - {{ $budget->period_end->format('M d, Y') }}
                        </td>
                        <td class="text-right text-sm font-mono font-medium">{{ number_format($budget->total_amount, 2) }}</td>
                        <td class="text-right text-sm font-mono text-base-content/70">
                            {{ number_format($budget->total_allocated, 2) }}
                        </td>
                        <td>
                            <x-tallui-badge :type="match($budget->status) {
                                'approved' => 'success',
                                default    => 'neutral',
                            }">
                                {{ ucfirst($budget->status) }}
                            </x-tallui-badge>
                        </td>
                        <td class="pr-5">
                            <div class="flex justify-end gap-1">
                                <x-tallui-button wire:click="openDetail({{ $budget->id }})"
                                    class="btn-ghost btn-xs" icon="heroicon-o-eye">View</x-tallui-button>
                                @if($budget->status === 'draft')
                                    <x-tallui-button wire:click="approveBudget({{ $budget->id }})"
                                        wire:confirm="Approve budget {{ $budget->budget_number }}?"
                                        class="btn-info btn-xs" spinner="save">Approve</x-tallui-button>
                                    <x-tallui-button wire:click="deleteBudget({{ $budget->id }})"
                                        wire:confirm="Delete this budget?"
                                        class="btn-error btn-xs" icon="heroicon-o-trash">Delete</x-tallui-button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <x-tallui-empty-state title="No budgets found" description="Create your first budget to start tracking expenses" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-base-200">{{ $budgets->links() }}</div>
</x-tallui-card>

{{-- Create Budget Modal --}}
<x-tallui-modal id="budget-modal" title="New Budget" icon="heroicon-o-chart-pie" size="xl">
    <x-slot:trigger>
        <span x-effect="if ($wire.showModal) $dispatch('open-modal', 'budget-modal'); else $dispatch('close-modal', 'budget-modal')"></span>
    </x-slot:trigger>

    <form wire:submit.prevent="save" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Budget Name *" :error="$errors->first('name')">
                <x-tallui-input wire:model="name" placeholder="e.g., 2026 Annual Budget" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Fiscal Year">
                <x-tallui-select wire:model="fiscal_year_id" class="select-sm">
                    <option value="">Select Year (optional)</option>
                    @foreach($fiscalYears as $year)
                        <option value="{{ $year->id }}">{{ $year->name }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>
        </div>

        <div class="grid grid-cols-3 gap-4">
            <x-tallui-form-group label="Period Start *" :error="$errors->first('period_start')">
                <x-tallui-input type="date" wire:model="period_start" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Period End *" :error="$errors->first('period_end')">
                <x-tallui-input type="date" wire:model="period_end" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Total Budget *" :error="$errors->first('total_amount')">
                <x-tallui-input type="number" step="0.01" wire:model="total_amount" class="text-right" />
            </x-tallui-form-group>
        </div>

        {{-- Budget Items --}}
        <div>
            <div class="flex items-center justify-between mb-2">
                <label class="text-sm font-semibold text-base-content/70">Budget Line Items</label>
                <x-tallui-button wire:click="addItem" icon="heroicon-o-plus" class="btn-ghost btn-xs">Add Line</x-tallui-button>
            </div>
            <div class="space-y-2 max-h-64 overflow-y-auto pr-1">
                @foreach($items as $i => $item)
                    <div class="flex gap-2 items-start bg-base-50 border border-base-200 p-2 rounded-xl">
                        <div class="flex-1">
                            <x-tallui-select wire:model="items.{{ $i }}.account_id" class="{{ $errors->has('items.' . $i . '.account_id') ? 'select-error' : '' }}">
                                <option value="">Select Account</option>
                                @foreach($expenseAccounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</option>
                                @endforeach
                            </x-tallui-select>
                        </div>
                        <div class="w-40">
                            <input type="number" step="0.01" wire:model.lazy="items.{{ $i }}.amount" placeholder="Amount"
                                class="input input-sm w-full border-base-300 text-right {{ $errors->has('items.' . $i . '.amount') ? 'input-error' : '' }}" />
                        </div>
                        <x-tallui-button wire:click="removeItem({{ $i }})" icon="heroicon-o-trash" class="btn-ghost btn-sm text-error" />
                    </div>
                @endforeach
            </div>
            <div class="mt-3 p-3 bg-base-50 rounded-xl border border-base-200 text-sm">
                <div class="flex justify-between mb-1">
                    <span class="text-base-content/60">Total Allocated</span>
                    <span class="font-mono">{{ number_format($this->subtotal, 2) }}</span>
                </div>
                <div class="flex justify-between font-bold border-t border-base-200 pt-1 mt-1">
                    <span>Difference</span>
                    <span class="font-mono {{ abs((float)$total_amount - $this->subtotal) > 0.01 ? 'text-error' : 'text-success' }}">
                        {{ number_format((float)$total_amount - $this->subtotal, 2) }}
                    </span>
                </div>
            </div>
            @error('total_amount')
                <p class="text-error text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        <x-tallui-form-group label="Notes">
            <x-tallui-textarea wire:model="notes" rows="2" placeholder="Budget notes…" />
        </x-tallui-form-group>
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="save" spinner="save" class="btn-primary">Create Budget</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>

{{-- Budget Detail Modal --}}
@if($viewingBudgetId)
    @php $viewingBudget = \Centrex\LaravelAccounting\Models\Budget::with(['items.account'])->find($viewingBudgetId); @endphp
    @if($viewingBudget)
        <x-tallui-modal id="budget-detail-modal" title="Budget: {{ $viewingBudget->name }}" icon="heroicon-o-chart-pie" size="lg">
            <x-slot:trigger>
                <span x-effect="if ($wire.showDetailModal) $dispatch('open-modal', 'budget-detail-modal'); else $dispatch('close-modal', 'budget-detail-modal')"></span>
            </x-slot:trigger>

            <div class="space-y-4">
                <div class="grid grid-cols-3 gap-4 text-sm">
                    <div>
                        <span class="text-base-content/50">Budget #</span>
                        <p class="font-mono font-semibold">{{ $viewingBudget->budget_number }}</p>
                    </div>
                    <div>
                        <span class="text-base-content/50">Status</span>
                        <p><x-tallui-badge type="{{ $viewingBudget->status === 'approved' ? 'success' : 'neutral' }}">{{ ucfirst($viewingBudget->status) }}</x-tallui-badge></p>
                    </div>
                    <div>
                        <span class="text-base-content/50">Total Budget</span>
                        <p class="font-mono font-bold text-lg">{{ number_format($viewingBudget->total_amount, 2) }}</p>
                    </div>
                </div>

                <div class="divider"></div>

                <table class="table table-sm w-full">
                    <thead>
                        <tr class="text-xs text-base-content/50 uppercase">
                            <th>Account</th>
                            <th class="text-right">Budgeted</th>
                            <th class="text-right">Actual</th>
                            <th class="text-right">Variance</th>
                            <th class="text-right">% Used</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($viewingBudget->items as $item)
                            @php
                                $percentage = $item->percentage_used;
                                $status = $percentage > 100 ? 'text-error' : ($percentage > 80 ? 'text-warning' : 'text-success');
                            @endphp
                            <tr>
                                <td class="font-medium">{{ $item->account?->code }} - {{ $item->account?->name }}</td>
                                <td class="text-right font-mono">{{ number_format($item->amount, 2) }}</td>
                                <td class="text-right font-mono">{{ number_format($item->spent, 2) }}</td>
                                <td class="text-right font-mono {{ $item->remaining < 0 ? 'text-error' : 'text-success' }}">
                                    {{ number_format($item->remaining, 2) }}
                                </td>
                                <td class="text-right font-mono {{ $status }}">{{ $percentage }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="font-bold border-t-2 border-base-300">
                            <td>Total</td>
                            <td class="text-right font-mono">{{ number_format($viewingBudget->total_allocated, 2) }}</td>
                            <td class="text-right font-mono">{{ number_format($viewingBudget->items->sum(fn($i) => $i->spent), 2) }}</td>
                            <td class="text-right font-mono">{{ number_format($viewingBudget->remaining, 2) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <x-slot:footer>
                <x-tallui-button wire:click="$set('showDetailModal', false)" class="btn-ghost">Close</x-tallui-button>
            </x-slot:footer>
        </x-tallui-modal>
    @endif
@endif
</div>
