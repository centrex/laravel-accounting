<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'budget_number'   => $this->budget_number,
            'name'            => $this->name,
            'fiscal_year_id'  => $this->fiscal_year_id,
            'period_start'    => $this->period_start?->toDateString(),
            'period_end'      => $this->period_end?->toDateString(),
            'total_amount'    => $this->total_amount,
            'total_allocated' => $this->total_allocated,
            'remaining'       => $this->remaining,
            'currency'        => $this->currency,
            'status'          => $this->status,
            'notes'           => $this->notes,
            'approved_by'     => $this->approved_by,
            'approved_at'     => $this->approved_at?->toISOString(),
            'fiscal_year'     => new FiscalYearResource($this->whenLoaded('fiscalYear')),
            'items'           => BudgetItemResource::collection($this->whenLoaded('items')),
            'created_at'      => $this->created_at?->toISOString(),
            'updated_at'      => $this->updated_at?->toISOString(),
        ];
    }
}
