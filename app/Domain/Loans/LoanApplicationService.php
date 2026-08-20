<?php

namespace App\Domain\Loans;

use App\Domain\Approvals\TwoPersonRule;
use App\Domain\Support\MoneyMutator;
use App\Enums\LoanStatus;
use App\Exceptions\DomainRuleException;
use App\Exceptions\LoanNotEligibleException;
use App\Models\Loan;
use App\Models\Member;
use App\Support\Kwacha;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Captures loan requests and takes them through approval.
 *
 * Eligibility is decided by LoanEligibilityService and enforced here, so a request can
 * never be written for terms the constitution refuses. Approval requires two distinct
 * committee members and records both — the pair is the audit trail.
 */
class LoanApplicationService
{
    public function __construct(
        protected LoanEligibilityService $eligibility,
        protected TwoPersonRule $twoPersonRule,
        protected MoneyMutator $mutator,
    ) {}

    /**
     * Records a request, with the tenor the principal earns.
     *
     * A discretion note is the only way past the one-loan-at-a-time rule, and it is
     * stored on the loan rather than in a log, because the next committee to read this
     * record needs to see why it was allowed.
     */
    public function request(
        Member $member,
        Money $principal,
        Member $actor,
        ?CarbonInterface $on = null,
        ?string $discretionNote = null,
    ): Loan {
        $on ??= Carbon::now();
        $override = $discretionNote !== null && trim($discretionNote) !== '';

        if ($override && ! $actor->isCommitteeMember()) {
            throw DomainRuleException::make(
                'Only a committee member may allow a second loan by discretion.'
            );
        }

        $result = $this->eligibility->check($member, $principal, $on, $override);

        if ($result->failed()) {
            throw LoanNotEligibleException::from($result);
        }

        return $this->mutator->mutate(
            $actor,
            'Recorded a loan request of '.Kwacha::format($principal)." for {$member->full_name}",
            fn (): Loan => Loan::create([
                'cycle_id' => $member->cycle_id,
                'member_id' => $member->id,
                'principal_ngwee' => $principal,
                'tenor_months' => $result->tenor->months,
                'schedule_compressed' => $result->isCompressed(),
                'status' => LoanStatus::Requested,
                'requested_at' => $on,
                'discretion_override' => $override,
                'discretion_note' => $override ? trim($discretionNote) : null,
            ]),
            ['member_id' => $member->id],
        );
    }

    /** Two committee signatures move a request to Approved and no fewer. */
    public function approve(Loan $loan, Member $firstApprover, Member $secondApprover): Loan
    {
        if ($loan->status !== LoanStatus::Requested) {
            throw DomainRuleException::make(
                'Only a requested loan can be approved; this one is '.strtolower($loan->status->label()).'.'
            );
        }

        $this->twoPersonRule->assertDistinctCommittee($firstApprover, $secondApprover, $loan->member);

        return $this->mutator->mutate(
            $firstApprover,
            "Approved loan #{$loan->id} of ".Kwacha::format($loan->principal_ngwee)
                ." for {$loan->member->full_name}, confirmed by {$secondApprover->full_name}",
            function () use ($loan, $firstApprover, $secondApprover): Loan {
                $loan->forceFill([
                    'status' => LoanStatus::Approved,
                    'approved_by_member_id' => $firstApprover->id,
                    'second_approver_member_id' => $secondApprover->id,
                    'approved_at' => Carbon::now(),
                ])->save();

                return $loan;
            },
            ['loan_id' => $loan->id, 'second_approver_member_id' => $secondApprover->id],
        );
    }

    public function reject(Loan $loan, Member $actor, string $reason): Loan
    {
        if ($loan->status !== LoanStatus::Requested) {
            throw DomainRuleException::make(
                'Only a requested loan can be rejected; this one is '.strtolower($loan->status->label()).'.'
            );
        }

        $loan->forceFill([
            'status' => LoanStatus::Rejected,
            'rejected_by_member_id' => $actor->id,
            'rejected_at' => Carbon::now(),
            'rejection_reason' => $reason,
        ])->save();

        return $loan;
    }
}
