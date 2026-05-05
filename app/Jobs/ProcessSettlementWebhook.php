<?php

namespace App\Jobs;

use App\Actions\ProcessSettlementWebhookAction;
use App\Services\WalletService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSettlementWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public int $webhookId)
    {
        //
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(WalletService $wallets): void
    {
        (new ProcessSettlementWebhookAction($wallets))->execute($this->webhookId);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Settlement webhook job failed.', [
            'webhook_id' => $this->webhookId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
