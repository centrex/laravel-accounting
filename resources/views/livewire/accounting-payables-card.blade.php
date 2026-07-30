<a href="{{ route('accounting.bills') }}" wire:navigate class="group block">
    <div class="card bg-base-100 border border-base-200 shadow-sm hover:shadow-md hover:border-warning/40 transition-all rounded-2xl h-full">
        <div class="card-body p-4 gap-1">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-base-content/50 uppercase tracking-wide">Payables</span>
                <div class="w-8 h-8 rounded-lg bg-warning/10 flex items-center justify-center">
                    <x-tallui-icon name="o-archive-box-arrow-down" class="w-4 h-4 text-warning" />
                </div>
            </div>
            <div class="text-xl font-bold mt-1">{{ $currency }} {{ number_format($billStats['outstanding_ap'], 2) }}</div>
            <div class="flex items-center gap-1.5 flex-wrap mt-1">
                @if($billStats['overdue_count'] > 0)
                    <x-tallui-badge type="error" size="sm">{{ $billStats['overdue_count'] }} overdue</x-tallui-badge>
                @endif
                @if($billStats['sent_count'] > 0)
                    <x-tallui-badge type="info" size="sm">{{ $billStats['sent_count'] }} sent</x-tallui-badge>
                @endif
                @if($billStats['partial_count'] > 0)
                    <x-tallui-badge type="warning" size="sm">{{ $billStats['partial_count'] }} partial</x-tallui-badge>
                @endif
                @if(!$billStats['overdue_count'] && !$billStats['sent_count'] && !$billStats['partial_count'])
                    <span class="text-xs text-base-content/40">All clear</span>
                @endif
            </div>
        </div>
    </div>
</a>
