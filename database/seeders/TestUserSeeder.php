<?php

namespace Database\Seeders;

use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Database\Seeder;

class TestUserSeeder extends Seeder
{
    private const EMAIL = 'test@example.com';

    private const TWO_FACTOR_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    public function run(WalletService $wallets): void
    {
        $user = User::firstOrNew(['email' => self::EMAIL]);
        $user->forceFill([
            'name' => 'Test User',
            'password' => 'password',
            'email_verified_at' => now(),
            'two_factor_secret' => self::TWO_FACTOR_SECRET,
            'two_factor_confirmed_at' => now(),
        ])->save();

        $ngnAccount = $wallets->createAccount($user, 'NGN');
        $wallets->createAccount($user, 'CNY');

        if ($ngnAccount->balance_minor === 0) {
            $wallets->deposit($ngnAccount, 100_000, 'seed:test-user:ngn-opening-balance', [
                'source' => 'TestUserSeeder',
                'purpose' => 'opening_balance',
            ]);
        }

        ExchangeRate::updateOrCreate([
            'base_currency' => 'NGN',
            'quote_currency' => 'CNY',
        ], [
            'rate_micro' => 500,
            'is_active' => true,
        ]);
    }
}
