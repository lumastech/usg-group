<?php

namespace App\Domain\Payments;

use App\Domain\Approvals\TwoPersonRule;
use App\Enums\LoanStatus;
use App\Enums\PaymentPurpose;
use App\Exceptions\DomainRuleException;
use App\Models\CycleMonth;
use App\Models\FuneralGrantClaim;
use App\Models\Loan;
use App\Models\Member;
use App\Models\PaymentIntent;
use App\Models\Payout;
use App\Models\PayoutDestination;
use App\Models\UnityBabyClaim;
use App\Support\Kwacha;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Sends the group's money out, once somebody with the authority to has said so.
 *
 * Three things are true of everything here. The domain decision always comes first —
 * this is the plumbing after a loan was approved or a payout signed, never the
 * authorisation itself. The ledgers are not touched: PaymentPoster records the money
 * when the provider confirms it left, so a failed transfer leaves no trace of a payment
 * that never happened. And the group's balance at the provider is checked first,
 * because collections have to settle before they can be sent out again and a share-out
 * day can find the float short.
 */
class TransferInitiator
{
    public function __construct(
        protected PaymentIntentService $intents,
        protected PayoutDestinationService $destinations,
        protected PaymentGateway $gateway,
        protected TwoPersonRule $twoPersonRule,
    ) {}

    /**
     * Sends an approved loan to the member.
     *
     * The loan is not marked disbursed here — the queue does that when the money is
     * confirmed gone, re-checking eligibility as it always has. Until then the loan is
     * still Approved and still holds its place in the queue.
     */
    public function disburseLoan(
        Loan $loan,
        CycleMonth $month,
        Member $actor,
        ?Member $secondApprover = null,
    ): PaymentIntent {
        if ($loan->status !== LoanStatus::Approved) {
            throw DomainRuleException::make(
                'Only an approved loan can be sent out; this one is '.strtolower($loan->status->label()).'.'
            );
        }

        if ($this->inFlightFor($loan)) {
            throw DomainRuleException::make('This loan already has a payment on its way to the member.');
        }

        return $this->send(
            PaymentPurpose::LoanDisbursement,
            $loan->member,
            $loan->principal_ngwee,
            $loan,
            $actor,
            $secondApprover,
            $month,
            "Unity Savings loan #{$loan->id}",
        );
    }

    /**
     * Pays a settled closure.
     *
     * The payout was executed, signed and frozen before any of this; it stands whether
     * or not the transfer works. A failure here means the money has to go out another
     * way, not that the member's position reopens.
     */
    public function payPayout(Payout $payout, Member $actor, ?Member $secondApprover = null): PaymentIntent
    {
        if ($payout->isPaid()) {
            throw DomainRuleException::make('That payout has already been paid.');
        }

        if ($this->inFlightFor($payout)) {
            throw DomainRuleException::make('That payout already has a transfer on its way.');
        }

        return $this->send(
            PaymentPurpose::Payout,
            $payout->member,
            $payout->amount_ngwee,
            $payout,
            $actor,
            $secondApprover,
            null,
            'Unity Savings share-out',
        );
    }

    /**
     * Sends an approved grant.
     *
     * A funeral grant is often paid to a next of kin rather than to the member, so a
     * destination may be passed in rather than taken from the member's own — checked
     * with the provider the same way, but not kept as anybody's default.
     *
     * @param  FuneralGrantClaim|UnityBabyClaim  $claim
     */
    public function payGrant(
        Model $claim,
        Member $actor,
        Member $secondApprover,
        ?PayoutDestination $destination = null,
    ): PaymentIntent {
        if (! $claim->isPayable()) {
            throw DomainRuleException::make(
                'Only an approved claim can be paid; this one is '.$claim->status->label().'.'
            );
        }

        $purpose = $claim instanceof FuneralGrantClaim
            ? PaymentPurpose::FuneralGrant
            : PaymentPurpose::UnityBabyGrant;

        return $this->send(
            $purpose,
            $claim->member,
            $claim->amount_ngwee,
            $claim,
            $actor,
            $secondApprover,
            null,
            $purpose->label(),
            $destination,
        );
    }

