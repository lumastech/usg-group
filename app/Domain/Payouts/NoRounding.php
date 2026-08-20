<?php

namespace App\Domain\Payouts;

/**
 * No round-off at all: a member is paid their net value to the ngwee.
 *
 * This is what the group runs today. The adjustment line is still rendered — showing
 * K0.00 — so the statement keeps the same shape as the workbook's and a convention
 * adopted later does not change how the document reads.
 */
class NoRounding implements RoundingPolicy
{
    public function adjustmentFor(int $netValueNgwee): int
    {
        return 0;
    }

    public function describe(): string
    {
        return 'No round-off applied — net payable is the net value exactly';
    }
}
