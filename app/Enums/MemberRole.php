<?php

namespace App\Enums;

enum MemberRole: string
{
    case Member = 'Member';
    case Treasurer = 'Treasurer';
    case ViceTreasurer = 'Vice-Treasurer';
    case Chairperson = 'Chairperson';
    case ViceChairperson = 'Vice-Chairperson';
    case Admin = 'Admin';

    /**
     * Roles whose holders may act as one of the two required approvers.
     *
     * @return array<int, self>
     */
    public static function committee(): array
    {
        return [self::Treasurer, self::ViceTreasurer, self::Chairperson, self::ViceChairperson];
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $role): string => $role->value, self::cases());
    }
}
