<x-tallui-card title="Current Assets" icon="o-banknotes" subtitle="Liquid assets available">
    @if($currentAssets->isEmpty())
        <x-tallui-empty-state title="No accounts" icon="o-banknotes" size="sm" />
    @else
        <div class="divide-y divide-base-200">
            @foreach($currentAssets as $item)
            <div class="flex justify-between items-center py-2 text-sm">
                <span class="text-base-content/70 flex items-center gap-1.5">
                    <span class="font-mono text-primary text-xs">{{ $item['account']->code }}</span>
                    {{ $item['account']->name }}
                </span>
                <span class="font-semibold font-mono text-xs">{{ $currency }} {{ number_format($item['balance'], 2) }}</span>
            </div>
            @endforeach
            <div class="flex justify-between items-center py-2.5 mt-1 bg-info/10 rounded-lg px-2 font-bold text-sm">
                <span>Total</span>
                <span class="font-mono">{{ $currency }} {{ number_format($currentAssetTotal, 2) }}</span>
            </div>
        </div>
    @endif
</x-tallui-card>