    /**
     * Throws unless the group's account holds enough to send this much.
     *
     * The headroom is kept back deliberately: a batch that drains the account to zero
     * leaves nothing for the fee on the last transfer.
     */
    public function assertFundsAvailable(int $ngwee): void
    {
        $available = $this->gateway->balanceNgwee();
        $headroom = (int) config('payments.transfers.balance_headroom_ngwee', 0);

        if ($available - $headroom < $ngwee) {
            throw DomainRuleException::make(sprintf(
                'The group\'s Lenco account holds %s, which is not enough to send %s.',
                Kwacha::format($available),
                Kwacha::format($ngwee),
            ));
        }
    }

    /** Whether money is already on its way for this loan, payout or claim. */
    public function inFlightFor(Model $payable): bool
    {
        return PaymentIntent::query()
            ->acrossCycles()
            ->where('payable_type', $payable->getMorphClass())
            ->where('payable_id', $payable->getKey())
            ->where(function ($query): void {
                $query->awaitingOutcome()->orWhere->unposted();
            })
            ->exists();
    }

    /** The member's default destination, or nothing, which means pay them in cash. */
    public function destinationFor(Member $member): ?PayoutDestination
    {
        return $member->defaultPayoutDestination()->first();
    }

    protected function send(
        PaymentPurpose $purpose,
        ?Member $member,
        Money $amount,
        Model $payable,
        Member $actor,
        ?Member $secondApprover,
        ?CycleMonth $month,
        string $narration,
        ?PayoutDestination $destination = null,
    ): PaymentIntent {
        if ($member === null) {
            throw DomainRuleException::make('There is no member on this record to pay.');
        }

        $destination ??= $this->destinationFor($member);

        if ($destination === null) {
            throw DomainRuleException::make(
                "{$member->full_name} has not said where to send their money, so this has to be paid by hand."
            );
        }

        $this->destinations->assertPayable($destination);
        $this->requireSignatures($purpose, $destination, $member, $actor, $secondApprover);

        $ngwee = Kwacha::toNgwee($amount);

        if ($ngwee <= 0) {
            throw DomainRuleException::make('There is nothing to send.');
        }

        $this->assertFundsAvailable($ngwee);

        $intent = $this->intents->create(
            cycle: $payable->cycle ?? $member->cycle,
            purpose: $purpose,
            amountNgwee: $ngwee,
            channel: $destination->type->channel(),
            member: $member,
            payable: $payable,
            month: $month,
            destination: $destination,
            requestedBy: $actor,
            attributes: [
                'approved_by_member_id' => $actor->id,
                'second_approver_member_id' => $secondApprover?->id,
            ],
        );

        return $this->intents->sendTransfer($intent, TransferRequest::from($intent, $destination, $narration));
    }

    /**
     * Two signatures where the purpose demands them, and where the destination itself
     * is the thing that looks new or wrong.
     *
     * The second case is the one that matters: an attacker who reaches a member's login
     * can change the number money goes to, but they cannot produce a second committee
     * member's password on the same device.
     */
    protected function requireSignatures(
        PaymentPurpose $purpose,
        PayoutDestination $destination,
        Member $member,
        Member $actor,
        ?Member $secondApprover,
    ): void {
        $needed = $purpose->requiresSecondApprover() || $this->destinations->needsSecondSignature($destination);

        if (! $needed) {
            return;
        }

        if ($secondApprover === null) {
            throw DomainRuleException::make($this->reasonForSecondSignature($purpose, $destination));
        }

        $this->twoPersonRule->assertDistinctCommittee($actor, $secondApprover, $member);
    }

    protected function reasonForSecondSignature(PaymentPurpose $purpose, PayoutDestination $destination): string
    {
        if ($destination->hasUnconfirmedNameMismatch()) {
            return 'The account is in the name of '.($destination->resolved_account_name ?? 'somebody else')
                .', so a second committee member has to confirm this before the money goes.';
        }

        if ($destination->isWithinCoolingOff()) {
            return 'This destination was changed in the last '
                .config('payments.transfers.destination_cooling_off_hours')
                .' hours, so a second committee member has to confirm this before the money goes.';
        }

        return 'Sending this needs a second committee member to confirm it.';
    }
}
