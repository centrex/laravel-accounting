<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankReconciliationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                       => $this->id,
            'account_id'               => $this->account_id,
            'statement_date'           => $this->statement_date?->toDateString(),
            'opening_balance'          => $this->opening_balance,
            'statement_ending_balance' => $this->statement_ending_balance,
            'status'                   => $this->status?->value,
            'reconciled_by'            => $this->reconciled_by,
            'reconciled_at'            => $this->reconciled_at?->toISOString(),
            'notes'                    => $this->notes,
            'statement_lines'          => BankStatementLineResource::collection($this->whenLoaded('statementLines')),
            'created_at'               => $this->created_at?->toISOString(),
            'updated_at'               => $this->updated_at?->toISOString(),
        ];
    }
}
