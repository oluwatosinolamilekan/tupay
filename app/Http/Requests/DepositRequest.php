<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount_minor' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
