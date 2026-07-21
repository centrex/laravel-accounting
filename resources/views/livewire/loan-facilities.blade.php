<div>
<x-tallui-notification />

<x-tallui-page-header title="Loan Facilities" subtitle="Term loans, working capital, director & inter-company loans" icon="o-banknotes">
    <x-slot:actions>
        @can('accounting.loans.manage')
            <x-tallui-button wire:click="openCreate" icon="o-plus" class="btn-primary btn-sm">New Loan Facility</x-tallui-button>
        @endcan
    </x-slot:actions>
</x-tallui-page-header>

{{-- Filters --}}
<x-tallui-card class="mb-4" padding="compact">
    <div class="flex flex-wrap gap-3 items-end p-1">
        <div class="flex-1 min-w-52">
            <x-tallui-form-group label="Search">
                <x-tallui-input wire:model.live.debounce.300ms="search" placeholder="Lender name…" class="input-sm" />
            </x-tallui-form-group>
        </div>
        <div class="w-44">
            <x-tallui-form-group label="Term">
                <x-tallui-select wire:model.live="termFilter" class="select-sm">
                    <option value="">All</option>
                    <option value="short_term">Short-term</option>
                    <option value="long_term">Long-term</option>
                </x-tallui-select>
            </x-tallui-form-group>
        </div>
        <div class="w-48">
            <x-tallui-form-group label="Type">
                <x-tallui-select wire:model.live="typeFilter" class="select-sm">
                    <option value="">All</option>
                    <option value="term_loan">Term Loan</option>
                    <option value="working_capital">Working Capital</option>
                    <option value="inter_company">Inter-company</option>
                    <option value="director">Director</option>
                    <option value="equipment">Equipment</option>
                    <option value="overdraft">Overdraft</option>
                    <option value="bridge">Bridge</option>
                </x-tallui-select>
            </x-tallui-form-group>
        </div>
    </div>
</x-tallui-card>

{{-- Facilities Table --}}
<x-tallui-card padding="none">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                    <th class="pl-5">Lender</th>
                    <th>Type</th>
                    <th>Term</th>
                    <th class="text-right">Rate / mo</th>
                    <th class="text-right">Outstanding Principal</th>
                    <th class="text-right">Accrued Interest</th>
                    <th>Status</th>
                    <th class="pr-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse($facilities as $facility)
                    <tr class="even:bg-base-200/50 hover:bg-base-200">
                        <td class="pl-5">
                            <div class="text-sm font-semibold">{{ $facility->lender_name }}</div>
                            @if($facility->sbu_code)
                                <div class="text-xs text-base-content/50">SBU: {{ $facility->sbu_code }}</div>
                            @endif
                        </td>
                        <td class="text-sm text-base-content/70">{{ str($facility->loan_type)->replace('_', ' ')->title() }}</td>
                        <td>
                            <x-tallui-badge type="{{ $facility->loan_term === 'long_term' ? 'secondary' : 'neutral' }}" size="sm">
                                {{ $facility->loan_term === 'long_term' ? 'Long-term' : 'Short-term' }}
                            </x-tallui-badge>
                        </td>
                        <td class="text-right text-sm font-mono">{{ number_format($facility->monthly_rate * 100, 2) }}%</td>
                        <td class="text-right text-sm font-mono font-medium">{{ number_format($facility->outstandingPrincipal(), 2) }}</td>
                        <td class="text-right text-sm font-mono text-base-content/70">{{ number_format($facility->accruedInterest(), 2) }}</td>
                        <td>
                            <x-tallui-badge type="{{ $facility->is_active ? 'success' : 'ghost' }}" size="sm">
                                {{ $facility->is_active ? 'Active' : 'Inactive' }}
                            </x-tallui-badge>
                        </td>
                        <td class="pr-5">
                            @can('accounting.loans.manage')
                                <div class="flex justify-end flex-wrap gap-1">
                                    <x-tallui-button wire:click="openAction({{ $facility->id }}, 'drawdown')" class="btn-ghost btn-xs">Drawdown</x-tallui-button>
                                    <x-tallui-button wire:click="accrueInterest({{ $facility->id }})" wire:confirm="Accrue this month's interest for {{ $facility->lender_name }}?" class="btn-ghost btn-xs">Accrue</x-tallui-button>
                                    <x-tallui-button wire:click="openAction({{ $facility->id }}, 'pay_interest')" class="btn-ghost btn-xs">Pay Interest</x-tallui-button>
                                    <x-tallui-button wire:click="openAction({{ $facility->id }}, 'repay')" class="btn-ghost btn-xs">Repay</x-tallui-button>
                                    <x-tallui-button wire:click="toggleActive({{ $facility->id }})" wire:confirm="{{ $facility->is_active ? 'Mark this facility inactive?' : 'Reactivate this facility?' }}" icon="{{ $facility->is_active ? 'o-pause' : 'o-play' }}" class="btn-ghost btn-xs" title="{{ $facility->is_active ? 'Mark Inactive' : 'Reactivate' }}" />
                                </div>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <x-tallui-empty-state title="No loan facilities yet" description="Register a lender to start tracking drawdowns, interest and repayments" icon="o-banknotes">
                                @can('accounting.loans.manage')
                                    <x-tallui-button wire:click="openCreate" icon="o-plus" class="btn-primary">New Loan Facility</x-tallui-button>
                                @endcan
                            </x-tallui-empty-state>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-base-200">{{ $facilities->links() }}</div>
