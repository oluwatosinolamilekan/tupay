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

    public function __construct(public int $webhookId)
    {
        //
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
