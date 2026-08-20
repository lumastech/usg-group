<?php

namespace App\Policies;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Members\MembershipRegistrar;
use App\Enums\Permission;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Who may see and act on member records.
 *
 * The register is not private within the group — anyone who may read reports may
 * read it — but changing it belongs to the offices holding `members.manage`. A
 * member always reaches their own record through the /my portal, never another's.
 */
class MemberPolicy
{
    public function __construct(protected MembershipRegistrar $registrar) {}

    public function viewAny(User $user): bool
    {
        return $this->canRead($user);
    }

    /** A member may always read their own record, even without a read permission. */
    public function view(User $user, Member $member): bool
    {
        return $this->canRead($user) || $this->owns($user, $member);
    }

    /**
     * Registration is a window, not a permission: once the third month of the cycle
     * has passed nobody may add a member, and there is no override.
     */
    public function create(User $user): bool
    {
        return $user->can(Permission::MembersManage->value) && $this->registrationOpen();
    }

    public function update(User $user, Member $member): bool
    {
        return $user->can(Permission::MembersManage->value);
    }

    public function changeStatus(User $user, Member $member): bool
    {
        return $user->can(Permission::MembersManage->value)
            && $member->status->allowedTransitions() !== [];
    }

    /** Inviting a login is pointless once one is attached; unlink first. */
    public function invite(User $user, Member $member): bool
    {
        return $user->can(Permission::MembersManage->value) && ! $member->hasLogin();
    }

    /** Members maintain their own contact details from /my/profile. */
    public function updateOwnContactDetails(User $user, Member $member): bool
    {
        return $this->owns($user, $member);
    }

    protected function canRead(User $user): bool
    {
        return $user->canAny([
            Permission::MembersView->value,
            Permission::MembersManage->value,
            Permission::ReportsView->value,
        ]);
    }

    protected function owns(User $user, Member $member): bool
    {
        return $member->user_id === $user->id;
    }

    /** Whether today still falls inside the cycle's registration window. */
    protected function registrationOpen(): bool
    {
        $cycle = app(CurrentCycle::class)->get();

        if ($cycle === null) {
            return false;
        }

        return $cycle->registrationOpenForMonth(
            $this->registrar->monthSequenceFor($cycle, Carbon::today()),
        );
    }
}
