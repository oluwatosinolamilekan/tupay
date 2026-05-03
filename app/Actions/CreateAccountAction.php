<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\User;
use App\Services\WalletService;

class CreateAccountAction
{
    public function __construct(private readonly WalletService $wallets) {}

    public function execute(int $userId, string $currency): Account
    {
        /** @var User $user */
        $user = User::query()->findOrFail($userId);

        return $this->wallets->createAccount($user, $currency);
    }
}
