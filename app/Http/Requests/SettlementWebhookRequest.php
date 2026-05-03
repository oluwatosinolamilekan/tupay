<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettlementWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider_reference' => ['required', 'string', 'max:128'],
            'account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where('currency', 'CNY'),
            ],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'in:CNY'],
            'status' => ['required', 'in:completed'],
        ];
    }
}
