<?php

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeader;
use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

it('dashboard read route without 2FA returns 200', function (): void {
    [$user, $account, $accessToken] = twoFactorGateLoginUserWithAccount();

    withToken($accessToken)
        ->getJson("/api/accounts/{$account->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $account->id);
});

it('swap without 2FA returns 403 with two_factor_required flag', function (): void {
    [$user, $account, $accessToken] = twoFactorGateLoginUserWithAccount();
    $cny = Account::factory()->create(['user_id' => $user->id, 'currency' => 'CNY']);

    withToken($accessToken)
        ->postJson('/api/swap', [
            'source_account_id' => $account->id,
            'destination_account_id' => $cny->id,
            'amount_minor' => 1_000,
            'idempotency_key' => 'swap-without-2fa',
        ])
        ->assertForbidden()
        ->assertJsonPath('message', 'Two-factor verification required.')
        ->assertJsonPath('two_factor_required', true);
});

it('dashboard read route after 2FA still returns 200', function (): void {
    [$user, $account, $accessToken, $sessionToken] = twoFactorGateLoginUserWithAccount();

    withToken($accessToken)->postJson('/api/2fa/verify', [
        'code' => app(TwoFactorService::class)->totp($user->two_factor_secret),
        'session_token' => $sessionToken,
    ])->assertOk();

    withToken($accessToken);
    withHeader('X-Two-Factor-Session', $sessionToken);

    getJson("/api/accounts/{$account->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $account->id);
});

it('swap after 2FA verification succeeds', function (): void {
    [$user, $ngn, $accessToken, $sessionToken] = twoFactorGateLoginUserWithAccount(ngnBalance: 100_000);
    $cny = Account::factory()->create(['user_id' => $user->id, 'currency' => 'CNY', 'balance_minor' => 0]);

    ExchangeRate::create([
        'base_currency' => 'NGN',
        'quote_currency' => 'CNY',
        'rate_micro' => 500,
        'is_active' => true,
    ]);

    withToken($accessToken)->postJson('/api/2fa/verify', [
        'code' => app(TwoFactorService::class)->totp($user->two_factor_secret),
        'session_token' => $sessionToken,
    ])->assertOk();

    withToken($accessToken)->postJson('/api/swap', [
        'source_account_id' => $ngn->id,
        'destination_account_id' => $cny->id,
        'amount_minor' => 50_000,
        'idempotency_key' => 'swap-after-2fa',
    ], [
        'X-Two-Factor-Session' => $sessionToken,
    ])
        ->assertCreated()
        ->assertJsonPath('data.debit.balance_after_minor', 50_000)
        ->assertJsonPath('data.credit.balance_after_minor', 25);
});

it('transfer without 2FA returns 403', function (): void {
    [$user, $account, $accessToken] = twoFactorGateLoginUserWithAccount();
    $destination = Account::factory()->create(['currency' => 'NGN']);

    withToken($accessToken)
        ->postJson('/api/transfer', [
            'source_account_id' => $account->id,
            'destination_account_id' => $destination->id,
            'amount_minor' => 1_000,
            'idempotency_key' => 'transfer-without-2fa',
        ])
        ->assertForbidden()
        ->assertJsonPath('two_factor_required', true);
});

/**
 * @return array{0: User, 1: Account, 2: string, 3: string}
 */
function twoFactorGateLoginUserWithAccount(int $ngnBalance = 0): array
{
    $user = User::factory()->create(['password' => 'password']);
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'NGN', 'balance_minor' => $ngnBalance]);

    $loginResponse = postJson('/api/login', [
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
