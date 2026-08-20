<?php

namespace App\Enums;

/** Which population a motion's 60% is counted against. */
enum ThresholdBasis: string
{
    case TotalMembers = 'total_members';
    case MembersPresent = 'members_present';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $basis): string => $basis->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::TotalMembers => 'Total active members',
            self::MembersPresent => 'Members present',
        };
    }
}
