<?php

namespace App\Policies;

use App\Enums\MemberStatus;
use App\Enums\Permission;
use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Models\User;

/**
 * Who may read and write the savings ledger.
 *
 * Reading the group's savings is open to anyone who may read reports — the members
 * check each other's contributions, that is the point of the workbook — but only the
 * treasurers hold `savings.record`. Nobody may edit or delete an entry: the ledger is
 * append-only, so those answers are always false and the UI never offers the button.
 */
class SavingsTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([
            Permission::SavingsView->value,
            Permission::SavingsRecord->value,
            Permission::ReportsView->value,
        ]);
    }

    public function view(User $user, SavingsTransaction $transaction): bool
    {
        return $this->viewAny($user) || $transaction->member->user_id === $user->id;
    }

    /** Recording a deposit for somebody. */
    public function create(User $user): bool
    {
        return $user->can(Permission::SavingsRecord->value);
    }

    /** Recording a deposit for this particular member. */
    public function recordFor(User $user, Member $member): bool
    {
        return $this->create($user) && $member->status === MemberStatus::Active;
    }

    /** The ledger is append-only; corrections are posted as reversing adjustments. */
    public function update(User $user, SavingsTransaction $transaction): bool
    {
        return false;
    }

    public function delete(User $user, SavingsTransaction $transaction): bool
    {
        return false;
    }
}
