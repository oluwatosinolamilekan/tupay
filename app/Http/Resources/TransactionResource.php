<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    /**
     * @return array<string, LedgerTransactionResource>
     */
    public function toArray(Request $request): array
    {
        return [
            'debit' => new LedgerTransactionResource($this->resource['debit']),
            'credit' => new LedgerTransactionResource($this->resource['credit']),
        ];
    }
}
