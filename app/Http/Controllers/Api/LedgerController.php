<?php

namespace App\Http\Controllers\Api;

use App\Actions\ListLedgerTransactionsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\LedgerTransactionResource;
use App\Models\Account;
use Illuminate\Http\Request;

class LedgerController extends Controller
{
    public function index(Request $request, Account $wallet, ListLedgerTransactionsAction $listLedgerTransactions)
    {
        return LedgerTransactionResource::collection(
            $listLedgerTransactions->execute(
                user: $request->user(),
                wallet: $wallet,
                perPage: $request->integer('per_page', 25),
            )
        );
    }
}
