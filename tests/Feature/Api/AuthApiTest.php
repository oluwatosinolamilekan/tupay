<?php

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

it('login returns token and session token', function (): void {
    $user = User::factory()->create(['password' => 'password']);

    postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonPath('auth_type', 'Bearer')
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
