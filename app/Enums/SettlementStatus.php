<?php

namespace App\Enums;

/**
 * Where a debt left behind by a closed member stands.
 *
 * A member whose loans outrun their savings is not paid a negative amount — the
 * shortfall becomes a record that is chased, agreed or written off. The same three
 * states serve a member's own debt and a next of kin's repayment arrangement.
 */
enum SettlementStatus: string
{
    case Outstanding = 'outstanding';
    case Agreed = 'agreed';
    case Settled = 'settled';
    case WrittenOff = 'written_off';

    public function isOpen(): bool
    {
        return $this === self::Outstanding || $this === self::Agreed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Outstanding => 'Outstanding',
            self::Agreed => 'Terms agreed',
            self::Settled => 'Settled',
            self::WrittenOff => 'Written off',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }
}
