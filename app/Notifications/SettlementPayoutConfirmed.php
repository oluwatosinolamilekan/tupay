<?php

namespace App\Notifications;

use App\Models\LedgerTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

class SettlementPayoutConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly LedgerTransaction $transaction)
    {
        //
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('RMB payout confirmed')
            ->line('Your RMB payout has been confirmed.')
            ->line('Amount: '.$this->transaction->amount_minor.' minor units.')
            ->line('Reference: '.$this->transaction->reference);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'transaction_id' => $this->transaction->id,
            'account_id' => $this->transaction->account_id,
            'amount_minor' => $this->transaction->amount_minor,
            'provider_reference' => $this->transaction->metadata['provider_reference'] ?? null,
        ];
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Settlement payout notification failed.', [
            'transaction_id' => $this->transaction->id,
            'account_id' => $this->transaction->account_id,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
