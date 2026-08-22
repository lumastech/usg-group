<?php

namespace App\Enums;

/**
 * Who pays the provider's cut.
 *
 * The group has settled this for money coming in: the member bears it. A K500
 * contribution must land in the savings ledger as exactly K500, or the ledger and the
 * bank disagree forever — the increment rule leaves no room for K487.50. Money going
 * out is the other way round: the fee is the group's cost and is never netted off
 * what a member is owed.
 */
enum FeeBearer: string
{
    case Merchant = 'merchant';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::Merchant => 'The group',
            self::Customer => 'The member',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $bearer): string => $bearer->value, self::cases());
    }
}
