<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'entry_number'  => $this->entry_number,
            'date'          => $this->date?->toDateString(),
            'reference'     => $this->reference,
            'type'          => $this->type,
            'description'   => $this->description,
            'currency'      => $this->currency,
            'exchange_rate' => $this->exchange_rate,
            'status'        => $this->status,
            'is_balanced'   => $this->when(!$this->relationLoaded('lines'), null, fn () => $this->isBalanced()),
            'total_debits'  => $this->when($this->relationLoaded('lines'), fn () => $this->lines->where('type', 'debit')->sum('amount')),
            'lines'         => JournalEntryLineResource::collection($this->whenLoaded('lines')),
            'creator'       => $this->whenLoaded('creator', fn (): array => [
                'id'   => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'approved_at' => $this->approved_at?->toISOString(),
            'created_at'  => $this->created_at?->toISOString(),
            'updated_at'  => $this->updated_at?->toISOString(),
        ];
    }
}
