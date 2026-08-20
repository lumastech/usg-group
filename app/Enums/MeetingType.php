<?php

namespace App\Enums;

/** The kinds of gathering the group minutes. */
enum MeetingType: string
{
    case Monthly = 'monthly';
    case Special = 'special';
    case ShareOut = 'share_out';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly meeting',
            self::Special => 'Special meeting',
            self::ShareOut => 'Share-out meeting',
        };
    }
}
