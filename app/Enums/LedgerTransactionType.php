<?php

namespace App\Enums;

enum LedgerTransactionType: string
{
    case Deposit = 'deposit';
    case Settlement = 'settlement';
    case Swap = 'swap';
    case Transfer = 'transfer';
}
