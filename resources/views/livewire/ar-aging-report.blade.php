<div>

    <x-tallui-page-header
        title="AR Aging Report"
        subtitle="Outstanding receivables grouped by age bucket, as of selected date"
        icon="heroicon-o-clock"
        :separator="true"
    >
        <x-slot:breadcrumbs>
            <x-tallui-breadcrumb :links="[
                ['label' => 'Accounting', 'href' => route('accounting.dashboard')],
                ['label' => 'Reports',    'href' => route('accounting.reports')],
                ['label' => 'AR Aging'],
            ]" />
        </x-slot:breadcrumbs>

        <x-slot:actions>
            <div class="flex items-center gap-2">
                <label class="text-sm font-medium">As of</label>
                <input type="date" wire:model.live="asOfDate"
                    class="input input-sm input-bordered" />
            </div>
            <a href="{{ route('accounting.invoices') }}" wire:navigate class="btn btn-sm btn-ghost gap-1">
                <x-tallui-icon name="o-document-duplicate" size="w-4 h-4" />
                Invoices
            </a>
        </x-slot:actions>
    </x-tallui-page-header>

    <div class="p-4 md:p-6 space-y-6">

        @php
            $labels = [
                'current'  => 'Current (not yet due)',
                '1_30'     => '1 – 30 days',
                '31_60'    => '31 – 60 days',
                '61_90'    => '61 – 90 days',
                'over_90'  => 'Over 90 days',
            ];
            $colors = [
                'current'  => 'text-success',
                '1_30'     => 'text-info',
                '31_60'    => 'text-warning',
                '61_90'    => 'text-orange-500',
                'over_90'  => 'text-error',
            ];
            $badgeColors = [
                'current'  => 'success',
                '1_30'     => 'info',
                '31_60'    => 'warning',
                '61_90'    => 'warning',
                'over_90'  => 'error',
            ];
            $bgColors = [
                'current'  => 'bg-success',
                '1_30'     => 'bg-info',
                '31_60'    => 'bg-warning',
                '61_90'    => 'bg-orange-400',
                'over_90'  => 'bg-error',
            ];
            $totals = $report['totals'];
            $grandTotal = (float) $totals['total'];
        @endphp

        {{-- Summary stats --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            @foreach($labels as $key => $label)
                <div class="stat bg-base-100 rounded-xl border border-base-200 shadow-sm p-3">
                    <div class="stat-title text-xs text-base-content/60 leading-tight">{{ $label }}</div>
                    <div class="stat-value text-base font-bold {{ $colors[$key] }} truncate">
                        {{ number_format($totals[$key], 2) }}
                    </div>
                    <div class="stat-desc text-xs">
                        {{ $grandTotal > 0 ? round($totals[$key] / $grandTotal * 100, 1) : 0 }}%
                    </div>
                </div>
            @endforeach
            <div class="stat bg-base-100 rounded-xl border border-base-200 shadow-sm p-3">
                <div class="stat-title text-xs text-base-content/60">Total AR</div>
                <div class="stat-value text-base font-bold truncate">{{ number_format($grandTotal, 2) }}</div>
                <div class="stat-desc text-xs">{{ $currency }}</div>
            </div>
        </div>

        {{-- Stacked bar --}}
        @if($grandTotal > 0)
            <div class="flex gap-1 h-3">
                @foreach($bgColors as $key => $color)
                    @php $pct = $grandTotal > 0 ? $totals[$key] / $grandTotal * 100 : 0 @endphp
                    @if($pct > 0)
                        <div class="{{ $color }} rounded-full h-full" style="width: {{ $pct }}%"
                            title="{{ $labels[$key] }}: {{ round($pct, 1) }}%"></div>
                    @endif
                @endforeach
            </div>
        @endif

        {{-- Per-customer detail table --}}
        <x-tallui-card title="Customer Breakdown" :shadow="true" padding="normal">
            @if(count($report['rows']) > 0)
                <div class="overflow-x-auto">
                    <table class="table table-sm w-full">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                @foreach($labels as $label)
                                    <th class="text-right">{{ $label }}</th>
                                @endforeach
                                <th class="text-right">Total ({{ $currency }})</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($report['rows'] as $row)
                                <tr class="hover">
                                    <td class="font-medium">{{ $row['name'] }}</td>
                                    @foreach(array_keys($labels) as $key)
                                        <td class="text-right font-mono text-sm {{ $row[$key] > 0 ? $colors[$key] : 'text-base-content/30' }}">
                                            {{ $row[$key] > 0 ? number_format($row[$key], 2) : '—' }}
                                        </td>
                                    @endforeach
                                    <td class="text-right font-mono font-semibold">
                                        {{ number_format($row['total'], 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-base-300 font-bold bg-base-200">
                                <td>Total</td>
                                @foreach(array_keys($labels) as $key)
                                    <td class="text-right font-mono {{ $totals[$key] > 0 ? $colors[$key] : '' }}">
                                        {{ number_format($totals[$key], 2) }}
                                    </td>
                                @endforeach
                                <td class="text-right font-mono">{{ number_format($grandTotal, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @else
                <x-tallui-empty-state
                    title="No outstanding receivables"
                    description="All invoices are settled as of {{ $report['as_of_date'] }}."
                    icon="heroicon-o-check-circle"
                    size="md"
                />
            @endif
        </x-tallui-card>

    </div>

</div>
