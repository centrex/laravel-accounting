<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'bill_number' => $this->bill_number,
            'vendor_id'   => $this->vendor_id,
            'bill_date'   => $this->bill_date?->toDateString(),
            'due_date'    => $this->due_date?->toDateString(),
            'subtotal'    => $this->subtotal,
            'tax_amount'  => $this->tax_amount,
            'total'       => $this->total,
            'paid_amount' => $this->paid_amount,
            'balance'     => $this->balance,
            'currency'    => $this->currency,
            'status'      => $this->status,
            'notes'       => $this->notes,
            'vendor'      => new VendorResource($this->whenLoaded('vendor')),
            'items'       => BillItemResource::collection($this->whenLoaded('items')),
            'payments'    => PaymentResource::collection($this->whenLoaded('payments')),
            'created_at'  => $this->created_at?->toISOString(),
            'updated_at'  => $this->updated_at?->toISOString(),
        ];
    }
}
