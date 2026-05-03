<?php

namespace App\Http\Controllers\Api;

use App\Actions\ReceiveSettlementWebhookAction;
use App\Exceptions\ConflictingSettlementWebhookPayload;
use App\Http\Controllers\Controller;
use App\Http\Requests\SettlementWebhookRequest;
use Illuminate\Http\JsonResponse;

class SettlementWebhookController extends Controller
{
    public function store(SettlementWebhookRequest $request, ReceiveSettlementWebhookAction $receiveSettlementWebhook): JsonResponse
    {
        try {
            $webhook = $receiveSettlementWebhook->execute($request->validated());
        } catch (ConflictingSettlementWebhookPayload) {
            return response()->json([
                'message' => 'Provider reference was already accepted with a different payload.',
            ], 409);
        }

        return response()->json([
            'message' => $webhook->wasRecentlyCreated ? 'Webhook accepted.' : 'Webhook already accepted.',
            'provider_reference' => $webhook->provider_reference,
            'status' => $webhook->status,
        ], $webhook->wasRecentlyCreated ? 202 : 200);
    }
}
