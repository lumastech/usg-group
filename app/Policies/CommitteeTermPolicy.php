<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\CommitteeTerm;
use App\Models\User;

/**
 * Who may read the committee register, and who may change it.
 *
 * Reading is open to anyone who may read the group's figures: who holds which office
 * is not a secret, it is read out at every meeting. Putting somebody into office or
 * taking them out belongs to `governance.record` alone, because a term grants portal
 * permissions for its duration.
 */
class CommitteeTermPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([
            Permission::GovernanceRecord->value,
            Permission::ReportsView->value,
            Permission::MembersView->value,
        ]);
    }

    public function view(User $user, CommitteeTerm $term): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::GovernanceRecord->value);
    }

    /** Ending a term: only one that is actually being served. */
    public function end(User $user, CommitteeTerm $term): bool
    {
        return $this->create($user) && $term->isCurrent();
    }

    /** Generating the next cycle's succession proposal — it appoints nobody. */
    public function propose(User $user): bool
    {
        return $this->viewAny($user);
    }
}
