<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\LedgerTransaction;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListLedgerTransactionsAction
{
    public function execute(User $user, Account $wallet, int $perPage = 25): LengthAwarePaginator
    {
        abort_unless($wallet->user_id === $user->id, 404);

        return LedgerTransaction::query()
            ->where('account_id', $wallet->id)
            ->forLedger()
            ->paginate(min($perPage, 100));
    }
}
