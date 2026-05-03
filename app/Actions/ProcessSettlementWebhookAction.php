<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\LedgerTransaction;
use App\Models\SettlementWebhook;
use App\Notifications\SettlementPayoutConfirmed;
use App\Services\WalletService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSettlementWebhookAction
{
    public function __construct(private readonly WalletService $wallets) {}

    public function execute(int $webhookId): void
    {
        $processed = DB::transaction(function () use ($webhookId): ?array {
            /** @var SettlementWebhook $webhook */
            $webhook = SettlementWebhook::query()->whereKey($webhookId)->lockForUpdate()->firstOrFail();

            if ($webhook->processed_at !== null) {
                return null;
            }

            /** @var array{account_id:int, amount_minor:int, provider_reference:string} $payload */
            $payload = $webhook->payload;
            /** @var Account $account */
            $account = Account::query()->whereKey($payload['account_id'])->lockForUpdate()->firstOrFail();

            if ($account->currency !== 'CNY') {
                throw new DomainException('Settlement payouts can only be credited to CNY accounts.');
            }

            $transaction = $this->wallets->creditSettlement($account, (int) $payload['amount_minor'], 'settlement:'.$payload['provider_reference'], [
                'provider_reference' => $payload['provider_reference'],
                'source' => 'settlement-webhook',
            ]);

            $webhook->forceFill([
                'status' => 'processed',
                'processed_at' => now(),
            ])->save();

            return [$account, $transaction, $payload['provider_reference']];
        });

        if ($processed === null) {
            return;
        }

        /** @var Account $account */
        /** @var LedgerTransaction $transaction */
        [$account, $transaction, $providerReference] = $processed;
        try {
            $account->user->notify(new SettlementPayoutConfirmed($transaction));
        } catch (Throwable $e) {
            Log::error('Failed to notify user of settlement.', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('Settlement payout confirmed.', [
            'provider_reference' => $providerReference,
            'account_id' => $account->id,
        ]);
    }
}
