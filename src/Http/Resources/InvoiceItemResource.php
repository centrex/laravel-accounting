<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'invoice_id'  => $this->invoice_id,
            'description' => $this->description,
            'quantity'    => $this->quantity,
            'unit_price'  => $this->unit_price,
            'amount'      => $this->amount,
            'tax_rate'    => $this->tax_rate,
            'tax_amount'  => $this->tax_amount,
            'reference'   => $this->reference,
        ];
    }
}
