<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'invoice_number'  => $this->invoice_number,
            'customer_id'     => $this->customer_id,
            'invoice_date'    => $this->invoice_date?->toDateString(),
            'due_date'        => $this->due_date?->toDateString(),
            'subtotal'        => $this->subtotal,
            'tax_amount'      => $this->tax_amount,
            'discount_amount' => $this->discount_amount,
            'total'           => $this->total,
            'paid_amount'     => $this->paid_amount,
            'balance'         => $this->balance,
            'currency'        => $this->currency,
            'status'          => $this->status,
            'notes'           => $this->notes,
            'customer'        => new CustomerResource($this->whenLoaded('customer')),
            'items'           => InvoiceItemResource::collection($this->whenLoaded('items')),
            'payments'        => PaymentResource::collection($this->whenLoaded('payments')),
            'created_at'      => $this->created_at?->toISOString(),
            'updated_at'      => $this->updated_at?->toISOString(),
        ];
    }
}
