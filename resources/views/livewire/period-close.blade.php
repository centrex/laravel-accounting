<div class="space-y-6">
<x-tallui-notification />

<x-tallui-page-header
    title="Period Close"
    subtitle="Lock a fiscal period, snapshot account balances, and optionally freeze inventory WAC"
    icon="o-lock-closed"
/>

@if(session('success'))
    <x-tallui-alert type="success">{{ session('success') }}</x-tallui-alert>
@endif

@if($errorMessage)
    <x-tallui-alert type="error">{{ $errorMessage }}</x-tallui-alert>
@endif

{{-- Step 1: Select period --}}
<x-tallui-card>
    <h3 class="font-semibold text-sm mb-4">Step 1 — Select Period to Close</h3>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <x-tallui-form-group label="Fiscal Period">
            <select wire:model.live="selectedPeriodId" class="select select-bordered select-sm w-full">
                <option value="">Select a period...</option>
                @foreach($fiscalYears as $fy)
                    <optgroup label="{{ $fy->name }}">
                        @foreach($fy->periods as $period)
                            <option value="{{ $period->id }}" @if($period->is_closed) disabled @endif>
                                {{ $period->name }}
                                ({{ \Carbon\Carbon::parse($period->start_date)->format('M d') }}
                                – {{ \Carbon\Carbon::parse($period->end_date)->format('M d, Y') }})
                                @if($period->is_closed) ✓ Closed @endif
                            </option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </x-tallui-form-group>

        @if($inventoryEnabled)
            <x-tallui-form-group label="Inventory Snapshot">
                <label class="flex items-center gap-2 mt-2 cursor-pointer">
                    <input type="checkbox" wire:model.live="snapshotInventory" class="checkbox checkbox-sm checkbox-primary" />
                    <span class="text-sm">Snapshot WAC &amp; qty at period-end</span>
                </label>
                <p class="text-xs text-base-content/50 mt-1">
                    Records inventory value and reconciles against GL account
                    {{ config('inventory.erp.accounting.accounts.inventory_asset', '1300') }}.
                </p>
            </x-tallui-form-group>
        @endif
    </div>

    @if($selectedPeriodId)
        <div class="mt-4">
            <x-tallui-button
                wire:click="runChecks"
                spinner="runChecks"
                label="Run Pre-Close Checks"
                icon="o-clipboard-document-check"
                class="btn-outline btn-sm"
            />
        </div>
    @endif
</x-tallui-card>

{{-- Step 2: Pre-close checks --}}
@if($checks)
<x-tallui-card>
    <h3 class="font-semibold text-sm mb-4">Step 2 — Pre-Close Check Results</h3>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
        <div class="rounded-xl p-4 {{ $checks['unposted_journals'] > 0 ? 'bg-error/10 text-error' : 'bg-success/10 text-success' }}">
            <div class="text-xs uppercase font-medium">Unposted Journal Entries</div>
            <div class="text-2xl font-bold font-mono mt-1">{{ $checks['unposted_journals'] }}</div>
            <div class="text-xs mt-1">{{ $checks['unposted_journals'] > 0 ? 'Must be posted or deleted before closing' : 'Clear' }}</div>
        </div>
        <div class="rounded-xl p-4 {{ $checks['open_invoices'] > 0 ? 'bg-warning/10 text-warning' : 'bg-success/10 text-success' }}">
            <div class="text-xs uppercase font-medium">Open Invoices</div>
            <div class="text-2xl font-bold font-mono mt-1">{{ $checks['open_invoices'] }}</div>
            <div class="text-xs mt-1">{{ $checks['open_invoices'] > 0 ? 'Draft/sent — review before closing' : 'Clear' }}</div>
        </div>
        <div class="rounded-xl p-4 {{ $checks['open_bills'] > 0 ? 'bg-warning/10 text-warning' : 'bg-success/10 text-success' }}">
            <div class="text-xs uppercase font-medium">Open Bills</div>
            <div class="text-2xl font-bold font-mono mt-1">{{ $checks['open_bills'] }}</div>
            <div class="text-xs mt-1">{{ $checks['open_bills'] > 0 ? 'Draft/sent — review before closing' : 'Clear' }}</div>
        </div>
    </div>

    @if($checks['has_blockers'])
        <x-tallui-alert type="error" title="Cannot close period">
            There are {{ $checks['unposted_journals'] }} unposted journal entries in this period.
            Post or void them before proceeding.
        </x-tallui-alert>
    @elseif($checks['has_warnings'])
        <x-tallui-alert type="warning" title="Warnings">
            Open invoices or bills exist in this period. You may close the period now, but these documents
            will still be editable and any payments will post to the next open period.
        </x-tallui-alert>
    @else
        <x-tallui-alert type="success" title="All checks passed">
            No blockers found. The period is ready to close.
        </x-tallui-alert>
    @endif

    @if(!$checks['has_blockers'])
        <div class="mt-5 border-t border-base-200 pt-5">
            <label class="flex items-center gap-3 cursor-pointer mb-4">
                <input type="checkbox" wire:model.live="confirmed" class="checkbox checkbox-sm checkbox-error" />
                <span class="text-sm font-medium">
                    I confirm this period should be locked. No journal entries dated within this period
                    will be postable after closing.
                </span>
            </label>

            <x-tallui-button
                wire:click="closePeriod"
                spinner="closePeriod"
                label="Close Period"
                icon="o-lock-closed"
                class="btn-error btn-sm"
                :disabled="!$confirmed"
            />
        </div>
    @endif
