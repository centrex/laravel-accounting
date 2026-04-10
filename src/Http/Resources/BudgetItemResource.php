<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'budget_id'    => $this->budget_id,
            'account_id'   => $this->account_id,
            'description'  => $this->description,
            'amount'       => $this->amount,
            'spent'        => $this->spent,
            'remaining'    => $this->remaining,
            'percentage'   => $this->percentage_used,
            'period_start' => $this->period_start?->toDateString(),
            'period_end'   => $this->period_end?->toDateString(),
            'account'      => new AccountResource($this->whenLoaded('account')),
            'created_at'   => $this->created_at?->toISOString(),
        ];
    }
}
