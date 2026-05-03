<?php

namespace App\Http\Controllers\Api;

use App\Actions\TransferAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\TransferRequest;
use App\Http\Resources\TransactionResource;
use DomainException;
use Illuminate\Http\JsonResponse;

class TransactionController extends Controller
{
    public function store(TransferRequest $request, TransferAction $transfer): JsonResponse
    {
        try {
            $transactions = $transfer->execute(
                sourceAccountId: $request->integer('source_account_id'),
                destinationAccountId: $request->integer('destination_account_id'),
                amountMinor: $request->integer('amount_minor'),
                idempotencyKey: $request->string('idempotency_key')->toString(),
                metadata: $request->array('metadata'),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return (new TransactionResource($transactions))
            ->response()
            ->setStatusCode($transactions['debit']->wasRecentlyCreated ? 201 : 200);
    }
}