</x-tallui-card>

{{-- Create Facility Modal --}}
<x-tallui-modal id="loan-facility-modal" title="New Loan Facility" icon="o-banknotes" size="lg">
    <x-slot:trigger>
        <span
            x-effect="if ($wire.showCreateModal) $dispatch('open-modal', 'loan-facility-modal'); else $dispatch('close-modal', 'loan-facility-modal')"
            @modal-closed.window="if ($event.detail === 'loan-facility-modal') $wire.showCreateModal = false"
        ></span>
    </x-slot:trigger>

    <form wire:submit.prevent="save" class="space-y-4">
        <x-tallui-form-group label="Lender Name *" :error="$errors->first('lender_name')">
            <x-tallui-input wire:model="lender_name" placeholder="e.g., IDLC Finance Ltd" />
        </x-tallui-form-group>

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Loan Type *" :error="$errors->first('loan_type')">
                <x-tallui-select wire:model="loan_type">
                    <option value="term_loan">Term Loan</option>
                    <option value="working_capital">Working Capital</option>
                    <option value="inter_company">Inter-company</option>
                    <option value="director">Director</option>
                    <option value="equipment">Equipment</option>
                    <option value="overdraft">Overdraft</option>
                    <option value="bridge">Bridge</option>
                </x-tallui-select>
            </x-tallui-form-group>
            <x-tallui-form-group label="Loan Term *" :error="$errors->first('loan_term')">
                <x-tallui-select wire:model="loan_term">
                    <option value="short_term">Short-term</option>
                    <option value="long_term">Long-term</option>
                </x-tallui-select>
            </x-tallui-form-group>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Monthly Interest Rate (%) *" :error="$errors->first('monthly_rate_pct')" helper="e.g. 1.5 = 1.5% per month">
                <x-tallui-input type="number" step="0.01" wire:model="monthly_rate_pct" class="text-right" />
            </x-tallui-form-group>
            <x-tallui-form-group label="SBU Code" :error="$errors->first('sbu_code')">
                <x-tallui-input wire:model="sbu_code" placeholder="Optional" />
            </x-tallui-form-group>
        </div>

        <div class="grid grid-cols-3 gap-4">
            <x-tallui-form-group label="Sanctioned Amount" :error="$errors->first('loan_amount')">
                <x-tallui-input type="number" step="0.01" wire:model="loan_amount" class="text-right" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Disbursed On" :error="$errors->first('disbursed_at')">
                <x-tallui-input type="date" wire:model="disbursed_at" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Due Date" :error="$errors->first('due_at')">
                <x-tallui-input type="date" wire:model="due_at" />
            </x-tallui-form-group>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Tenure (months)" :error="$errors->first('tenure_months')">
                <x-tallui-input type="number" wire:model="tenure_months" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Lender Contact" :error="$errors->first('contact')">
                <x-tallui-input wire:model="contact" placeholder="Optional" />
            </x-tallui-form-group>
        </div>
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showCreateModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="save" spinner="save" class="btn-primary">Save Facility</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>

{{-- Drawdown / Pay Interest / Repay Modal --}}
@php
    $actionLabels = [
        'drawdown'     => ['title' => 'Record Drawdown', 'cta' => 'Record Drawdown', 'help' => 'Funds received into Bank against this facility.'],
        'pay_interest' => ['title' => 'Pay Interest', 'cta' => 'Record Payment', 'help' => 'Pay accrued interest to the lender from Bank.'],
        'repay'        => ['title' => 'Repay Principal', 'cta' => 'Record Repayment', 'help' => 'Repay outstanding principal to the lender from Bank.'],
    ];
    $actionMeta = $actionLabels[$actionType] ?? ['title' => 'Record Transaction', 'cta' => 'Save', 'help' => ''];
@endphp
<x-tallui-modal id="loan-action-modal" title="{{ $actionMeta['title'] }}" icon="o-banknotes" size="md">
    <x-slot:trigger>
        <span
            x-effect="if ($wire.showActionModal) $dispatch('open-modal', 'loan-action-modal'); else $dispatch('close-modal', 'loan-action-modal')"
            @modal-closed.window="if ($event.detail === 'loan-action-modal') $wire.showActionModal = false"
        ></span>
    </x-slot:trigger>

    <form wire:submit.prevent="submitAction" class="space-y-4">
        <p class="text-sm text-base-content/60">{{ $actionMeta['help'] }}</p>

        <x-tallui-form-group label="Amount *" :error="$errors->first('action_amount')">
            <x-tallui-input type="number" step="0.01" wire:model="action_amount" class="text-right" />
        </x-tallui-form-group>

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Date *" :error="$errors->first('action_date')">
                <x-tallui-input type="date" wire:model="action_date" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Reference *" :error="$errors->first('action_reference')">
                <x-tallui-input wire:model="action_reference" />
            </x-tallui-form-group>
        </div>

        @if($actionType !== 'pay_interest')
            <x-tallui-form-group label="Description">
                <x-tallui-input wire:model="action_description" placeholder="Optional" />
            </x-tallui-form-group>
        @endif
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showActionModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="submitAction" spinner="save" class="btn-primary">{{ $actionMeta['cta'] }}</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>
</div>
