<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $prefix = config('accounting.table_prefix', 'acct_');

        return [
            'vendor_id'           => ['required', 'integer', "exists:{$prefix}vendors,id"],
            'bill_date'           => ['required', 'date'],
            'due_date'            => ['required', 'date', 'after_or_equal:bill_date'],
            'currency'            => ['nullable', 'string', 'size:3'],
            'notes'               => ['nullable', 'string'],
            'items'               => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity'    => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price'  => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate'    => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
