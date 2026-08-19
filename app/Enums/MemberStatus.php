<?php

namespace App\Enums;

enum MemberStatus: string
{
    case Active = 'active';
    case LeftEarly = 'left_early';
    case Expelled = 'expelled';
    case Deceased = 'deceased';

    /** Members who may transact; everyone else is settled at cycle end only. */
    public function canTransact(): bool
    {
        return $this === self::Active;
    }

    /** Whether the member's payout includes interest earned. */
    public function earnsInterestOnPayout(): bool
    {
        return match ($this) {
            self::Active, self::Deceased => true,
            self::LeftEarly, self::Expelled => false,
        };
    }
}
