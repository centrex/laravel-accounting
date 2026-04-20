<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Http\Resources;

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
            'exchange_rate'   => $this->exchange_rate,
            'base_currency'   => $this->base_currency,
            'base_subtotal'   => $this->base_subtotal,
            'base_tax_amount' => $this->base_tax_amount,
            'base_discount_amount' => $this->base_discount_amount,
            'base_total'      => $this->base_total,
            'base_paid_amount' => $this->base_paid_amount,
            'base_balance'    => $this->base_balance,
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
