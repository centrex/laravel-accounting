<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'payment_number' => $this->payment_number,
            'payable_type'   => $this->payable_type,
            'payable_id'     => $this->payable_id,
            'payment_date'   => $this->payment_date?->toDateString(),
            'amount'         => $this->amount,
            'payment_method' => $this->payment_method,
            'reference'      => $this->reference,
            'notes'          => $this->notes,
            'created_at'     => $this->created_at?->toISOString(),
        ];
    }
}
