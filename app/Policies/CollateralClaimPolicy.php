<?php

namespace App\Policies;

use App\Enums\CollateralClaimStatus;
use App\Enums\Permission;
use App\Models\CollateralClaim;
use App\Models\User;

/**
 * Who may move a collateral claim along.
 *
 * Claiming a member's household goods is the most serious thing the group does, so
 * every step sits with the office that approves lending, and enforcement additionally
 * needs the second signature the claim itself records.
 */
class CollateralClaimPolicy
{
    public function view(User $user, CollateralClaim $claim): bool
    {
        return $user->canAny([Permission::LoansView->value, Permission::ReportsView->value])
            || $claim->loan->member->user_id === $user->id;
    }

    public function signOff(User $user, CollateralClaim $claim): bool
    {
        return $user->can(Permission::LoansApprove->value)
            && $claim->status === CollateralClaimStatus::Draft;
    }

    public function enforce(User $user, CollateralClaim $claim): bool
    {
        return $user->can(Permission::LoansApprove->value)
            && $claim->status === CollateralClaimStatus::CommitteeSignOff;
    }

    public function release(User $user, CollateralClaim $claim): bool
    {
        return $user->can(Permission::LoansApprove->value)
            && $claim->status !== CollateralClaimStatus::Released;
    }
}
