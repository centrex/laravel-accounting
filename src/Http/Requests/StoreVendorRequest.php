<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $vendorId = $this->route('vendor')?->id;
        $prefix = config('accounting.table_prefix', 'acct_');
        $table = $prefix . 'vendors';

        return [
            'code'          => ['required', 'string', 'max:50', "unique:{$table},code,{$vendorId}"],
            'name'          => ['required', 'string', 'max:255'],
            'email'         => ['nullable', 'email', 'max:255'],
            'phone'         => ['nullable', 'string', 'max:50'],
            'address'       => ['nullable', 'string'],
            'city'          => ['nullable', 'string', 'max:100'],
            'country'       => ['nullable', 'string', 'max:100'],
            'tax_id'        => ['nullable', 'string', 'max:100'],
            'currency'      => ['nullable', 'string', 'size:3'],
            'payment_terms' => ['nullable', 'integer', 'min:0'],
            'is_active'     => ['nullable', 'boolean'],
        ];
    }
}
