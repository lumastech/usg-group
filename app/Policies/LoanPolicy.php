<?php

namespace App\Policies;

use App\Enums\LoanStatus;
use App\Enums\MemberStatus;
use App\Enums\Permission;
use App\Models\Loan;
use App\Models\Member;
use App\Models\User;

/**
 * Who may do what to a loan.
 *
 * Lending is where the group's money actually leaves the room, so the permissions are
 * deliberately split: the chair's office approves, the treasurer's office pays out and
 * takes repayments, and neither can do the other's half. A member can always read their
 * own loan, whatever they hold.
 */
class LoanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([
            Permission::LoansView->value,
            Permission::ReportsView->value,
        ]);
    }

    public function view(User $user, Loan $loan): bool
    {
        return $this->viewAny($user) || $loan->member->user_id === $user->id;
    }

    /** Capturing a loan request at all. */
    public function create(User $user): bool
    {
        return $user->can(Permission::LoansRequest->value);
    }

    /**
     * Capturing a request for this particular member.
     *
     * A member may ask for their own loan; recording one on somebody else's behalf is
     * the committee's job, which is what the trading-table wizard does.
     */
    public function requestFor(User $user, Member $member): bool
    {
        if (! $this->create($user) || $member->status !== MemberStatus::Active) {
            return false;
        }

        return $member->user_id === $user->id || $user->can(Permission::LoansView->value);
    }

    /**
     * Standing as the first approver.
     *
     * The second approver's permission is checked separately when their credentials are
     * confirmed, so both halves of the two-person rule are verified against the server.
     */
    public function approve(User $user, Loan $loan): bool
    {
        return $user->can(Permission::LoansApprove->value)
            && $loan->status === LoanStatus::Requested
            && $loan->member->user_id !== $user->id;
    }

    public function reject(User $user, Loan $loan): bool
    {
        return $user->can(Permission::LoansApprove->value)
            && $loan->status === LoanStatus::Requested;
    }

    public function disburse(User $user, Loan $loan): bool
    {
        return $user->can(Permission::LoansDisburse->value)
            && $loan->status === LoanStatus::Approved;
    }

    public function recordRepayment(User $user, Loan $loan): bool
    {
        return $user->can(Permission::LoansRecordRepayment->value)
            && in_array($loan->status, [LoanStatus::Disbursed, LoanStatus::Repaying, LoanStatus::Defaulted], true);
    }

    public function markDefault(User $user, Loan $loan): bool
    {
        return $user->can(Permission::LoansApprove->value)
            && in_array($loan->status, [LoanStatus::Disbursed, LoanStatus::Repaying], true);
    }

    /** Raising the collateral claim that follows a default. */
    public function claimCollateral(User $user, Loan $loan): bool
    {
        return $user->can(Permission::LoansApprove->value)
            && $loan->status === LoanStatus::Defaulted;
    }

    /** The ledger is append-only, so a loan is never edited or removed. */
    public function update(User $user, Loan $loan): bool
    {
        return false;
    }

    public function delete(User $user, Loan $loan): bool
    {
        return false;
    }
}
