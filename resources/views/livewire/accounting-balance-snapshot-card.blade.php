<x-tallui-card title="Balance Snapshot" icon="o-chart-pie">
    <x-slot:actions>
        @can('accounting.reports.view')
        <a href="{{ route('accounting.reports') }}" wire:navigate class="btn btn-ghost btn-xs gap-1">
            Balance Sheet <x-tallui-icon name="o-arrow-top-right-on-square" size="w-3 h-3" />
        </a>
        @endcan
    </x-slot:actions>
    @if($balanceChart['series'][0] > 0 || $balanceChart['series'][1] > 0 || $balanceChart['series'][2] > 0)
        <livewire:tallui-pie-chart
            :series="$balanceChart['series']"
            :categories="$balanceChart['categories']"
            :height="200"
            :donut="true"
        />
    @endif
    <div class="divide-y divide-base-200 mt-2">
        @foreach([
            ['label' => 'Assets',      'key' => 'total_assets',      'color' => 'bg-info'],
            ['label' => 'Liabilities', 'key' => 'total_liabilities', 'color' => 'bg-warning'],
            ['label' => 'Equity',      'key' => 'total_equity',      'color' => 'bg-secondary'],
        ] as $row)
        <div class="flex justify-between items-center py-2 text-sm">
            <span class="text-base-content/60 flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 rounded-full {{ $row['color'] }} inline-block"></span>
                {{ $row['label'] }}
            </span>
            <span class="font-semibold font-mono text-xs">{{ $currency }} {{ number_format($metrics[$row['key']], 2) }}</span>
        </div>
        @endforeach
    </div>
</x-tallui-card>
