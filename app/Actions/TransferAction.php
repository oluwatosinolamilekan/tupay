<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Services\WalletService;

class TransferAction
{
    public function __construct(private readonly WalletService $wallets) {}

    /**
     * @return array{debit: LedgerTransaction, credit: LedgerTransaction}
     */
    public function execute(User $user, int $sourceAccountId, int $destinationAccountId, int $amountMinor, string $idempotencyKey, array $metadata = []): array
    {
        /** @var Account $source */
        $source = Account::query()
            ->where('user_id', $user->id)
            ->findOrFail($sourceAccountId);
        /** @var Account $destination */
        $destination = Account::query()->findOrFail($destinationAccountId);

        return $this->wallets->transfer(
            source: $source,
            destination: $destination,
            amountMinor: $amountMinor,
            idempotencyKey: $idempotencyKey,
            metadata: $metadata,
        );
    }
}
