<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Services\TwoFactorService;
use Database\Seeders\TestUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TestUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_a_test_user_with_balance_and_active_two_factor_secret(): void
    {
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

        $this->assertSame('Test User', $user->name);
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertTrue(app(TwoFactorService::class)->verify(
            $user,
            app(TwoFactorService::class)->totp($user->two_factor_secret),
        ));
        $this->assertSame(100_000, $account->balance_minor);
        $this->assertSame(0, $cnyAccount->balance_minor);
        $this->assertSame(100_000, LedgerTransaction::query()->where('account_id', $account->id)->sum('amount_minor'));
        $this->assertDatabaseHas('exchange_rates', [
            'base_currency' => 'NGN',
            'quote_currency' => 'CNY',
            'rate_micro' => 500,
            'is_active' => true,
        ]);
    }
}
