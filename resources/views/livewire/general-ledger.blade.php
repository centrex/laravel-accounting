<div class="space-y-6">
<x-tallui-notification />

<x-tallui-page-header
    title="General Ledger"
    subtitle="Review posted journal activity with opening, period, and running balances"
    icon="o-book-open"
>
    <x-slot:actions>
        <x-tallui-button
            wire:click="exportPdf"
            spinner="exportPdf"
            label="Export PDF"
            icon="o-arrow-down-tray"
            class="btn-ghost btn-sm"
        />
    </x-slot:actions>
</x-tallui-page-header>

<x-tallui-card padding="compact">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 p-1">
        <x-tallui-form-group label="Account">
            <x-tallui-select wire:model="accountId" class="select-sm">
                <option value="">Select account...</option>
                @foreach($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</option>
                @endforeach
            </x-tallui-select>
            <div class="mt-1 text-xs text-base-content/50">Leave blank only if you want to generate all active accounts manually.</div>
        </x-tallui-form-group>

        <x-tallui-form-group label="Start Date">
            <x-tallui-input type="date" wire:model="startDate" class="input-sm" />
        </x-tallui-form-group>

        <x-tallui-form-group label="End Date">
            <x-tallui-input type="date" wire:model="endDate" class="input-sm" />
        </x-tallui-form-group>

        <x-tallui-form-group label="SBU Code">
            <x-tallui-input
                wire:model.live.debounce.300ms="sbuCode"
                class="input-sm"
                placeholder="All SBUs or e.g. OCT"
            />
        </x-tallui-form-group>

        <div class="flex items-end">
            <x-tallui-button
                wire:click="generateLedger"
                spinner="generateLedger"
                label="Generate"
                icon="o-arrow-path"
                class="btn-primary btn-sm w-full"
            />
        </div>
    </div>
</x-tallui-card>

@if($ledgerData === null)
    <x-tallui-card>
        <div class="px-2 py-8 text-center text-sm text-base-content/60">
            Select an account or keep it blank, then click Generate to load the ledger.
        </div>
    </x-tallui-card>
@endif

@if($ledgerData)
    @php
        $start = $ledgerData['period']['start'] ?? $startDate;
        $end = $ledgerData['period']['end'] ?? $endDate;
    @endphp

    <div class="text-sm text-base-content/60 px-1">
        Period: {{ \Carbon\Carbon::parse($start)->format('M d, Y') }} - {{ \Carbon\Carbon::parse($end)->format('M d, Y') }}
        @if(($ledgerData['sbu_code'] ?? null) || $sbuCode !== '')
            · SBU: {{ $ledgerData['sbu_code'] ?? strtoupper($sbuCode) }}
        @endif
    </div>

    @forelse($ledgerData['accounts'] as $section)
        @php
            $account = $section['account'];
        @endphp
        <x-tallui-card>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between mb-5">
                <div>
                    <h3 class="text-lg font-semibold">{{ $account->code }} - {{ $account->name }}</h3>
                    <p class="text-sm text-base-content/60">
                        {{ str($account->type->value ?? $account->type)->replace('_', ' ')->title() }}
                        @if($account->subtype)
                            · {{ str($account->subtype->value ?? $account->subtype)->replace('_', ' ')->title() }}
                        @endif
                    </p>
                </div>

                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 text-sm min-w-full lg:min-w-[36rem]">
                    <div class="rounded-xl bg-base-200/60 px-4 py-3">
                        <div class="text-base-content/50 text-xs uppercase">Opening</div>
                        <div class="font-mono font-semibold">{{ $currency }} {{ number_format($section['opening_balance'], 2) }}</div>
                    </div>
                    <div class="rounded-xl bg-base-200/60 px-4 py-3">
                        <div class="text-base-content/50 text-xs uppercase">Debits</div>
                        <div class="font-mono font-semibold">{{ $currency }} {{ number_format($section['period_debits'], 2) }}</div>
                    </div>
                    <div class="rounded-xl bg-base-200/60 px-4 py-3">
                        <div class="text-base-content/50 text-xs uppercase">Credits</div>
                        <div class="font-mono font-semibold">{{ $currency }} {{ number_format($section['period_credits'], 2) }}</div>
                    </div>
                    <div class="rounded-xl bg-base-200/60 px-4 py-3">
                        <div class="text-base-content/50 text-xs uppercase">Closing</div>
                        <div class="font-mono font-semibold">{{ $currency }} {{ number_format($section['closing_balance'], 2) }}</div>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="table table-sm w-full">
                    <thead>
                        <tr class="text-xs text-base-content/50 uppercase border-b border-base-200">
                            <th class="py-3">Date</th>
                            <th>Entry</th>
                            <th>Reference</th>
                            <th>Description</th>
                            <th class="text-right">Debit</th>
                            <th class="text-right">Credit</th>
                            <th class="text-right">Running Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-base-200">
                        <tr class="bg-base-200/30">
                            <td colspan="6" class="font-medium text-sm">Opening Balance</td>
                            <td class="text-right font-mono text-sm">{{ $currency }} {{ number_format($section['opening_balance'], 2) }}</td>
                        </tr>

                        @forelse($section['entries'] as $entry)
                            <tr class="hover:bg-base-200/40">
                                <td class="text-sm whitespace-nowrap">{{ \Carbon\Carbon::parse($entry['date'])->format('M d, Y') }}</td>
                                <td class="text-sm font-mono">{{ $entry['entry_number'] }}</td>
                                <td class="text-sm">{{ $entry['reference'] ?: '—' }}</td>
                                <td class="text-sm text-base-content/70">
                                    {{ $entry['line_description'] ?: $entry['journal_description'] ?: '—' }}
                                </td>
                                <td class="text-right text-sm font-mono">
                                    @if($entry['debit'] > 0)
                                        {{ $currency }} {{ number_format($entry['debit'], 2) }}
                                    @else
                                        <span class="text-base-content/30">—</span>
                                    @endif
                                </td>
                                <td class="text-right text-sm font-mono">
                                    @if($entry['credit'] > 0)
                                        {{ $currency }} {{ number_format($entry['credit'], 2) }}
                                    @else
                                        <span class="text-base-content/30">—</span>
                                    @endif
                                </td>
                                <td class="text-right text-sm font-mono">{{ $currency }} {{ number_format($entry['running_balance'], 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-6 text-center text-sm text-base-content/60">No posted ledger activity for this period.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-base-300 font-bold bg-base-200/50">
                            <td colspan="4" class="py-3 text-sm">Period Totals</td>
                            <td class="text-right text-sm font-mono">{{ $currency }} {{ number_format($section['period_debits'], 2) }}</td>
                            <td class="text-right text-sm font-mono">{{ $currency }} {{ number_format($section['period_credits'], 2) }}</td>
                            <td class="text-right text-sm font-mono">{{ $currency }} {{ number_format($section['closing_balance'], 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-tallui-card>
    @empty
        <x-tallui-card>
            <div class="px-2 py-8 text-center text-sm text-base-content/60">
                No ledger data found for the selected filters.
            </div>
        </x-tallui-card>
    @endforelse
@endif
</div>
