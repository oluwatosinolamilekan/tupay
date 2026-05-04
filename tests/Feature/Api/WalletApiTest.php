<?php

namespace Tests\Feature\Api;

use App\Actions\VerifyTwoFactorAction;
use App\Models\Account;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WalletApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_duplicate_account_currency_for_a_user(): void
    {
        $user = User::factory()->create();
        $sessionToken = $this->verifiedTwoFactorSession($user);

        $firstResponse = $this->postJson('/api/accounts', [
            'currency' => 'ngn',
        ], $this->authHeaders($user, $sessionToken));

        $firstResponse
            ->assertCreated()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.currency', 'NGN')
            ->assertJsonPath('data.balance_minor', 0);

        $secondResponse = $this->postJson('/api/accounts', [
            'currency' => 'NGN',
        ], $this->authHeaders($user, $sessionToken));

        $secondResponse
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.currency.0',
                'You already have a NGN account with account number: '.$firstResponse->json('data.account_number').'.',
            );
    }

    public function test_it_deposits_money_idempotently(): void
    {
        $account = Account::factory()->create();
        $sessionToken = $this->verifiedTwoFactorSession($account->user);

        $payload = [
            'amount_minor' => 25_000,
            'idempotency_key' => 'deposit-123',
            'metadata' => ['provider' => 'bank-transfer'],
        ];

        $this->postJson("/api/accounts/{$account->id}/deposits", $payload, $this->authHeaders($account->user, $sessionToken))
            ->assertCreated()
            ->assertJsonPath('data.amount_minor', 25_000)
            ->assertJsonPath('data.balance_after_minor', 25_000);

        $this->postJson("/api/accounts/{$account->id}/deposits", $payload, $this->authHeaders($account->user, $sessionToken))
            ->assertOk()
            ->assertJsonPath('data.balance_after_minor', 25_000);

        $this->assertSame(25_000, $account->refresh()->balance_minor);
        $this->assertSame(1, LedgerTransaction::query()->count());
    }

    public function test_it_transfers_money_between_same_currency_accounts(): void
    {
        $user = User::factory()->create();
        $source = Account::factory()->create(['user_id' => $user->id, 'currency' => 'NGN', 'balance_minor' => 75_000]);
        $destination = Account::factory()->create(['currency' => 'NGN', 'balance_minor' => 10_000]);
        $sessionToken = $this->verifiedTwoFactorSession($user);

        $this->postJson('/api/transfer', [
            'source_account_id' => $source->id,
            'destination_account_id' => $destination->id,
            'amount_minor' => 30_000,
            'idempotency_key' => 'transfer-123',
        ], $this->authHeaders($user, $sessionToken))
            ->assertCreated()
            ->assertJsonPath('data.debit.balance_after_minor', 45_000)
            ->assertJsonPath('data.credit.balance_after_minor', 40_000);

        $this->assertSame(45_000, $source->refresh()->balance_minor);
        $this->assertSame(40_000, $destination->refresh()->balance_minor);
        $this->assertSame(2, LedgerTransaction::query()->count());
    }

    public function test_it_converts_swap_amounts_with_big_integer_precision(): void
    {
        $amountMinor = 9_223_372_036_855;
        $source = Account::factory()->create(['currency' => 'NGN', 'balance_minor' => $amountMinor]);
        $destination = Account::factory()->create(['currency' => 'CNY', 'balance_minor' => 0]);

        $result = app(WalletService::class)->swap(
            source: $source,
            destination: $destination,
            amountMinor: $amountMinor,
            rateMicro: 1_000_000,
            idempotencyKey: 'swap-bigint-precision',
        );

        $this->assertSame($amountMinor, $result['credit']->amount_minor);
        $this->assertSame(0, $source->refresh()->balance_minor);
        $this->assertSame($amountMinor, $destination->refresh()->balance_minor);
        $this->assertSame(2, LedgerTransaction::query()->where('idempotency_key', 'swap-bigint-precision')->count());
    }

    public function test_it_prevents_invalid_transfers(): void
    {
        $user = User::factory()->create();
        $source = Account::factory()->create(['user_id' => $user->id, 'currency' => 'NGN', 'balance_minor' => 10_000]);
        $destination = Account::factory()->create(['currency' => 'NGN', 'balance_minor' => 0]);
        $sessionToken = $this->verifiedTwoFactorSession($user);

        $this->postJson('/api/transfer', [
            'source_account_id' => $source->id,
            'destination_account_id' => $destination->id,
            'amount_minor' => 10_001,
            'idempotency_key' => 'transfer-insufficient',
        ], $this->authHeaders($user, $sessionToken))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Insufficient funds.');

        $usdAccount = Account::factory()->create(['currency' => 'USD']);

        $this->postJson('/api/transfer', [
            'source_account_id' => $source->id,
            'destination_account_id' => $usdAccount->id,
            'amount_minor' => 1_000,
            'idempotency_key' => 'transfer-currency-mismatch',
        ], $this->authHeaders($user, $sessionToken))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Transfers are only supported between accounts with the same currency.');
    }

    public function test_it_prevents_transfers_from_accounts_owned_by_another_user(): void
    {
        $user = User::factory()->create();
        $source = Account::factory()->create(['currency' => 'NGN', 'balance_minor' => 10_000]);
        $destination = Account::factory()->create(['user_id' => $user->id, 'currency' => 'NGN', 'balance_minor' => 0]);
        $sessionToken = $this->verifiedTwoFactorSession($user);

        $this->postJson('/api/transfer', [
            'source_account_id' => $source->id,
            'destination_account_id' => $destination->id,
            'amount_minor' => 1_000,
            'idempotency_key' => 'transfer-other-user',
        ], $this->authHeaders($user, $sessionToken))
            ->assertNotFound();

        $this->assertSame(10_000, $source->refresh()->balance_minor);
        $this->assertSame(0, $destination->refresh()->balance_minor);
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(User $user, string $sessionToken): array
    {
        return [
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
            'X-Two-Factor-Session' => $sessionToken,
        ];
    }

    private function verifiedTwoFactorSession(User $user): string
    {
        $sessionToken = 'test-session-'.$user->id;

        Cache::put(VerifyTwoFactorAction::verifiedCacheKey($user, $sessionToken), true, now()->addMinutes(10));

        return $sessionToken;
    }
}
