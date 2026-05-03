<?php

namespace Tests\Unit;

use App\Enums\LedgerTransactionDirection;
use PHPUnit\Framework\TestCase;

class LedgerTransactionDirectionTest extends TestCase
{
    public function test_direction_values_match_ledger_storage_values(): void
    {
        $this->assertSame('credit', LedgerTransactionDirection::Credit->value);
        $this->assertSame('debit', LedgerTransactionDirection::Debit->value);
    }
}
