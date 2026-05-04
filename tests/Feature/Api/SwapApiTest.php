<?php

use App\Actions\VerifyTwoFactorAction;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\LedgerTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

it('swap debits NGN and credits CNY correctly', function (): void {
    [$user, $ngn, $cny, $headers] = swapApiFixture();

    postJson('/api/swap', [
        'source_account_id' => $ngn->id,
        'destination_account_id' => $cny->id,
        'amount_minor' => 50_000,
        'idempotency_key' => 'swap-success',
    ], $headers)
        ->assertCreated()
        ->assertJsonPath('data.debit.amount_minor', 50_000)
        ->assertJsonPath('data.debit.balance_after_minor', 50_000)
        ->assertJsonPath('data.credit.amount_minor', 25)
        ->assertJsonPath('data.credit.balance_after_minor', 25);

    expect($ngn->refresh()->balance_minor)->toBe(50_000)
        ->and($cny->refresh()->balance_minor)->toBe(25);
});

it('swap records rate_micro in metadata on both ledger entries', function (): void {
    [$user, $ngn, $cny, $headers] = swapApiFixture();

    postJson('/api/swap', [
        'source_account_id' => $ngn->id,
        'destination_account_id' => $cny->id,
        'amount_minor' => 50_000,
        'idempotency_key' => 'swap-rate-metadata',
    ], $headers)
        ->assertCreated()
        ->assertJsonPath('data.debit.metadata.rate_micro', 500)
        ->assertJsonPath('data.credit.metadata.rate_micro', 500);

    $transactions = LedgerTransaction::query()
        ->where('idempotency_key', 'swap-rate-metadata')
        ->get();

    expect($transactions)->toHaveCount(2)
        ->and($transactions->every(fn (LedgerTransaction $transaction): bool => $transaction->metadata['rate_micro'] === 500))->toBeTrue();
});

it('swap with insufficient funds returns 422', function (): void {
    [$user, $ngn, $cny, $headers] = swapApiFixture(ngnBalance: 10_000);

    postJson('/api/swap', [
        'source_account_id' => $ngn->id,
        'destination_account_id' => $cny->id,
        'amount_minor' => 10_001,
        'idempotency_key' => 'swap-insufficient',
    ], $headers)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Insufficient funds.');

    expect($ngn->refresh()->balance_minor)->toBe(10_000)
        ->and($cny->refresh()->balance_minor)->toBe(0);
});

it('swap is idempotent with same key returning 200 with same records', function (): void {
    [$user, $ngn, $cny, $headers] = swapApiFixture();
    $payload = [
        'source_account_id' => $ngn->id,
        'destination_account_id' => $cny->id,
        'amount_minor' => 50_000,
        'idempotency_key' => 'swap-idempotent',
    ];

    $firstResponse = postJson('/api/swap', $payload, $headers)
        ->assertCreated();

    $secondResponse = postJson('/api/swap', $payload, $headers)
        ->assertOk()
        ->assertJsonPath('data.debit.id', $firstResponse->json('data.debit.id'))
        ->assertJsonPath('data.credit.id', $firstResponse->json('data.credit.id'));

    expect($secondResponse->json('data.debit.reference'))->toBe($firstResponse->json('data.debit.reference'))
        ->and($secondResponse->json('data.credit.reference'))->toBe($firstResponse->json('data.credit.reference'))
        ->and($ngn->refresh()->balance_minor)->toBe(50_000)
        ->and($cny->refresh()->balance_minor)->toBe(25)
        ->and(LedgerTransaction::query()->where('idempotency_key', 'swap-idempotent')->count())->toBe(2);
});

it('swap wrong direction CNY to NGN returns 422', function (): void {
    [$user, $ngn, $cny, $headers] = swapApiFixture(cnyBalance: 100);

    postJson('/api/swap', [
        'source_account_id' => $cny->id,
        'destination_account_id' => $ngn->id,
        'amount_minor' => 50,
        'idempotency_key' => 'swap-wrong-direction',
    ], $headers)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Swaps are only supported from NGN to CNY.');

    expect($ngn->refresh()->balance_minor)->toBe(100_000)
        ->and($cny->refresh()->balance_minor)->toBe(100);
});

it('concurrent swap returns 423 when lock is held', function (): void {
    [$user, $ngn, $cny, $headers] = swapApiFixture();
    $lock = Cache::lock("swap:user:{$user->id}", 10);

    expect($lock->get())->toBeTrue();

    try {
        postJson('/api/swap', [
            'source_account_id' => $ngn->id,
            'destination_account_id' => $cny->id,
            'amount_minor' => 50_000,
            'idempotency_key' => 'swap-lock-held',
        ], $headers)
            ->assertStatus(423)
            ->assertJsonPath('message', 'Another swap is already in progress.');
    } finally {
        $lock->release();
    }
});

it('swap amount too small for rate returns 422', function (): void {
    [$user, $ngn, $cny, $headers] = swapApiFixture();

    postJson('/api/swap', [
        'source_account_id' => $ngn->id,
        'destination_account_id' => $cny->id,
        'amount_minor' => 1,
        'idempotency_key' => 'swap-too-small',
    ], $headers)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Swap amount is too small for the configured exchange rate.');

    expect($ngn->refresh()->balance_minor)->toBe(100_000)
        ->and($cny->refresh()->balance_minor)->toBe(0);
});

/**
 * @return array{0: User, 1: Account, 2: Account, 3: array<string, string>}
 */
function swapApiFixture(int $ngnBalance = 100_000, int $cnyBalance = 0): array
{
    $user = User::factory()->create();
    $ngn = Account::factory()->create(['user_id' => $user->id, 'currency' => 'NGN', 'balance_minor' => $ngnBalance]);
    $cny = Account::factory()->create(['user_id' => $user->id, 'currency' => 'CNY', 'balance_minor' => $cnyBalance]);

    ExchangeRate::create([
        'base_currency' => 'NGN',
        'quote_currency' => 'CNY',
        'rate_micro' => 500,
        'is_active' => true,
    ]);

    $sessionToken = 'test-session-'.$user->id;
    Cache::put(VerifyTwoFactorAction::verifiedCacheKey($user, $sessionToken), true, now()->addMinutes(10));

    return [
        $user,
        $ngn,
        $cny,
        [
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
            'X-Two-Factor-Session' => $sessionToken,
        ],
    ];
}
