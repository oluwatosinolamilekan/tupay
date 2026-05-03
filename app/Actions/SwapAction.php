<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Services\WalletService;
use Illuminate\Support\Facades\Cache;

class SwapAction
{
    public function __construct(
        private readonly ExchangeRateService $rates,
        private readonly WalletService $wallets,
    ) {}

    /**
     * @return array{debit: LedgerTransaction, credit: LedgerTransaction}
     */
    public function execute(
        User $user,
        int $sourceAccountId,
        int $destinationAccountId,
        int $amountMinor,
        string $idempotencyKey,
        array $metadata = [],
    ): array {
        $source = Account::query()
            ->where('user_id', $user->id)
            ->findOrFail($sourceAccountId);

        $destination = Account::query()
            ->where('user_id', $user->id)
            ->findOrFail($destinationAccountId);

        $rateMicro = $this->rates->rateMicro('NGN', 'CNY');
        $metadata = array_merge($metadata, [
            'rate_micro' => $rateMicro,
        ]);

        $lock = Cache::lock("swap:user:{$user->id}", 10);

        return $lock->block(3, function () use ($source, $destination, $amountMinor, $rateMicro, $idempotencyKey, $metadata): array {
            return $this->wallets->swap(
                source: $source,
                destination: $destination,
                amountMinor: $amountMinor,
                rateMicro: $rateMicro,
                idempotencyKey: $idempotencyKey,
                metadata: $metadata,
            );
        });
    }
}
