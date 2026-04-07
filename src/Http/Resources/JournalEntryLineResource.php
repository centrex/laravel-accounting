<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalEntryLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'account_id'  => $this->account_id,
            'type'        => $this->type,
            'amount'      => $this->amount,
            'description' => $this->description,
            'reference'   => $this->reference,
            'account'     => new AccountResource($this->whenLoaded('account')),
        ];
    }
}
