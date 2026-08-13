<div>
    @if($qboStatus['configured'] ?? false)
        <a href="{{ $qboStatus['connect_url'] }}" class="group block">
            <div class="card bg-base-100 border border-base-200 shadow-sm hover:shadow-md hover:border-primary/40 transition-all rounded-2xl h-full">
                <div class="card-body p-4 gap-1">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium text-base-content/50 uppercase tracking-wide">QuickBooks Online</span>
                        <div class="w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center">
                            <x-tallui-icon name="o-arrow-path-rounded-square" class="w-4 h-4 text-primary" />
                        </div>
                    </div>

                    @if($qboStatus['connected'] ?? false)
                        <div class="text-xl font-bold mt-1">Connected</div>
                        <div class="flex items-center gap-1.5 flex-wrap mt-1">
                            <span class="text-xs text-base-content/40 font-mono">{{ $qboStatus['realm_id'] }}</span>
                            @if($qboStatus['refresh_expired'] ?? false)
                                <x-tallui-badge type="error" size="sm">Reauthorize required</x-tallui-badge>
                            @elseif($qboStatus['access_expired'] ?? false)
                                <x-tallui-badge type="warning" size="sm">Token refreshing</x-tallui-badge>
                            @else
                                <x-tallui-badge type="success" size="sm">Active</x-tallui-badge>
                            @endif
                        </div>
                    @else
                        <div class="text-xl font-bold mt-1">Not Connected</div>
                        <div class="flex items-center gap-1.5 flex-wrap mt-1">
                            <span class="text-xs text-base-content/40">Click to connect your QBO company</span>
                        </div>
                    @endif
                </div>
            </div>
        </a>
    @endif
</div>
