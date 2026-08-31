<?php

namespace App\Enums;

/**
 * Whose money a wallet holds.
 *
 * The distinction is not cosmetic: the sum of every member wallet is a liability the
 * group owes on demand, and never appears in the savings pool or the social fund
 * balance. The group wallet is the other side of that — what the group holds and has
 * not yet paid out.
 */
enum WalletKind: string
{
    case Member = 'member';
    case Group = 'group';

    public function label(): string
    {
        return match ($this) {
            self::Member => 'Member wallet',
            self::Group => 'Group wallet',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $kind): string => $kind->value, self::cases());
    }
}
