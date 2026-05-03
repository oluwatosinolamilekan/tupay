<?php

namespace App\Actions;

use App\Exceptions\ConflictingSettlementWebhookPayload;
use App\Jobs\ProcessSettlementWebhook;
use App\Models\SettlementWebhook;
use Illuminate\Database\QueryException;

class ReceiveSettlementWebhookAction
{
    public function execute(array $payload): SettlementWebhook
    {
        try {
            $webhook = SettlementWebhook::create([
                'provider_reference' => $payload['provider_reference'],
                'payload' => $payload,
                'status' => 'received',
            ]);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }

            $webhook = SettlementWebhook::query()
                ->where('provider_reference', $payload['provider_reference'])
                ->firstOrFail();
        }

        if (! $webhook->wasRecentlyCreated && $this->payloadConflicts($webhook->payload, $payload)) {
            throw new ConflictingSettlementWebhookPayload('Provider reference was already accepted with a different payload.');
        }

        if ($webhook->wasRecentlyCreated) {
            ProcessSettlementWebhook::dispatch($webhook->id);
        }

        return $webhook;
    }

    private function payloadConflicts(array $stored, array $incoming): bool
    {
        return (int) $stored['account_id'] !== (int) $incoming['account_id']
            || (int) $stored['amount_minor'] !== (int) $incoming['amount_minor']
            || (string) $stored['currency'] !== (string) $incoming['currency']
            || (string) $stored['status'] !== (string) $incoming['status'];
    }
}
