<?php

namespace App\Http\Controllers\Api;

use App\Actions\SwapAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\SwapRequest;
use App\Http\Resources\TransactionResource;
use DomainException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;

class SwapController extends Controller
{
    public function store(SwapRequest $request, SwapAction $swap): JsonResponse
    {
        try {
            $transactions = $swap->execute(
                user: $request->user(),
                sourceAccountId: $request->integer('source_account_id'),
                destinationAccountId: $request->integer('destination_account_id'),
                amountMinor: $request->integer('amount_minor'),
                idempotencyKey: $request->string('idempotency_key')->toString(),
                metadata: array_merge($request->array('metadata'), [
                    'ip_address' => $request->ip(),
                    'device_id' => $request->header('X-Device-Id'),
                ]),
            );
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'Another swap is already in progress.'], 423);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return (new TransactionResource($transactions))
            ->response()
            ->setStatusCode($transactions['debit']->wasRecentlyCreated ? 201 : 200);
    }
}
