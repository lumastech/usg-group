<?php

namespace App\Enums;

/**
 * A movement on the Social Fund ledger.
 *
 * Inflows carry a positive amount and outflows a negative one, so the fund's balance
 * is the plain sum of the column. Every outflow needs a second committee signature —
 * `requiresSecondApprover()` is what the ledger enforces that with.
 */
enum SocialFundTransactionType: string
{
    case Contribution = 'contribution';
    case LatePenaltyInflow = 'late_penalty_inflow';
    case FuneralGrant = 'funeral_grant';
    case UnityBabyGrant = 'unity_baby_grant';
    case GatheringExpense = 'gathering_expense';
    case DiasporaApportionment = 'diaspora_apportionment';
    case Adjustment = 'adjustment';

    /** Money leaving the fund. Adjustments may go either way, so they are neither. */
    public function isOutflow(): bool
    {
        return match ($this) {
            self::FuneralGrant, self::UnityBabyGrant,
            self::GatheringExpense, self::DiasporaApportionment => true,
            default => false,
        };
    }

    public function isInflow(): bool
    {
        return $this === self::Contribution || $this === self::LatePenaltyInflow;
    }

    /**
     * Whether a second committee signature is required.
     *
     * An Adjustment can reduce the fund just as a grant does, so a negative one is
     * held to the same rule; the ledger decides that from the amount's sign.
     */
    public function requiresSecondApprover(): bool
    {
        return $this->isOutflow();
    }

    public function label(): string
    {
        return match ($this) {
            self::Contribution => 'Contribution',
            self::LatePenaltyInflow => 'Late penalty',
            self::FuneralGrant => 'Funeral grant',
            self::UnityBabyGrant => 'Unity baby grant',
            self::GatheringExpense => 'Gathering expense',
            self::DiasporaApportionment => 'Diaspora apportionment',
            self::Adjustment => 'Adjustment',
        };
    }
}