</x-tallui-card>
@endif

{{-- Step 3: Result --}}
@if($result)
<x-tallui-card>
    <h3 class="font-semibold text-sm mb-4">Period Closed</h3>

    <div class="flex items-center gap-3 mb-4">
        <div class="badge badge-success badge-lg gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            {{ $result['period']->name }} — Closed
        </div>
        <span class="text-sm text-base-content/60">
            {{ \Carbon\Carbon::parse($result['period']->start_date)->format('M d') }}
            – {{ \Carbon\Carbon::parse($result['period']->end_date)->format('M d, Y') }}
        </span>
    </div>

    @if($result['inventory'])
        @php $inv = $result['inventory']; @endphp
        <div class="border border-base-200 rounded-xl p-4">
            <h4 class="font-medium text-sm mb-3">Inventory Snapshot</h4>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                <div>
                    <div class="text-xs uppercase text-base-content/50">Lines Captured</div>
                    <div class="font-mono font-semibold">{{ $inv['snapshot_count'] }}</div>
                </div>
                <div>
                    <div class="text-xs uppercase text-base-content/50">Physical Value</div>
                    <div class="font-mono font-semibold">{{ $inv['currency'] }} {{ number_format($inv['physical_value'], 2) }}</div>
                </div>
                <div>
                    <div class="text-xs uppercase text-base-content/50">GL Balance (Inv. Asset)</div>
                    <div class="font-mono font-semibold">{{ $inv['currency'] }} {{ number_format($inv['gl_balance'], 2) }}</div>
                </div>
                <div>
                    <div class="text-xs uppercase text-base-content/50">Variance</div>
                    <div class="font-mono font-semibold {{ $inv['is_reconciled'] ? 'text-success' : 'text-error' }}">
                        {{ $inv['currency'] }} {{ number_format($inv['variance'], 2) }}
                        @if($inv['is_reconciled'])
                            <span class="badge badge-success badge-xs ml-1">Reconciled</span>
                        @else
                            <span class="badge badge-error badge-xs ml-1">Variance</span>
                        @endif
                    </div>
                </div>
            </div>
            @if(!$inv['is_reconciled'])
                <x-tallui-alert type="warning" class="mt-3">
                    Inventory physical value differs from the GL inventory asset balance by
                    {{ $inv['currency'] }} {{ number_format(abs($inv['variance']), 2) }}.
                    Post an adjustment journal entry (DR/CR Inventory 1300) to reconcile before year-end.
                </x-tallui-alert>
            @endif
        </div>
    @endif
</x-tallui-card>
@endif

{{-- Recently Closed Periods with Inventory Snapshots --}}
@if($recentSnapshots->isNotEmpty())
<x-tallui-card>
    <h3 class="font-semibold text-sm mb-4">Recent Inventory Snapshots</h3>
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="text-xs text-base-content/50 uppercase border-b border-base-200">
                    <th class="py-3">Period</th>
                    <th>Snapshot Date</th>
                    <th class="text-right">Lines</th>
                    <th class="text-right">Total Value</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @foreach($recentSnapshots as $snap)
                    @php
                        $totalValue = \Centrex\Accounting\Models\PeriodInventorySnapshot::where('fiscal_period_id', $snap->fiscal_period_id)->sum('total_value');
                        $count = \Centrex\Accounting\Models\PeriodInventorySnapshot::where('fiscal_period_id', $snap->fiscal_period_id)->count();
                    @endphp
                    <tr class="hover:bg-base-200/40">
                        <td class="text-sm font-medium">{{ $snap->fiscalPeriod?->name ?? '—' }}</td>
                        <td class="text-sm text-base-content/60">
                            {{ \Carbon\Carbon::parse($snap->snapshot_date)->format('M d, Y') }}
                        </td>
                        <td class="text-right text-sm font-mono">{{ $count }}</td>
                        <td class="text-right text-sm font-mono font-semibold">
                            {{ $snap->currency }} {{ number_format($totalValue, 2) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-tallui-card>
@endif

</div>
