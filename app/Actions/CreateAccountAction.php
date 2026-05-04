<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\User;
use App\Services\WalletService;

class CreateAccountAction
{
    public function __construct(private readonly WalletService $wallets) {}

    public function execute(User $user, string $currency): Account
    {
        return $this->wallets->createAccount($user, $currency);
    }
}
