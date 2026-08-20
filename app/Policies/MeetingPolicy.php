<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Meeting;
use App\Models\User;

/**
 * Who may read the minutes, and who may keep them.
 *
 * The attendance register is worked on a phone in the room by whoever is minuting, so
 * marking it is `governance.record`. Everyone on the committee may read a meeting.
 */
class MeetingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([
            Permission::GovernanceRecord->value,
            Permission::ReportsView->value,
            Permission::MembersView->value,
        ]);
    }

    public function view(User $user, Meeting $meeting): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::GovernanceRecord->value);
    }

    /** Taking the register: tapping members present as they arrive. */
    public function record(User $user, Meeting $meeting): bool
    {
        return $this->create($user);
    }
}
