<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    protected $fillable = [
        'base_currency',
        'quote_currency',
        'rate_micro',
        'is_active',
    ];

    protected $casts = [
        'rate_micro' => 'integer',
        'is_active' => 'boolean',
    ];
}
