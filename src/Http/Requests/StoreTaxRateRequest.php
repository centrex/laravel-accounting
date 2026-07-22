<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaxRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $taxRateId = $this->route('taxRate')?->id;
        $prefix = config('accounting.table_prefix', 'acct_');
        $table = $prefix . 'tax_rates';

        return [
            'name'        => ['required', 'string', 'max:255'],
            'code'        => ['required', 'string', 'max:50', "unique:{$table},code,{$taxRateId}"],
            'rate'        => ['required', 'numeric', 'min:0', 'max:100'],
            'is_compound' => ['nullable', 'boolean'],
            'is_active'   => ['nullable', 'boolean'],
        ];
    }
}
