<?php

namespace App\Policies;

use App\Models\Amendment;
use App\Models\User;

/**
 * Who may read the amendment log, and who may propose a change.
 *
 * Proposing is `governance.record`, and is additionally gated by the six-month spacing
 * rule inside App\Domain\Governance\AmendmentWindow — a permission opens the form, the
 * calendar decides whether it may be submitted.
 */
class AmendmentPolicy
{
    public function __construct(protected MotionPolicy $motions) {}

    public function viewAny(User $user): bool
    {
        return $this->motions->viewAny($user);
    }

    public function view(User $user, Amendment $amendment): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->motions->create($user);
    }
}
