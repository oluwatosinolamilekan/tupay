<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessSettlementWebhook;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\LedgerTransaction;
use App\Models\SettlementWebhook;
use App\Models\User;
use App\Notifications\SettlementPayoutConfirmed;
use App\Services\TwoFactorService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AssessmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_factor_gate_protects_and_allows_swaps(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $ngn = Account::factory()->create(['user_id' => $user->id, 'currency' => 'NGN', 'balance_minor' => 100_000]);
        $cny = Account::factory()->create(['user_id' => $user->id, 'currency' => 'CNY', 'balance_minor' => 0]);
        ExchangeRate::create([
            'base_currency' => 'NGN',
            'quote_currency' => 'CNY',
            'rate_micro' => 500,
            'is_active' => true,
        ]);

        $loginResponse = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('auth_type', 'Bearer')
            ->assertJsonPath('two_factor.setup_required', true)
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'two_factor' => [
                    'session_token',
                    'secret',
                    'provisioning_uri',
                ],
            ]);

        $sessionToken = $loginResponse->json('two_factor.session_token');
        $accessToken = $loginResponse->json('access_token');

        $payload = [
            'source_account_id' => $ngn->id,
            'destination_account_id' => $cny->id,
            'amount_minor' => 50_000,
            'idempotency_key' => 'swap-123',
        ];

        $this->withToken($accessToken)->postJson('/api/swap', $payload)
            ->assertForbidden()
            ->assertJsonPath('message', 'Two-factor verification required.');

        $code = app(TwoFactorService::class)->totp($user->refresh()->two_factor_secret);

        $this->withToken($accessToken)->postJson('/api/2fa/verify', [
            'code' => $code,
            'session_token' => $sessionToken,
        ])
            ->assertOk();

        $this->assertNotNull($user->refresh()->two_factor_confirmed_at);

        $this->withToken($accessToken)->postJson('/api/swap', $payload)
            ->assertForbidden()
            ->assertJsonPath('message', 'Two-factor verification required.');

        $this->withHeader('X-Two-Factor-Session', $sessionToken)
            ->withToken($accessToken)
            ->postJson('/api/swap', $payload)
            ->assertCreated()
            ->assertJsonPath('data.debit.balance_after_minor', 50_000)
            ->assertJsonPath('data.credit.amount_minor', 25);

        $this->withHeader('X-Two-Factor-Session', $sessionToken)
            ->withToken($accessToken)
            ->postJson('/api/swap', $payload)
            ->assertOk()
            ->assertJsonPath('data.credit.amount_minor', 25);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('two_factor.setup_required', false)
            ->assertJsonMissingPath('two_factor.secret')
            ->assertJsonMissingPath('two_factor.provisioning_uri');

        $this->assertSame(50_000, $ngn->refresh()->balance_minor);
        $this->assertSame(25, $cny->refresh()->balance_minor);
        $this->assertSame(2, LedgerTransaction::query()->where('type', 'swap')->count());
    }

    public function test_settlement_webhooks_are_signed_queued_and_idempotent(): void
    {
        Queue::fake();
        Notification::fake();

        $account = Account::factory()->create(['currency' => 'CNY', 'balance_minor' => 0]);
        $payload = [
            'provider_reference' => 'provider-rmb-123',
            'account_id' => $account->id,
            'amount_minor' => 12_500,
            'currency' => 'CNY',
            'status' => 'completed',
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $body, config('services.settlement.secret'));

        $this->withHeaders(['X-Tupay-Signature' => 'bad'])
            ->postJson('/api/webhooks/settlement', $payload)
            ->assertUnauthorized();

        $this->call('POST', '/api/webhooks/settlement', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TUPAY_SIGNATURE' => $signature,
        ], $body)
            ->assertAccepted();

        $conflictingPayload = [...$payload, 'amount_minor' => 25_000];
        $conflictingBody = json_encode($conflictingPayload, JSON_THROW_ON_ERROR);
        $conflictingSignature = hash_hmac('sha256', $conflictingBody, config('services.settlement.secret'));

        $this->call('POST', '/api/webhooks/settlement', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TUPAY_SIGNATURE' => $conflictingSignature,
        ], $conflictingBody)
            ->assertConflict()
            ->assertJsonPath('message', 'Provider reference was already accepted with a different payload.');

        $this->call('POST', '/api/webhooks/settlement', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TUPAY_SIGNATURE' => $signature,
        ], $body)
            ->assertOk();

        Queue::assertPushed(ProcessSettlementWebhook::class, 1);
        $this->assertSame(1, SettlementWebhook::query()->count());

        Queue::assertPushed(ProcessSettlementWebhook::class, function (ProcessSettlementWebhook $job) use ($account): bool {
            $job->handle(app(WalletService::class));

            return $account->refresh()->balance_minor === 12_500;
        });

        Notification::assertSentTo($account->user, SettlementPayoutConfirmed::class);

        $job = new ProcessSettlementWebhook(SettlementWebhook::query()->firstOrFail()->id);
        $job->handle(app(WalletService::class));

        Notification::assertSentTimes(SettlementPayoutConfirmed::class, 1);
        $this->assertSame(12_500, $account->refresh()->balance_minor);
        $this->assertSame(1, LedgerTransaction::query()->where('idempotency_key', 'settlement:provider-rmb-123')->count());
    }

    public function test_settlement_webhooks_only_accept_cny_accounts(): void
    {
        Queue::fake();

        $account = Account::factory()->create(['currency' => 'NGN', 'balance_minor' => 0]);
        $payload = [
            'provider_reference' => 'provider-rmb-ngn-account',
            'account_id' => $account->id,
            'amount_minor' => 12_500,
            'currency' => 'CNY',
            'status' => 'completed',
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $body, config('services.settlement.secret'));

        $this->call('POST', '/api/webhooks/settlement', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_TUPAY_SIGNATURE' => $signature,
        ], $body)
            ->assertUnprocessable();

        Queue::assertNothingPushed();
        $this->assertSame(0, SettlementWebhook::query()->count());
    }
}
