<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $prefix = config('accounting.table_prefix', 'acct_');

        return [
            'date'               => ['required', 'date'],
            'reference'          => ['nullable', 'string', 'max:255'],
            'type'               => ['nullable', 'string', 'in:general,opening,closing,adjusting'],
            'description'        => ['nullable', 'string', 'max:1000'],
            'currency'           => ['nullable', 'string', 'size:3'],
            'exchange_rate'      => ['nullable', 'numeric', 'min:0.000001'],
            'lines'              => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', "exists:{$prefix}accounts,id"],
            'lines.*.type'       => ['required', 'string', 'in:debit,credit'],
            'lines.*.amount'     => ['required', 'numeric', 'min:0.01'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
