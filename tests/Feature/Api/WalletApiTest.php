<?php

use App\Actions\VerifyTwoFactorAction;
use App\Models\Account;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

it('creates NGN account successfully', function (): void {
    $user = User::factory()->create();
    $sessionToken = walletApiVerifiedTwoFactorSession($user);

    postJson('/api/accounts', [
        'currency' => 'NGN',
    ], walletApiAuthHeaders($user, $sessionToken))
        ->assertCreated()
        ->assertJsonPath('data.user_id', $user->id)
        ->assertJsonPath('data.currency', 'NGN')
        ->assertJsonPath('data.balance_minor', 0);
});

it('creates CNY account successfully', function (): void {
    $user = User::factory()->create();
    $sessionToken = walletApiVerifiedTwoFactorSession($user);

    postJson('/api/accounts', [
        'currency' => 'CNY',
    ], walletApiAuthHeaders($user, $sessionToken))
        ->assertCreated()
        ->assertJsonPath('data.user_id', $user->id)
        ->assertJsonPath('data.currency', 'CNY')
        ->assertJsonPath('data.balance_minor', 0);
});

it('rejects duplicate account currency for a user', function (): void {
    $user = User::factory()->create();
    $sessionToken = walletApiVerifiedTwoFactorSession($user);

    $firstResponse = postJson('/api/accounts', [
        'currency' => 'ngn',
    ], walletApiAuthHeaders($user, $sessionToken));

    $firstResponse
        ->assertCreated()
        ->assertJsonPath('data.user_id', $user->id)
        ->assertJsonPath('data.currency', 'NGN')
        ->assertJsonPath('data.balance_minor', 0);

    postJson('/api/accounts', [
        'currency' => 'NGN',
    ], walletApiAuthHeaders($user, $sessionToken))
        ->assertUnprocessable()
        ->assertJsonPath(
            'errors.currency.0',
            'You already have a NGN account with account number: '.$firstResponse->json('data.account_number').'.',
        );
});

it('user cannot access another user account', function (): void {
    $user = User::factory()->create();
    $otherAccount = Account::factory()->create(['currency' => 'NGN']);
    $sessionToken = walletApiVerifiedTwoFactorSession($user);

    getJson("/api/accounts/{$otherAccount->id}", walletApiAuthHeaders($user, $sessionToken))
        ->assertNotFound();
});

it('deposits money idempotently', function (): void {
    $account = Account::factory()->create();
    $sessionToken = walletApiVerifiedTwoFactorSession($account->user);

    $payload = [
        'amount_minor' => 25_000,
        'idempotency_key' => 'deposit-123',
        'metadata' => ['provider' => 'bank-transfer'],
    ];

    postJson("/api/accounts/{$account->id}/deposits", $payload, walletApiAuthHeaders($account->user, $sessionToken))
        ->assertCreated()
        ->assertJsonPath('data.amount_minor', 25_000)
        ->assertJsonPath('data.balance_after_minor', 25_000);

    postJson("/api/accounts/{$account->id}/deposits", $payload, walletApiAuthHeaders($account->user, $sessionToken))
        ->assertOk()
        ->assertJsonPath('data.balance_after_minor', 25_000);

    expect($account->refresh()->balance_minor)->toBe(25_000)
        ->and(LedgerTransaction::query()->count())->toBe(1);
});

it('transfers money between same currency accounts', function (): void {
    $user = User::factory()->create();
    $source = Account::factory()->create(['user_id' => $user->id, 'currency' => 'NGN', 'balance_minor' => 75_000]);
    $destination = Account::factory()->create(['currency' => 'NGN', 'balance_minor' => 10_000]);
    $sessionToken = walletApiVerifiedTwoFactorSession($user);

    postJson('/api/transfer', [
        'source_account_id' => $source->id,
        'destination_account_id' => $destination->id,
        'amount_minor' => 30_000,
        'idempotency_key' => 'transfer-123',
    ], walletApiAuthHeaders($user, $sessionToken))
        ->assertCreated()
        ->assertJsonPath('data.debit.balance_after_minor', 45_000)
        ->assertJsonPath('data.credit.balance_after_minor', 40_000);

    expect($source->refresh()->balance_minor)->toBe(45_000)
        ->and($destination->refresh()->balance_minor)->toBe(40_000)
        ->and(LedgerTransaction::query()->count())->toBe(2);
});

