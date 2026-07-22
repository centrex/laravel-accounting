<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankStatementLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                            => $this->id,
            'bank_reconciliation_id'        => $this->bank_reconciliation_id,
            'transaction_date'              => $this->transaction_date?->toDateString(),
            'description'                   => $this->description,
            'amount'                        => $this->amount,
            'type'                          => $this->type,
            'external_reference'            => $this->external_reference,
            'matched_journal_entry_line_id' => $this->matched_journal_entry_line_id,
            'matched_at'                    => $this->matched_at?->toISOString(),
        ];
    }
}
