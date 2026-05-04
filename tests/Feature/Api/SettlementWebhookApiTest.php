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

use function Pest\Laravel\call;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

uses(RefreshDatabase::class);

it('valid webhook returns 202 and dispatches job', function (): void {
    Queue::fake();
    $account = Account::factory()->create(['currency' => 'CNY', 'balance_minor' => 0]);
    [$payload, $body, $signature] = settlementWebhookSignedPayload($account, 'provider-rmb-valid');

    call('POST', '/api/webhooks/settlement', [], [], [], [
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

    call('POST', '/api/webhooks/settlement', [], [], [], $server, $body)
        ->assertAccepted();

    Queue::assertPushed(ProcessSettlementWebhook::class, function (ProcessSettlementWebhook $job) use ($account): bool {
        $job->handle(app(WalletService::class));

        return $account->refresh()->balance_minor === 12_500;
    });

    call('POST', '/api/webhooks/settlement', [], [], [], $server, $body)
        ->assertOk()
        ->assertJsonPath('message', 'Webhook already accepted.')
        ->assertJsonPath('provider_reference', $payload['provider_reference']);

    Queue::assertPushed(ProcessSettlementWebhook::class, 1);

    $job = new ProcessSettlementWebhook(SettlementWebhook::query()->firstOrFail()->id);
    $job->handle(app(WalletService::class));

    Notification::assertSentTo($account->user, SettlementPayoutConfirmed::class);
    Notification::assertSentTimes(SettlementPayoutConfirmed::class, 1);
    expect($account->refresh()->balance_minor)->toBe(12_500)
        ->and(LedgerTransaction::query()->where('idempotency_key', 'settlement:provider-rmb-duplicate')->count())->toBe(1);
});

it('invalid signature returns 401', function (): void {
    Queue::fake();
    $account = Account::factory()->create(['currency' => 'CNY', 'balance_minor' => 0]);
    [$payload] = settlementWebhookSignedPayload($account, 'provider-rmb-invalid-signature');

    withHeaders(['X-Tupay-Signature' => 'bad']);

    postJson('/api/webhooks/settlement', $payload)
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Invalid webhook signature.');

    Queue::assertNothingPushed();
    expect(SettlementWebhook::query()->count())->toBe(0);
});

it('webhook with conflicting payload for existing reference returns 409', function (): void {
    Queue::fake();
    $account = Account::factory()->create(['currency' => 'CNY', 'balance_minor' => 0]);
    $otherAccount = Account::factory()->create(['currency' => 'CNY', 'balance_minor' => 0]);
    [$payload, $body, $signature] = settlementWebhookSignedPayload($account, 'provider-rmb-conflict');
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_TUPAY_SIGNATURE' => $signature,
    ];

    call('POST', '/api/webhooks/settlement', [], [], [], $server, $body)
        ->assertAccepted();

    $conflictingPayload = array_merge($payload, ['account_id' => $otherAccount->id]);
    $conflictingBody = json_encode($conflictingPayload, JSON_THROW_ON_ERROR);

    call('POST', '/api/webhooks/settlement', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_TUPAY_SIGNATURE' => hash_hmac('sha256', $conflictingBody, config('services.settlement.secret')),
    ], $conflictingBody)
        ->assertStatus(409)
        ->assertJsonPath('message', 'Provider reference was already accepted with a different payload.');
});

it('processed webhook credits the correct CNY account via job', function (): void {
    Notification::fake();
    $account = Account::factory()->create(['currency' => 'CNY', 'balance_minor' => 0]);
    [$payload, $body, $signature] = settlementWebhookSignedPayload($account, 'provider-rmb-process');

    call('POST', '/api/webhooks/settlement', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_TUPAY_SIGNATURE' => $signature,
    ], $body)
        ->assertAccepted();

    $webhook = SettlementWebhook::query()
        ->where('provider_reference', $payload['provider_reference'])
        ->firstOrFail();

    (new ProcessSettlementWebhook($webhook->id))->handle(app(WalletService::class));

    expect($account->refresh()->balance_minor)->toBe(12_500)
        ->and(LedgerTransaction::query()
            ->where('account_id', $account->id)
            ->where('idempotency_key', 'settlement:provider-rmb-process')
            ->where('amount_minor', 12_500)
            ->exists())->toBeTrue();
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
