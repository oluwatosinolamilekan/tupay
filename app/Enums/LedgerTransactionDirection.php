<?php

namespace App\Enums;

enum LedgerTransactionDirection: string
{
    case Credit = 'credit';
    case Debit = 'debit';
}
