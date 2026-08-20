<?php

namespace App\Policies;

use App\Enums\MemberStatus;
use App\Enums\Permission;
use App\Models\Member;
use App\Models\SocialFundTransaction;
use App\Models\User;

/**
 * Who may read and write the Social Fund ledger.
 *
 * The fund is the group's money for its own bereavements and celebrations, so reading
 * it is open to anyone who may read reports. Recording an entry is the treasurers', and
 * anything that takes money out additionally needs the second signature the ledger
 * itself checks — a permission alone is never enough there.
 */
class SocialFundTransactionPolicy
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

    public function view(User $user, SocialFundTransaction $transaction): bool
    {
        return $this->viewAny($user) || $transaction->member?->user_id === $user->id;
    }

    /** Recording a contribution or another inflow. */
    public function create(User $user): bool
    {
        return $user->can(Permission::FundRecord->value);
    }

    /** Recording this particular member's contribution. */
    public function recordFor(User $user, Member $member): bool
    {
        return $this->create($user) && $member->status === MemberStatus::Active;
    }

    /** Standing behind money leaving the fund. */
    public function approveOutflow(User $user): bool
    {
        return $user->can(Permission::FundApproveOutflow->value);
    }

    /** The ledger is append-only; corrections are posted as reversing adjustments. */
    public function update(User $user, SocialFundTransaction $transaction): bool
    {
        return false;
    }

    public function delete(User $user, SocialFundTransaction $transaction): bool
    {
        return false;
    }
}
