<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Member;
use App\Models\PayoutDestination;
use App\Models\User;

/**
 * Who may decide where a member's money goes.
 *
 * A member manages their own, and the committee may capture one on their behalf for
 * the members who will phone it in — the same arrangement as their contact details,
 * which is the right neighbour for this. Clearing a name mismatch is the one thing a
 * member cannot do for themselves, because the whole point of the check is that a
 * second pair of eyes has been on it.
 */
class PayoutDestinationPolicy
{
    public function viewAny(User $user, Member $member): bool
    {
        return $this->owns($user, $member) || $user->can(Permission::MembersView->value);
    }

    public function create(User $user, Member $member): bool
    {
        return $this->owns($user, $member) || $user->can(Permission::MembersManage->value);
    }

    public function update(User $user, PayoutDestination $destination): bool
    {
        return $destination->member !== null
            && ($this->owns($user, $destination->member) || $user->can(Permission::MembersManage->value));
    }

    public function delete(User $user, PayoutDestination $destination): bool
    {
        return $this->update($user, $destination);
    }

    /** Saying, on the record, that a different name on the account is acceptable. */
    public function confirmName(User $user, PayoutDestination $destination): bool
    {
        return $user->can(Permission::PaymentsInitiate->value)
            && $destination->member?->user_id !== $user->id;
    }

    protected function owns(User $user, Member $member): bool
    {
        return $member->user_id === $user->id;
    }
}
