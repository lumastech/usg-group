<?php

namespace App\Domain\Governance;

use App\Enums\CommitteeRole;
use App\Models\CommitteeTerm;
use App\Models\Cycle;
use App\Models\Member;

/**
 * Keeps the portal's roles a reflection of the terms actually being served.
 *
 * A committee role is never granted by hand. Recording a term grants it; ending the
 * term takes it away. This class is the only thing that touches those four roles, so
 * the answer to "why does this person hold treasurer?" is always a row in
 * committee_terms rather than something somebody once clicked.
 *
 * `member` and `admin` are left alone: the first is everybody's by virtue of being in
 * the group, the second is the system's own and has nothing to do with office.
 */
class CommitteeRoleSync
{
    /**
     * Brings one member's committee roles in line with their current terms.
     *
     * A member with no portal login has nothing to sync — the term is still recorded,
     * they simply cannot act on it until somebody invites them.
     */
    public function syncMember(Member $member): void
    {
        $user = $member->user;

        if ($user === null) {
            return;
        }

        $held = CommitteeTerm::query()
            ->forCycle($member->cycle_id)
            ->current()
            ->where('member_id', $member->id)
            ->pluck('role')
            ->map(fn (CommitteeRole $role): ?string => $role->portalRole()?->value)
            ->filter()
            ->unique()
            ->all();

        foreach ($this->managedRoles() as $role) {
            $shouldHold = in_array($role, $held, true);

            if ($shouldHold && ! $user->hasRole($role)) {
                $user->assignRole($role);
            }

            if (! $shouldHold && $user->hasRole($role)) {
                $user->removeRole($role);
            }
        }

        $user->unsetRelation('roles')->load('roles');
    }

    /**
     * Reconciles every member of a cycle.
     *
     * Run by `unity:sync-committee-roles` after an import or a restore, where terms
     * may have been written without going through CommitteeTermService.
     */
    public function syncCycle(Cycle $cycle): int
    {
        $members = Member::query()->forCycle($cycle->id)->with('user')->get();

        $members->each(fn (Member $member) => $this->syncMember($member));

        return $members->count();
    }

    /**
     * The roles this class owns, and the only ones it will ever revoke.
     *
     * @return array<int, string>
     */
    protected function managedRoles(): array
    {
        return collect(CommitteeRole::cases())
            ->map(fn (CommitteeRole $role): ?string => $role->portalRole()?->value)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
