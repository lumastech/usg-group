<?php

namespace App\Enums;

enum SavingsTransactionType: string
{
    case Contribution = 'contribution';
    case JoiningFee = 'joining_fee';
    case Adjustment = 'adjustment';
    case ImportOpening = 'import_opening';

    /** Only member contributions are held to the K500 minimum and increment rules. */
    public function followsIncrementRules(): bool
    {
        return $this === self::Contribution;
    }
}
