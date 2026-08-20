<?php

namespace App\Enums;

/**
 * What one line of a payout statement does to the running figure.
 *
 * The statement card renders from these: credits and debits are the arithmetic,
 * subtotals and totals are the rules drawn under it, and a note is a line that
 * explains something without moving the money — such as interest forfeited.
 */
enum PayoutLineKind: string
{
    case Credit = 'credit';
    case Debit = 'debit';
    case Subtotal = 'subtotal';
    case Adjustment = 'adjustment';
    case Total = 'total';
    case Note = 'note';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $kind): string => $kind->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Credit => 'Credit',
            self::Debit => 'Debit',
            self::Subtotal => 'Subtotal',
            self::Adjustment => 'Adjustment',
            self::Total => 'Total',
            self::Note => 'Note',
        };
    }
}
