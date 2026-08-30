<?php

namespace App\Policies;

use App\Enums\DeclarationStatus;
use App\Enums\MemberStatus;
use App\Enums\Permission;
use App\Models\Declaration;
use App\Models\Member;
use App\Models\User;

/**
 * Who may read and write the month's declarations.
 *
 * A member declares for themselves and nobody else. The treasurer's office may capture
 * a declaration on somebody's behalf — that is the late-entry path — but the window
 * rules still belong to DeclarationService, so holding the permission opens the form,
 * not the window.
 */
class DeclarationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([
            Permission::DeclarationsView->value,
            Permission::DeclarationsRecord->value,
            Permission::ReportsView->value,
        ]);
    }

    public function view(User $user, Declaration $declaration): bool
    {
        return $this->viewAny($user) || $declaration->member->user_id === $user->id;
    }

    /** Declaring for oneself: the member's own record, and only while still active. */
    public function submitOwn(User $user, Member $member): bool
    {
        return $user->can(Permission::DeclarationsSubmitOwn->value)
            && $member->user_id === $user->id
            && $member->status === MemberStatus::Active;
    }

    /** Capturing somebody else's declaration, including a late one. */
    public function recordFor(User $user, Member $member): bool
    {
        return $user->can(Permission::DeclarationsRecord->value)
            && $member->status === MemberStatus::Active;
    }

    /**
     * The committee's "ask": accepting the figures so the member can be charged.
     *
     * Allowed on a declaration that is already Locked as well, because the trading
     * session opens on the 4th and a member arriving to pay on the 5th still needs
     * somebody to have accepted their figures.
     */
    public function approve(User $user, Declaration $declaration): bool
    {
        return $user->can(Permission::DeclarationsApprove->value)
            && ! $declaration->isApproved()
            && $declaration->status !== DeclarationStatus::Processed;
    }

    /** Handing an approved declaration back to the member, before the month locks. */
    public function reopen(User $user, Declaration $declaration): bool
    {
        return $user->can(Permission::DeclarationsApprove->value)
            && $declaration->status === DeclarationStatus::Approved;
    }

    /** A locked or processed declaration is read-only for everybody. */
    public function update(User $user, Declaration $declaration): bool
    {
        if (! $declaration->status->isEditable()) {
            return false;
        }

        return $this->submitOwn($user, $declaration->member)
            || $this->recordFor($user, $declaration->member);
    }

    /** Declarations are the record of what was promised; they are never deleted. */
    public function delete(User $user, Declaration $declaration): bool
    {
        return false;
    }

    public function export(User $user): bool
    {
        return $this->viewAny($user);
    }
}
