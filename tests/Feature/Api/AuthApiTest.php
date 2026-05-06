<?php

use App\Actions\VerifyTwoFactorAction;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

it('login returns token, session token, and two-factor required flag', function (): void {
    $user = User::factory()->create(['password' => 'password']);

    postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonPath('auth_type', 'Bearer')
        ->assertJsonPath('two_factor.required_for_financial_actions', true)
        ->assertJsonStructure([
            'access_token',
            'token_type',
            'two_factor' => [
                'session_token',
                'required_for_financial_actions',
                'setup_required',
            ],
        ]);
});

it('2FA verify with valid code returns 200 and stamps verified cache key', function (): void {
    $user = User::factory()->create([
        'password' => 'password',
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
    ]);

    $loginResponse = postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    $sessionToken = $loginResponse->json('two_factor.session_token');

    withToken($loginResponse->json('access_token'))->postJson('/api/2fa/verify', [
        'code' => app(TwoFactorService::class)->totp($user->two_factor_secret),
        'session_token' => $sessionToken,
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Two-factor verification accepted.');

    expect(Cache::has(VerifyTwoFactorAction::verifiedCacheKey($user, $sessionToken)))->toBeTrue()
        ->and(Cache::has(VerifyTwoFactorAction::pendingCacheKey($user, $sessionToken)))->toBeFalse();
});

it('stores two-factor secrets encrypted at rest', function (): void {
    $user = User::factory()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
    ]);

    $storedSecret = DB::table('users')->where('id', $user->id)->value('two_factor_secret');

    expect($user->two_factor_secret)->toBe('JBSWY3DPEHPK3PXP')
        ->and($storedSecret)->not->toBe('JBSWY3DPEHPK3PXP');
});

it('login re-encrypts a legacy plaintext two-factor secret', function (): void {
    $user = User::factory()->create(['password' => 'password']);

    DB::table('users')->where('id', $user->id)->update([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
    ]);

    postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonPath('two_factor.setup_required', false);

    $storedSecret = DB::table('users')->where('id', $user->id)->value('two_factor_secret');

    expect($user->fresh()->two_factor_secret)->toBe('JBSWY3DPEHPK3PXP')
        ->and($storedSecret)->not->toBe('JBSWY3DPEHPK3PXP');
});

it('login replaces an unreadable two-factor secret payload', function (): void {
    $user = User::factory()->create(['password' => 'password']);

    DB::table('users')->where('id', $user->id)->update([
        'two_factor_secret' => 'not-a-valid-encrypted-payload',
    ]);

    postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonPath('two_factor.setup_required', true)
        ->assertJsonStructure([
            'two_factor' => [
                'secret',
                'provisioning_uri',
            ],
        ]);

    expect($user->fresh()->two_factor_secret)->not->toBeNull();
});

it('login with invalid credentials returns 422', function (): void {
    $user = User::factory()->create(['password' => 'password']);

    postJson('/api/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Invalid credentials.');
});

it('2FA verify with invalid code returns 422', function (): void {
    $user = User::factory()->create([
        'password' => 'password',
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
    ]);

    $loginResponse = postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    $validCode = app(TwoFactorService::class)->totp($user->two_factor_secret);
    $invalidCode = str_pad((string) (((int) $validCode + 1) % 1_000_000), 6, '0', STR_PAD_LEFT);

    withToken($loginResponse->json('access_token'))->postJson('/api/2fa/verify', [
        'code' => $invalidCode,
        'session_token' => $loginResponse->json('two_factor.session_token'),
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Invalid two-factor code.');
});

it('2FA verify with expired or missing session token returns 422', function (): void {
    $user = User::factory()->create([
        'password' => 'password',
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
    ]);

    $loginResponse = postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    withToken($loginResponse->json('access_token'))->postJson('/api/2fa/verify', [
        'code' => app(TwoFactorService::class)->totp($user->two_factor_secret),
        'session_token' => 'missing-session-token',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Invalid two-factor code.');

    $expiredSessionToken = $loginResponse->json('two_factor.session_token');
    Cache::forget(VerifyTwoFactorAction::pendingCacheKey($user, $expiredSessionToken));

    withToken($loginResponse->json('access_token'))->postJson('/api/2fa/verify', [
        'code' => app(TwoFactorService::class)->totp($user->two_factor_secret),
        'session_token' => $expiredSessionToken,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Invalid two-factor code.');
});
