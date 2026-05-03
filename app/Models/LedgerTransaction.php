<?php

namespace App\Models;

use App\Enums\LedgerTransactionDirection;
use App\Enums\LedgerTransactionStatus;
use App\Enums\LedgerTransactionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $account_id
 * @property int|null $counterparty_account_id
 * @property string $reference
 * @property string $idempotency_key
 * @property LedgerTransactionType $type
 * @property LedgerTransactionDirection $direction
 * @property int $amount_minor
 * @property int $balance_before_minor
 * @property int $balance_after_minor
 * @property LedgerTransactionStatus $status
 * @property array<string, mixed>|null $metadata
 */
class LedgerTransaction extends Model
{
    protected $fillable = [
        'account_id',
        'counterparty_account_id',
        'reference',
        'idempotency_key',
        'type',
        'direction',
        'amount_minor',
        'balance_before_minor',
        'balance_after_minor',
        'status',
        'metadata',
    ];

    protected $casts = [
        'type' => LedgerTransactionType::class,
        'direction' => LedgerTransactionDirection::class,
        'status' => LedgerTransactionStatus::class,
        'amount_minor' => 'integer',
        'balance_before_minor' => 'integer',
        'balance_after_minor' => 'integer',
        'metadata' => 'array',
    ];

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function counterpartyAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'counterparty_account_id');
    }

    /**
     * @param  Builder<LedgerTransaction>  $query
     * @return Builder<LedgerTransaction>
     */
    public function scopeForLedger(Builder $query): Builder
    {
        return $query
            ->select([
                'id', 'account_id', 'counterparty_account_id',
                'reference', 'type', 'direction', 'amount_minor',
                'balance_before_minor', 'balance_after_minor',
                'status', 'metadata', 'created_at',
            ])
            ->latest('created_at')
            ->latest('id');
    }
}
