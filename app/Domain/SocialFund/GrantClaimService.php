<?php

namespace App\Domain\SocialFund;

use App\Domain\Approvals\TwoPersonRule;
use App\Enums\GrantClaimStatus;
use App\Enums\MemberStatus;
use App\Exceptions\DomainRuleException;
use App\Models\FuneralGrantClaim;
use App\Models\Member;
use App\Models\SocialFundTransaction;
use App\Models\UnityBabyClaim;
use App\Support\Kwacha;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Moves a funeral or unity baby claim from Submitted to Paid.
 *
 * Both grants run the same three steps and are driven through the same code: the two
 * signatures are recorded on the claim at approval, and the outflow only reaches the
 * ledger when the money actually leaves, so an approved-but-unpaid claim never
 * understates what the fund still holds.
 *
 * The funeral grant's eligibility is not checked here at all — App\Enums\FuneralRelationship
 * has only the three cases the constitution allows, so an ineligible claim cannot be
 * constructed in the first place.
 */
class GrantClaimService
{
    public function __construct(
        protected SocialFundLedger $ledger,
        protected TwoPersonRule $twoPersonRule,
    ) {}

    /**
     * Records the second signature.
     *
     * @param  FuneralGrantClaim|UnityBabyClaim  $claim
     */
    public function approve(Model $claim, Member $actor, Member $secondApprover): Model
    {
        if ($claim->status !== GrantClaimStatus::Submitted) {
            throw DomainRuleException::make(
                'Only a submitted claim can be approved; this one is '.$claim->status->label().'.'
            );
        }

        $this->twoPersonRule->assertDistinctCommittee($actor, $secondApprover, $claim->member);

        $claim->forceFill([
            'first_approver_member_id' => $actor->id,
            'second_approver_member_id' => $secondApprover->id,
            'approved_at' => Carbon::now(),
            'status' => GrantClaimStatus::Approved,
        ])->save();

        return $claim;
    }

    /**
     * Pays an approved claim, which is the moment the fund is debited.
     *
     * @param  FuneralGrantClaim|UnityBabyClaim  $claim
     */
    public function pay(
        Model $claim,
        Member $actor,
        Member $secondApprover,
        ?CarbonInterface $paidOn = null,
    ): SocialFundTransaction {
        if (! $claim->isPayable()) {
            throw DomainRuleException::make(
                'Only an approved claim can be paid; this one is '.$claim->status->label().'.'
            );
        }

        $transaction = $this->ledger->pay(
            $claim->cycle,
            $claim->grantType(),
            $claim->amount_ngwee,
            $paidOn ?? Carbon::today(),
            $actor,
            $secondApprover,
            $claim->member,
            $claim,
            $claim->grantType()->label().' for '.$claim->subject(),
        );

        $claim->forceFill([
            'paid_at' => Carbon::now(),
            'status' => GrantClaimStatus::Paid,
        ])->save();

        return $transaction;
    }

    /** @param  FuneralGrantClaim|UnityBabyClaim  $claim */
    public function reject(Model $claim, Member $actor, string $reason): Model
    {
        if (! $claim->status->isOpen()) {
            throw DomainRuleException::make(
                'This claim is '.$claim->status->label().' and can no longer be rejected.'
            );
        }

        $claim->forceFill([
            'rejected_by_member_id' => $actor->id,
            'rejected_at' => Carbon::now(),
            'status' => GrantClaimStatus::Rejected,
            'note' => trim($claim->note.PHP_EOL.$reason),
        ])->save();

        return $claim;
    }

    /** Refuses a claim from someone who has left the group. */
    public function assertClaimable(Member $member): void
    {
        if ($member->status !== MemberStatus::Active) {
            throw DomainRuleException::make(
                "{$member->full_name} is {$member->status->label()} and cannot claim on the social fund."
            );
        }
    }

    /** The grant amounts the constitution fixes, for display on the claim forms. */
    public function funeralGrantNgwee(): int
    {
        return Kwacha::toNgwee(Kwacha::of(1_000));
    }

    public function unityBabyGrantNgwee(): int
    {
        return Kwacha::toNgwee(Kwacha::of(500));
    }
}
