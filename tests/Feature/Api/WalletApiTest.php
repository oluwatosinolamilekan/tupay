<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_duplicate_account_currency_for_a_user(): void
    {
        $user = User::factory()->create();

        $firstResponse = $this->postJson('/api/accounts', [
            'user_id' => $user->id,
            'currency' => 'ngn',
        ]);

        $firstResponse
            ->assertCreated()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.currency', 'NGN')
            ->assertJsonPath('data.balance_minor', 0);

        $secondResponse = $this->postJson('/api/accounts', [
            'user_id' => $user->id,
            'currency' => 'NGN',
        ]);

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

        $payload = [
            'amount_minor' => 25_000,
            'idempotency_key' => 'deposit-123',
            'metadata' => ['provider' => 'bank-transfer'],
        ];

        $this->postJson("/api/accounts/{$account->id}/deposits", $payload)
            ->assertCreated()
            ->assertJsonPath('data.amount_minor', 25_000)
            ->assertJsonPath('data.balance_after_minor', 25_000);

        $this->postJson("/api/accounts/{$account->id}/deposits", $payload)
            ->assertOk()
            ->assertJsonPath('data.balance_after_minor', 25_000);

        $this->assertSame(25_000, $account->refresh()->balance_minor);
        $this->assertSame(1, LedgerTransaction::query()->count());
    }

    public function test_it_transfers_money_between_same_currency_accounts(): void
    {
        $source = Account::factory()->create(['currency' => 'NGN', 'balance_minor' => 75_000]);
        $destination = Account::factory()->create(['currency' => 'NGN', 'balance_minor' => 10_000]);

        $this->postJson('/api/transfers', [
            'source_account_id' => $source->id,
            'destination_account_id' => $destination->id,
            'amount_minor' => 30_000,
            'idempotency_key' => 'transfer-123',
        ])
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
        $source = Account::factory()->create(['currency' => 'NGN', 'balance_minor' => 10_000]);
        $destination = Account::factory()->create(['currency' => 'NGN', 'balance_minor' => 0]);

        $this->postJson('/api/transfers', [
            'source_account_id' => $source->id,
            'destination_account_id' => $destination->id,
            'amount_minor' => 10_001,
            'idempotency_key' => 'transfer-insufficient',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Insufficient funds.');

        $usdAccount = Account::factory()->create(['currency' => 'USD']);

        $this->postJson('/api/transfers', [
            'source_account_id' => $source->id,
            'destination_account_id' => $usdAccount->id,
            'amount_minor' => 1_000,
            'idempotency_key' => 'transfer-currency-mismatch',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Transfers are only supported between accounts with the same currency.');
    }
}
