<?php

namespace App\Domain\Approvals;

use App\Exceptions\DomainRuleException;
use App\Models\Member;

/**
 * The group's two-person rule, for actions confirmed in a single sitting.
 *
 * DualApprovalGate covers approvals collected over time, each approver visiting the
 * portal separately. Loan approval and collateral sign-off happen at the trading table
 * instead: one committee member acts and a second confirms on the same device, so both
 * signatures arrive together and are checked together.
 */
class TwoPersonRule
{
    /**
     * Throws unless two distinct committee members, neither of them the member the
     * action is about, are standing behind it.
     */
    public function assertDistinctCommittee(Member $first, Member $second, ?Member $subject = null): void
    {
        if ($first->is($second)) {
            throw DomainRuleException::make(
                'This action needs a second, different committee member to confirm it.'
            );
        }

        foreach ([$first, $second] as $approver) {
            if (! $approver->isCommitteeMember()) {
                throw DomainRuleException::make(
                    "{$approver->full_name} does not sit on the committee and cannot confirm this action."
                );
            }

            if ($subject !== null && $approver->is($subject)) {
                throw DomainRuleException::make(
                    'A member cannot stand as an approver on their own request.'
                );
            }
        }
    }
}
