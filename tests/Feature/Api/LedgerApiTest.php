<?php

use App\Actions\VerifyTwoFactorAction;
use App\Enums\LedgerTransactionDirection;
use App\Enums\LedgerTransactionStatus;
use App\Enums\LedgerTransactionType;
use App\Models\Account;
use App\Models\LedgerTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

it('returns paginated transactions ordered by created_at desc then id desc', function (): void {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'NGN', 'balance_minor' => 3_000]);
    $headers = ledgerApiAuthHeaders($user);

    $old = ledgerApiCreateTransaction($account, 'ledger-old', 1_000, now()->subMinutes(2));
    $sameTimestampFirst = ledgerApiCreateTransaction($account, 'ledger-same-1', 2_000, now());
    $sameTimestampSecond = ledgerApiCreateTransaction($account, 'ledger-same-2', 3_000, $sameTimestampFirst->created_at);

    getJson("/api/ledger/{$account->id}?per_page=10", $headers)
        ->assertOk()
        ->assertJsonPath('data.0.id', $sameTimestampSecond->id)
        ->assertJsonPath('data.1.id', $sameTimestampFirst->id)
        ->assertJsonPath('data.2.id', $old->id);
});

it('per_page is capped at 100 regardless of input', function (): void {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'NGN', 'balance_minor' => 105_000]);
    $headers = ledgerApiAuthHeaders($user);

    foreach (range(1, 105) as $index) {
        ledgerApiCreateTransaction($account, "ledger-cap-{$index}", $index * 1_000, now()->addSeconds($index));
    }

    getJson("/api/ledger/{$account->id}?per_page=500", $headers)
        ->assertOk()
        ->assertJsonCount(100, 'data')
        ->assertJsonPath('meta.per_page', 100);
});

it('accessing another user wallet returns 404 not 403', function (): void {
    $user = User::factory()->create();
    $otherAccount = Account::factory()->create(['currency' => 'NGN']);
    $headers = ledgerApiAuthHeaders($user);

    getJson("/api/ledger/{$otherAccount->id}", $headers)
        ->assertNotFound();
});

it('balance_after_minor on last entry matches account balance_minor', function (): void {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'NGN', 'balance_minor' => 25_000]);
    $headers = ledgerApiAuthHeaders($user);

    ledgerApiCreateTransaction($account, 'ledger-balance-old', 10_000, now()->subMinute());
    $latest = ledgerApiCreateTransaction($account, 'ledger-balance-latest', 25_000, now());

    getJson("/api/ledger/{$account->id}", $headers)
        ->assertOk()
        ->assertJsonPath('data.0.id', $latest->id)
        ->assertJsonPath('data.0.balance_after_minor', $account->balance_minor);
});

/**
 * @return array<string, string>
 */
function ledgerApiAuthHeaders(User $user): array
{
    $sessionToken = 'ledger-session-'.$user->id;
    Cache::put(VerifyTwoFactorAction::verifiedCacheKey($user, $sessionToken), true, now()->addMinutes(10));

    return [
        'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
        'X-Two-Factor-Session' => $sessionToken,
    ];
}

function ledgerApiCreateTransaction(Account $account, string $idempotencyKey, int $balanceAfterMinor, DateTimeInterface $createdAt): LedgerTransaction
{
    return LedgerTransaction::create([
        'account_id' => $account->id,
        'reference' => 'TXN-'.$idempotencyKey,
        'idempotency_key' => $idempotencyKey,
        'type' => LedgerTransactionType::Deposit,
        'direction' => LedgerTransactionDirection::Credit,
        'amount_minor' => 1_000,
        'balance_before_minor' => max(0, $balanceAfterMinor - 1_000),
        'balance_after_minor' => $balanceAfterMinor,
        'status' => LedgerTransactionStatus::Completed,
        'metadata' => ['source' => 'test'],
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}
