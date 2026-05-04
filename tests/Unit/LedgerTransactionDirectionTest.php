<?php

use App\Enums\LedgerTransactionDirection;

it('direction values match ledger storage values', function (): void {
    expect(LedgerTransactionDirection::Credit->value)->toBe('credit')
        ->and(LedgerTransactionDirection::Debit->value)->toBe('debit');
});