it('transfer is idempotent with same key returning 200', function (): void {
    $user = User::factory()->create();
    $source = Account::factory()->create(['user_id' => $user->id, 'currency' => 'NGN', 'balance_minor' => 75_000]);
    $destination = Account::factory()->create(['currency' => 'NGN', 'balance_minor' => 10_000]);
    $sessionToken = walletApiVerifiedTwoFactorSession($user);
    $payload = [
        'source_account_id' => $source->id,
        'destination_account_id' => $destination->id,
        'amount_minor' => 30_000,
        'idempotency_key' => 'transfer-idempotent',
    ];

    $firstResponse = postJson('/api/transfer', $payload, walletApiAuthHeaders($user, $sessionToken))
        ->assertCreated();

    postJson('/api/transfer', $payload, walletApiAuthHeaders($user, $sessionToken))
        ->assertOk()
        ->assertJsonPath('data.debit.id', $firstResponse->json('data.debit.id'))
        ->assertJsonPath('data.credit.id', $firstResponse->json('data.credit.id'));

    expect($source->refresh()->balance_minor)->toBe(45_000)
        ->and($destination->refresh()->balance_minor)->toBe(40_000)
        ->and(LedgerTransaction::query()->where('idempotency_key', 'transfer-idempotent')->count())->toBe(2);
});

it('converts swap amounts with big integer precision', function (): void {
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

    expect($result['credit']->amount_minor)->toBe($amountMinor)
        ->and($source->refresh()->balance_minor)->toBe(0)
        ->and($destination->refresh()->balance_minor)->toBe($amountMinor)
        ->and(LedgerTransaction::query()->where('idempotency_key', 'swap-bigint-precision')->count())->toBe(2);
});

it('prevents invalid transfers', function (): void {
    $user = User::factory()->create();
    $source = Account::factory()->create(['user_id' => $user->id, 'currency' => 'NGN', 'balance_minor' => 10_000]);
    $destination = Account::factory()->create(['currency' => 'NGN', 'balance_minor' => 0]);
    $sessionToken = walletApiVerifiedTwoFactorSession($user);

    postJson('/api/transfer', [
        'source_account_id' => $source->id,
        'destination_account_id' => $source->id,
        'amount_minor' => 1_000,
        'idempotency_key' => 'transfer-same-account',
    ], walletApiAuthHeaders($user, $sessionToken))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('destination_account_id');

    postJson('/api/transfer', [
        'source_account_id' => $source->id,
        'destination_account_id' => $destination->id,
        'amount_minor' => 10_001,
        'idempotency_key' => 'transfer-insufficient',
    ], walletApiAuthHeaders($user, $sessionToken))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Insufficient funds.');

    $usdAccount = Account::factory()->create(['currency' => 'USD']);

    postJson('/api/transfer', [
        'source_account_id' => $source->id,
        'destination_account_id' => $usdAccount->id,
        'amount_minor' => 1_000,
        'idempotency_key' => 'transfer-currency-mismatch',
    ], walletApiAuthHeaders($user, $sessionToken))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Transfers are only supported between accounts with the same currency.');
});

it('prevents transfers from accounts owned by another user', function (): void {
    $user = User::factory()->create();
    $source = Account::factory()->create(['currency' => 'NGN', 'balance_minor' => 10_000]);
    $destination = Account::factory()->create(['user_id' => $user->id, 'currency' => 'NGN', 'balance_minor' => 0]);
    $sessionToken = walletApiVerifiedTwoFactorSession($user);

    postJson('/api/transfer', [
        'source_account_id' => $source->id,
        'destination_account_id' => $destination->id,
        'amount_minor' => 1_000,
        'idempotency_key' => 'transfer-other-user',
    ], walletApiAuthHeaders($user, $sessionToken))
        ->assertNotFound();

    expect($source->refresh()->balance_minor)->toBe(10_000)
        ->and($destination->refresh()->balance_minor)->toBe(0);
});

/**
 * @return array<string, string>
 */
function walletApiAuthHeaders(User $user, string $sessionToken): array
{
    return [
        'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
        'X-Two-Factor-Session' => $sessionToken,
    ];
}

function walletApiVerifiedTwoFactorSession(User $user): string
{
    $sessionToken = 'test-session-'.$user->id;

    Cache::put(VerifyTwoFactorAction::verifiedCacheKey($user, $sessionToken), true, now()->addMinutes(10));

    return $sessionToken;
}
