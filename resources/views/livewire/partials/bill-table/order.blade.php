@if ($row->inventory_purchase_order_id && Route::has('inventory.purchase-orders.show'))
    <a href="{{ route('inventory.purchase-orders.show', ['recordId' => $row->inventory_purchase_order_id]) }}" wire:navigate class="font-mono text-sm text-primary hover:underline">
        {{ $value }}
    </a>
@elseif ($row->source_type === \Centrex\Inventory\Models\Shipment::class && $row->source_id && Route::has('inventory.shipments.show'))
    {{-- Freight/customs/handling bill created from a shipment (see ErpIntegration::syncShipmentDocument()) --}}
    <a href="{{ route('inventory.shipments.show', ['recordId' => $row->source_id]) }}" wire:navigate class="font-mono text-sm text-primary hover:underline">
        {{ $value }}
    </a>
@elseif ($row->source_type === \Centrex\Inventory\Models\Transfer::class && $row->source_id && Route::has('inventory.transfers.show'))
    {{-- Freight bill created from an inter-warehouse transfer (see ErpIntegration::syncTransferDocument()) --}}
    <a href="{{ route('inventory.transfers.show', ['recordId' => $row->source_id]) }}" wire:navigate class="font-mono text-sm text-primary hover:underline">
        {{ $value }}
    </a>
@elseif ($value)
    <span class="font-mono text-sm text-base-content/70">{{ $value }}</span>
@else
    <span class="text-base-content/30">—</span>
@endif
