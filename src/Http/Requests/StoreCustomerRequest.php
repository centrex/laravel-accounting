<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $customerId = $this->route('customer')?->id;
        $prefix = config('accounting.table_prefix', 'acct_');
        $table = $prefix . 'customers';

        return [
            'code'          => ['required', 'string', 'max:50', "unique:{$table},code,{$customerId}"],
            'name'          => ['required', 'string', 'max:255'],
            'email'         => ['nullable', 'email', 'max:255'],
            'phone'         => ['nullable', 'string', 'max:50'],
            'address'       => ['nullable', 'string'],
            'city'          => ['nullable', 'string', 'max:100'],
            'country'       => ['nullable', 'string', 'max:100'],
            'tax_id'        => ['nullable', 'string', 'max:100'],
            'currency'      => ['nullable', 'string', 'size:3'],
            'credit_limit'  => ['nullable', 'numeric', 'min:0'],
            'payment_terms' => ['nullable', 'integer', 'min:0'],
            'is_active'     => ['nullable', 'boolean'],
        ];
    }
}
