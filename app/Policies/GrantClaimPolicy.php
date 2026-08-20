<?php

namespace App\Policies;

use App\Enums\GrantClaimStatus;
use App\Enums\Permission;
use App\Models\FuneralGrantClaim;
use App\Models\UnityBabyClaim;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may move a funeral or unity baby claim along.
 *
 * Registered for both claim models, because both grants follow one route: a member
 * submits their own, the committee approves it with two signatures, and the treasurers
 * pay it. Which relationships qualify for the funeral grant is not decided here — the
 * enum has only the three the constitution allows, so there is nothing to police.
 */
class GrantClaimPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([
            Permission::FundView->value,
            Permission::FundRecord->value,
            Permission::FundApproveOutflow->value,
            Permission::ReportsView->value,
        ]);
    }

    /** @param  FuneralGrantClaim|UnityBabyClaim  $claim */
    public function view(User $user, Model $claim): bool
    {
        return $this->viewAny($user) || $claim->member->user_id === $user->id;
    }

    /** Any member may raise a claim; whose claim it is is checked on the route. */
    public function create(User $user): bool
    {
        return $user->member !== null;
    }

    /** @param  FuneralGrantClaim|UnityBabyClaim  $claim */
    public function approve(User $user, Model $claim): bool
    {
        return $user->can(Permission::FundApproveOutflow->value)
            && $claim->status === GrantClaimStatus::Submitted;
    }

    /** @param  FuneralGrantClaim|UnityBabyClaim  $claim */
    public function pay(User $user, Model $claim): bool
    {
        return $user->can(Permission::FundApproveOutflow->value)
            && $claim->status === GrantClaimStatus::Approved;
    }

    /** @param  FuneralGrantClaim|UnityBabyClaim  $claim */
    public function reject(User $user, Model $claim): bool
    {
        return $user->can(Permission::FundApproveOutflow->value)
            && $claim->status->isOpen();
    }
}
