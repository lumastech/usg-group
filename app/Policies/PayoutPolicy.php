<?php

namespace App\Policies;

use App\Enums\CycleStatus;
use App\Enums\Permission;
use App\Models\Member;
use App\Models\Payout;
use App\Models\User;

/**
 * Who may see and settle a closure.
 *
 * Reading the register is open to anyone who may read the group's figures — members
 * check each other's positions all year. Executing is `payouts.execute` and, on top of
 * that, needs the cycle to have reached share-out: the ability answers here rather
 * than in the Vue page, so the wizard's final button is enabled by the same rule that
 * would refuse the request.
 */
class PayoutPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([
            Permission::PayoutsApprove->value,
            Permission::PayoutsExecute->value,
            Permission::ReportsView->value,
        ]);
    }

    public function view(User $user, Payout $payout): bool
    {
        return $this->viewAny($user) || $payout->member->user_id === $user->id;
    }

    /** Whether this user could execute the closure of this member, right now. */
    public function execute(User $user, Member $member): bool
    {
        if (! $user->can(Permission::PayoutsExecute->value) || $member->ledgersFrozen()) {
            return false;
        }

        return $member->cycle->status === CycleStatus::ShareOut
            || $this->mayBeSettledEarly($member);
    }

    /**
     * Whether this closure could be settled ahead of share-out.
     *
     * Only a death, and only with a written reason — the reason is captured in the
     * wizard, so the ability is what makes that step appear at all.
     */
    public function settleEarly(User $user, Member $member): bool
    {
        return $user->can(Permission::PayoutsExecute->value)
            && ! $member->ledgersFrozen()
            && $this->mayBeSettledEarly($member);
    }

    protected function mayBeSettledEarly(Member $member): bool
    {
        return $member->status->requiresDateOfDeath()
            && $member->cycle->status !== CycleStatus::Closed;
    }
}
