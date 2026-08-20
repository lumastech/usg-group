<?php

namespace App\Enums;

/**
 * A month's trading session is Open from the moment declarations close until the
 * treasurer concludes it, which is the single act that posts the month's money.
 */
enum TradingSessionStatus: string
{
    case Open = 'open';
    case Concluded = 'concluded';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Concluded => 'Concluded',
        };
    }
}
