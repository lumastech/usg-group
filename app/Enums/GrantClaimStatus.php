<?php

namespace App\Enums;

/**
 * The life of a funeral or unity baby claim.
 *
 * Nothing touches the fund's ledger until Paid: Approved records the two signatures,
 * and the outflow is posted at the moment the money actually leaves.
 */
enum GrantClaimStatus: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Paid = 'paid';
    case Rejected = 'rejected';

    public function isOpen(): bool
    {
        return $this === self::Submitted || $this === self::Approved;
    }

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::Approved => 'Approved',
            self::Paid => 'Paid',
            self::Rejected => 'Rejected',
        };
    }
}
