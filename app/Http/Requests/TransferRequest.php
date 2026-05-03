<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'destination_account_id' => ['required', 'integer', 'different:source_account_id', 'exists:accounts,id'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
