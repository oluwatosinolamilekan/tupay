<?php

use App\Models\Account;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('financial route without 2FA returns 403 with two_factor_required flag', function (): void {
    [$user, $account, $accessToken] = twoFactorGateLoginUserWithAccount($this);

    $this->withToken($accessToken)
        ->getJson("/api/accounts/{$account->id}")
        ->assertForbidden()
        ->assertJsonPath('message', 'Two-factor verification required.')
        ->assertJsonPath('two_factor_required', true);
});

it('financial route after 2FA returns 200', function (): void {
    [$user, $account, $accessToken, $sessionToken] = twoFactorGateLoginUserWithAccount($this);

    $this->withToken($accessToken)->postJson('/api/2fa/verify', [
        'code' => app(TwoFactorService::class)->totp($user->two_factor_secret),
        'session_token' => $sessionToken,
    ])->assertOk();

    $this->withToken($accessToken)
        ->withHeader('X-Two-Factor-Session', $sessionToken)
        ->getJson("/api/accounts/{$account->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $account->id);
});

/**
 * @return array{0: User, 1: Account, 2: string, 3: string}
 */
function twoFactorGateLoginUserWithAccount($test): array
{
    $user = User::factory()->create(['password' => 'password']);
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'NGN']);

    $loginResponse = $test->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    return [
        $user->refresh(),
        $account,
        $loginResponse->json('access_token'),
        $loginResponse->json('two_factor.session_token'),
    ];
}
