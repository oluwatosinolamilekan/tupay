<?php

namespace App\Http\Resources;

use App\Models\LedgerTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LedgerTransaction
 */
class LedgerTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'counterparty_account_id' => $this->counterparty_account_id,
            'reference' => $this->reference,
            'type' => $this->type->value,
            'direction' => $this->direction->value,
            'amount_minor' => $this->amount_minor,
            'balance_before_minor' => $this->balance_before_minor,
            'balance_after_minor' => $this->balance_after_minor,
            'status' => $this->status->value,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
