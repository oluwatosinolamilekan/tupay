<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Seeder;

class TestUserSeeder extends Seeder
{
    private const EMAIL = 'test@example.com';

    private const TWO_FACTOR_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    public function run(): void
    {
        $user = User::firstOrNew(['email' => self::EMAIL]);
        $user->forceFill([
            'name' => 'Test User',
            'password' => 'password',
            'email_verified_at' => now(),
            'two_factor_secret' => self::TWO_FACTOR_SECRET,
            'two_factor_confirmed_at' => now(),
        ])->save();

        $account = Account::firstOrNew([
            'user_id' => $user->id,
            'currency' => 'NGN',
        ]);

        if (! $account->exists) {
            $account->account_number = $this->accountNumber();
        }

        $account->balance_minor = 100_000;
        $account->save();
    }

    private function accountNumber(): string
    {
        $accountNumber = 9_000_000_001;

        while (Account::query()->where('account_number', (string) $accountNumber)->exists()) {
            $accountNumber++;
        }

        return (string) $accountNumber;
    }
}
