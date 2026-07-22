<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBankReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $prefix = config('accounting.table_prefix', 'acct_');

        return [
            'account_id'               => ['required', 'integer', "exists:{$prefix}accounts,id"],
            'statement_date'           => ['required', 'date'],
            'opening_balance'          => ['required', 'numeric'],
            'statement_ending_balance' => ['required', 'numeric'],
            'notes'                    => ['nullable', 'string'],
        ];
    }
}
