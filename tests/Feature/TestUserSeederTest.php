<?php

use App\Models\Account;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Services\TwoFactorService;
use Database\Seeders\TestUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('seeds a test user with balance and active two factor secret', function (): void {
    $this->seed(TestUserSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $account = Account::query()
        ->whereBelongsTo($user)
        ->where('currency', 'NGN')
        ->firstOrFail();
    $cnyAccount = Account::query()
        ->whereBelongsTo($user)
        ->where('currency', 'CNY')
        ->firstOrFail();

    expect($user->name)->toBe('Test User')
        ->and(Hash::check('password', $user->password))->toBeTrue()
        ->and($user->two_factor_secret)->not->toBeNull()
        ->and($user->two_factor_confirmed_at)->not->toBeNull()
        ->and(app(TwoFactorService::class)->verify(
            $user,
            app(TwoFactorService::class)->totp($user->two_factor_secret),
        ))->toBeTrue()
        ->and($account->balance_minor)->toBe(100_000)
        ->and($cnyAccount->balance_minor)->toBe(0)
        ->and(LedgerTransaction::query()->where('account_id', $account->id)->sum('amount_minor'))->toBe(100_000);

    $this->assertDatabaseHas('exchange_rates', [
        'base_currency' => 'NGN',
        'quote_currency' => 'CNY',
        'rate_micro' => 500,
        'is_active' => true,
    ]);
});
