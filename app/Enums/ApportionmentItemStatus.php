<?php

namespace App\Enums;

/**
 * One diaspora member's share of an apportionment.
 *
 * A share is Pending from the moment the split is confirmed until the treasurer ticks
 * the transfer off, which is when the outflow reaches the ledger.
 */
enum ApportionmentItemStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }
}
