<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoginResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $twoFactor = [
            'required_for_financial_actions' => true,
            'session_token' => $this->resource['two_factor_session_token'],
            'setup_required' => $this->resource['two_factor_setup_required'],
        ];

        if ($this->resource['two_factor_setup_required']) {
            $twoFactor['secret'] = $this->resource['two_factor_secret'];
            $twoFactor['provisioning_uri'] = $this->resource['provisioning_uri'];
        }

        return [
            'auth_type' => 'Bearer',
            'email' => $this->resource['email'],
            'access_token' => $this->resource['access_token'],
            'token_type' => $this->resource['token_type'],
            'two_factor' => $twoFactor,
        ];
    }
}
