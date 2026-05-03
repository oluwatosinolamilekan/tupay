<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\LedgerTransaction;
use App\Services\WalletService;

class DepositAction
{
    public function __construct(private readonly WalletService $wallets) {}

    public function execute(Account $account, int $amountMinor, string $idempotencyKey, array $metadata = []): LedgerTransaction
    {
        return $this->wallets->deposit(
            account: $account,
            amountMinor: $amountMinor,
            idempotencyKey: $idempotencyKey,
            metadata: $metadata,
        );
    }
}
