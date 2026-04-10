<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date'         => ['required', 'date'],
            'amount'       => ['required', 'numeric', 'min:0.01'],
            'method'       => ['required', 'string', 'in:cash,check,bank_transfer,card,other'],
            'reference'    => ['nullable', 'string', 'max:255'],
            'notes'        => ['nullable', 'string'],
            'account_code' => ['nullable', 'string', 'max:50'],
        ];
    }
}
