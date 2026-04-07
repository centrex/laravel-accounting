<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'code'        => $this->code,
            'name'        => $this->name,
            'type'        => $this->type,
            'subtype'     => $this->subtype,
            'parent_id'   => $this->parent_id,
            'description' => $this->description,
            'currency'    => $this->currency,
            'is_active'   => $this->is_active,
            'is_system'   => $this->is_system,
            'level'       => $this->level,
            'balance'     => $this->when($request->has('with_balance'), fn () => $this->getCurrentBalance()),
            'parent'      => new AccountResource($this->whenLoaded('parent')),
            'children'    => AccountResource::collection($this->whenLoaded('children')),
            'created_at'  => $this->created_at?->toISOString(),
            'updated_at'  => $this->updated_at?->toISOString(),
        ];
    }
}
