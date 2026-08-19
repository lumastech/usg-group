<?php

namespace App\Domain\Approvals;

use App\Enums\ApprovalStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Approval;
use App\Models\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Enforces two-person integrity on sensitive actions.
 *
 * Loan approvals and payouts need confirmation from two different committee members,
 * neither of whom may be the member who requested the action.
 */
class DualApprovalGate
{
    public function request(Model $subject, string $action, Member $requester, ?string $note = null): Approval
    {
        return $subject->morphMany(Approval::class, 'approvable')->create([
            'action' => $action,
            'requested_by_member_id' => $requester->id,
            'status' => ApprovalStatus::Pending,
            'note' => $note,
        ]);
    }

    /**
     * Records one approval. The first call moves the record to partially approved, the
     * second completes it. A member may never approve their own request, or approve twice.
     */
    public function approve(Approval $approval, Member $approver): Approval
    {
        $this->assertEligible($approval, $approver);

        if ($approval->first_approver_member_id === null) {
            $approval->forceFill([
                'first_approver_member_id' => $approver->id,
                'first_approved_at' => Carbon::now(),
                'status' => ApprovalStatus::PartiallyApproved,
            ])->save();

            return $approval;
        }

        $approval->forceFill([
            'second_approver_member_id' => $approver->id,
            'second_approved_at' => Carbon::now(),
            'status' => ApprovalStatus::Approved,
        ])->save();

        return $approval;
    }

    public function reject(Approval $approval, Member $approver, string $reason): Approval
    {
        $this->assertEligible($approval, $approver);

        $approval->forceFill([
            'rejected_by_member_id' => $approver->id,
            'rejected_at' => Carbon::now(),
            'status' => ApprovalStatus::Rejected,
            'note' => trim($approval->note.PHP_EOL.$reason),
        ])->save();

        return $approval;
    }

    /** Throws unless the subject carries a completed two-person approval for the action. */
    public function assertApproved(Model $subject, string $action): void
    {
        $approved = $subject->morphMany(Approval::class, 'approvable')
            ->where('action', $action)
            ->where('status', ApprovalStatus::Approved)
            ->exists();

        if (! $approved) {
            throw DomainRuleException::make(
                "This action requires confirmation from two committee members before it can proceed [{$action}]."
            );
        }
    }

    protected function assertEligible(Approval $approval, Member $approver): void
    {
        if ($approval->status === ApprovalStatus::Approved) {
            throw DomainRuleException::make('This request has already been fully approved.');
        }

        if ($approval->status === ApprovalStatus::Rejected) {
            throw DomainRuleException::make('This request has been rejected and cannot be approved.');
        }

        if (! $approver->isCommitteeMember()) {
            throw DomainRuleException::make('Only committee members may approve this action.');
        }

        if ($approval->requested_by_member_id === $approver->id) {
            throw DomainRuleException::make('A member cannot approve their own request.');
        }

        if ($approval->first_approver_member_id === $approver->id) {
            throw DomainRuleException::make('This action needs a second, different committee member to confirm it.');
        }
    }
}
