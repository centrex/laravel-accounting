<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'code'          => $this->code,
            'name'          => $this->name,
            'email'         => $this->email,
            'phone'         => $this->phone,
            'address'       => $this->address,
            'city'          => $this->city,
            'country'       => $this->country,
            'tax_id'        => $this->tax_id,
            'currency'      => $this->currency,
            'credit_limit'  => $this->credit_limit,
            'payment_terms' => $this->payment_terms,
            'is_active'     => $this->is_active,
            'total_outstanding' => $this->when($request->has('with_outstanding'), fn () => $this->total_outstanding),
            'created_at'    => $this->created_at?->toISOString(),
            'updated_at'    => $this->updated_at?->toISOString(),
        ];
    }
}
