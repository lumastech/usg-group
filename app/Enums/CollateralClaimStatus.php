<?php

namespace App\Enums;

/**
 * A claim against the household goods a defaulting member pledged.
 *
 * The constitution's guarantee clause requires two committee signatures before
 * anything is enforced, which is the step between Draft and CommitteeSignOff.
 */
enum CollateralClaimStatus: string
{
    case Draft = 'draft';
    case CommitteeSignOff = 'committee_sign_off';
    case Enforced = 'enforced';
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::CommitteeSignOff => 'Committee sign-off',
            self::Enforced => 'Enforced',
            self::Released => 'Released',
        };
    }
}
