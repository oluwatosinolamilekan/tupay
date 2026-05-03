<?php

namespace App\Http\Controllers\Api;

use App\Actions\DepositAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\DepositRequest;
use App\Http\Resources\LedgerTransactionResource;
use App\Models\Account;
use Illuminate\Http\JsonResponse;

class DepositController extends Controller
{
    public function store(DepositRequest $request, Account $account, DepositAction $deposit): JsonResponse
    {
        $transaction = $deposit->execute(
            account: $account,
            amountMinor: $request->integer('amount_minor'),
            idempotencyKey: $request->string('idempotency_key')->toString(),
            metadata: $request->array('metadata'),
        );

        return (new LedgerTransactionResource($transaction))
            ->response()
            ->setStatusCode($transaction->wasRecentlyCreated ? 201 : 200);
    }
}
