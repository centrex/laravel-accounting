@if ($row->inventory_sale_order_id && Route::has('inventory.sale-orders.show'))
    <a href="{{ route('inventory.sale-orders.show', ['recordId' => $row->inventory_sale_order_id]) }}" wire:navigate class="font-mono text-sm text-primary hover:underline">
        {{ $value }}
    </a>
@elseif ($value)
    <span class="font-mono text-sm text-base-content/70">{{ $value }}</span>
@else
    <span class="text-base-content/30">—</span>
@endif
