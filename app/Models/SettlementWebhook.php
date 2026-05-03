<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SettlementWebhook extends Model
{
    protected $fillable = [
        'provider_reference',
        'status',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}
