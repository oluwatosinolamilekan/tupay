<?php

namespace App\Http\Requests;

use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;

class CreateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'currency' => [
                'required',
                'string',
                'in:NGN,CNY',
                function (string $attribute, mixed $value, callable $fail): void {
                    $account = Account::query()
                        ->where('user_id', $this->integer('user_id'))
                        ->where('currency', $value)
                        ->first();

                    if ($account !== null) {
                        $fail("You already have a {$value} account with account number: {$account->account_number}.");
                    }
                },
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge([
                'currency' => strtoupper((string) $this->input('currency')),
            ]);
        }
    }

    public function messages(): array
    {
        return [
            'currency.in' => 'Currency must be NGN or CNY.',
        ];
    }
}
