<?php

use App\Jobs\ProcessSettlementWebhook;
use App\Models\Account;
use App\Models\LedgerTransaction;
use App\Models\SettlementWebhook;
use App\Notifications\SettlementPayoutConfirmed;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('valid webhook returns 202 and dispatches job', function (): void {
    Queue::fake();
    $account = Account::factory()->create(['currency' => 'CNY', 'balance_minor' => 0]);
    [$payload, $body, $signature] = settlementWebhookSignedPayload($account, 'provider-rmb-valid');

    $this->call('POST', '/api/webhooks/settlement', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_TUPAY_SIGNATURE' => $signature,
    ], $body)
        ->assertAccepted()
        ->assertJsonPath('message', 'Webhook accepted.')
        ->assertJsonPath('provider_reference', $payload['provider_reference']);

    Queue::assertPushed(ProcessSettlementWebhook::class, 1);
    expect(SettlementWebhook::query()->count())->toBe(1);
});

it('duplicate webhook returns 200 without double-crediting', function (): void {
    Queue::fake();
    Notification::fake();

    $account = Account::factory()->create(['currency' => 'CNY', 'balance_minor' => 0]);
    [$payload, $body, $signature] = settlementWebhookSignedPayload($account, 'provider-rmb-duplicate');
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_TUPAY_SIGNATURE' => $signature,
    ];

    $this->call('POST', '/api/webhooks/settlement', [], [], [], $server, $body)
        ->assertAccepted();

    Queue::assertPushed(ProcessSettlementWebhook::class, function (ProcessSettlementWebhook $job) use ($account): bool {
        $job->handle(app(WalletService::class));

        return $account->refresh()->balance_minor === 12_500;
    });

    $this->call('POST', '/api/webhooks/settlement', [], [], [], $server, $body)
        ->assertOk()
        ->assertJsonPath('message', 'Webhook already accepted.')
        ->assertJsonPath('provider_reference', $payload['provider_reference']);

    Queue::assertPushed(ProcessSettlementWebhook::class, 1);

    $job = new ProcessSettlementWebhook(SettlementWebhook::query()->firstOrFail()->id);
    $job->handle(app(WalletService::class));

    Notification::assertSentTo($account->user, SettlementPayoutConfirmed::class, 1);
    expect($account->refresh()->balance_minor)->toBe(12_500)
        ->and(LedgerTransaction::query()->where('idempotency_key', 'settlement:provider-rmb-duplicate')->count())->toBe(1);
});

it('invalid signature returns 401', function (): void {
    Queue::fake();
    $account = Account::factory()->create(['currency' => 'CNY', 'balance_minor' => 0]);
    [$payload] = settlementWebhookSignedPayload($account, 'provider-rmb-invalid-signature');

    $this->withHeaders(['X-Tupay-Signature' => 'bad'])
        ->postJson('/api/webhooks/settlement', $payload)
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Invalid webhook signature.');

    Queue::assertNothingPushed();
    expect(SettlementWebhook::query()->count())->toBe(0);
});

/**
 * @return array{0: array<string, mixed>, 1: string, 2: string}
 */
function settlementWebhookSignedPayload(Account $account, string $providerReference): array
{
    $payload = [
        'provider_reference' => $providerReference,
        'account_id' => $account->id,
        'amount_minor' => 12_500,
        'currency' => 'CNY',
        'status' => 'completed',
    ];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return [
        $payload,
        $body,
        hash_hmac('sha256', $body, config('services.settlement.secret')),
    ];
}
