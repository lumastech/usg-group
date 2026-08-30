<?php

namespace App\Domain\Payments;

use App\Domain\Loans\LoanDisbursementQueue;
use App\Domain\Loans\LoanRepaymentService;
use App\Domain\Savings\SavingsLedger;
use App\Domain\SocialFund\GrantClaimService;
use App\Domain\SocialFund\SocialFundLedger;
use App\Domain\Trading\TradingSessionService;
use App\Enums\LoanTransactionType;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\SavingsTransactionType;
use App\Enums\SocialFundTransactionType;
use App\Enums\TransactionSource;
use App\Exceptions\DomainRuleException;
use App\Exceptions\PaymentDeferredException;
use App\Models\FuneralGrantClaim;
use App\Models\Loan;
use App\Models\Member;
use App\Models\PaymentIntent;
use App\Models\Payout;
use App\Models\TradingSession;
use App\Models\UnityBabyClaim;
use App\Support\Kwacha;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The only thing that turns money the provider moved into an entry in the group's books.
 *
 * Everything else — webhooks, the poller, the widget's callback — may do exactly one
 * thing: mark an intent as having succeeded. Posting happens here, once, through the
 * same domain services a cash payment goes through, so an online K750 contribution is
 * refused by the same rule that refuses one handed across the table.
 *
 * The claim on an intent is an atomic UPDATE rather than a read-then-write. A webhook
 * and a poll arrive for the same payment constantly, and two workers both seeing
 * "Successful" a millisecond apart is not a rare case.
 */
class PaymentPoster
{
    public function __construct(
        protected PaymentIntentService $intents,
        protected SavingsLedger $savings,
        protected LoanRepaymentService $repayments,
        protected SocialFundLedger $fund,
        protected GrantClaimService $grants,
        protected TradingSessionService $trading,
        protected LoanDisbursementQueue $disbursements,
    ) {}

    /**
     * Records a settled payment in the ledgers.
     *
     * Returns false for anything not ready — money still in flight, a payment already
     * posted, a savings payment waiting for the trading sheet to open — and never
     * throws for a ledger's refusal: that money is parked at NeedsAttention with the
     * ledger's own words on it, for a committee member to decide about.
     */
    public function post(PaymentIntent $intent): bool
    {
        if (! $intent->status->hasSucceeded() || $intent->status === PaymentStatus::Posted) {
            return false;
        }

        try {
            return DB::transaction(function () use ($intent): bool {
                if (! $this->claim($intent)) {
                    return false;
                }

                $transaction = $this->record($intent->refresh());

                if ($transaction !== null) {
                    $intent->postedTransaction()->associate($transaction);
                    $intent->save();
                }

                return true;
            });
        } catch (PaymentDeferredException) {
            return false;
        } catch (Throwable $exception) {
            $this->intents->markNeedsAttention($intent->refresh(), $exception->getMessage());

            return false;
        }
    }

    /**
     * Takes the payment, or finds somebody else already took it.
     *
     * The WHERE clause is the lock: only one caller can move a row out of Successful
     * or Settled, and everybody else gets zero rows and goes home.
     */
    protected function claim(PaymentIntent $intent): bool
    {
        return PaymentIntent::query()
            ->whereKey($intent->id)
            ->whereIn('status', [PaymentStatus::Successful->value, PaymentStatus::Settled->value])
            ->update([
                'status' => PaymentStatus::Posted->value,
                'posted_at' => Carbon::now(),
            ]) === 1;
    }

    /** Sends the payment to whichever ledger its purpose belongs to. */
    protected function record(PaymentIntent $intent): ?Model
    {
        return match ($intent->purpose) {
            PaymentPurpose::SavingsContribution,
            PaymentPurpose::DeclarationSettlement => $this->markOnTradingSheet($intent),
            PaymentPurpose::JoiningFee => $this->postJoiningFee($intent),
            PaymentPurpose::LoanRepayment => $this->postRepayment($intent),
            PaymentPurpose::SocialFundContribution => $this->postFundContribution($intent),
            PaymentPurpose::LoanDisbursement => $this->postDisbursement($intent),
            PaymentPurpose::Payout, PaymentPurpose::ShareOut => $this->stampPayout($intent),
            PaymentPurpose::FuneralGrant, PaymentPurpose::UnityBabyGrant => $this->payGrant($intent),
            PaymentPurpose::DiasporaApportionment => $this->stampApportionment($intent),
        };
    }

    /**
     * Money for the table does not post on arrival — it takes its turn on the sheet.
     *
     * A whole declaration paid in one prompt lands here as one figure; `markReceived`
     * splits it back into savings and repayment against the declaration, exactly as it
     * would for cash counted at the table.
     *
     * `TradingConcluder::conclude()` is the only thing that posts a month, and the
     * order inside it is the constitution's: missed installments, then interest, then
     * savings, then repayments. A payment that jumped that queue would quietly cut the
     * interest owed to everybody else.
     */
    protected function markOnTradingSheet(PaymentIntent $intent): ?Model
    {
        $session = $this->openSessionFor($intent);

        if ($session === null) {
            throw PaymentDeferredException::make(
                'Waiting for the trading session to open before this can go on the sheet.'
            );
        }

        $entry = $session->entries()
            ->where('member_id', $intent->member_id)
            ->with(['session', 'member', 'declaration'])
            ->first();

        /*
         * The sheet is built from declarations, so a member who did not declare has no
         * row for their money to land on — exactly as they would have none if they
         * turned up at the table with cash. That is a committee decision, not something
         * to invent a row for, so the payment is parked rather than deferred.
         */
        if ($entry === null) {
            throw DomainRuleException::make(
                ($intent->member->full_name ?? 'This member').' has no declaration for this month, so there is '
                    .'no row on the trading sheet for this money.'
            );
        }

        $alreadyReceived = (int) $entry->getRawOriginal('actual_in_ngwee');

        $this->trading->markReceived(
            $entry,
            Kwacha::ofNgwee($alreadyReceived + $intent->amount_ngwee->getMinorAmount()->toInt()),
            $intent->effectiveDate(),
            $this->actorFor($intent),
        );

        /*
         * No ledger row yet, so nothing to point at. The intent is Posted because our
         * part is done: the money is on the sheet and the month will carry it.
         */
        return null;
    }

    protected function postJoiningFee(PaymentIntent $intent): Model
    {
        $member = $this->memberFor($intent);
        $month = $intent->cycleMonth ?? $intent->cycle->monthFor($intent->effectiveDate());

        if ($month === null) {
            throw PaymentDeferredException::make('This payment falls outside the cycle\'s months.');
        }

        $transaction = $this->savings->record(
            member: $member,
            month: $month,
            amount: $intent->amount_ngwee,
            actor: $this->actorFor($intent),
            type: SavingsTransactionType::JoiningFee,
            source: TransactionSource::Gateway,
            occurredOn: $intent->effectiveDate(),
        );

        if (! $member->joining_fee_paid) {
            $member->forceFill(['joining_fee_paid' => true])->save();
        }

        return $transaction;
    }

    /**
     * A repayment posts straight away, dated by the provider's clock.
     *
     * A member who paid at 23:50 on the 7th and whose webhook we handle on the 8th is
     * allocated on the 7th. Anything else charges them the daily late penalty for the
     * depth of our own queue.
     */
    protected function postRepayment(PaymentIntent $intent): Model
    {
        $loan = $intent->payable;

        if (! $loan instanceof Loan) {
            throw PaymentDeferredException::make('This repayment is not attached to a loan.');
        }

        return $this->repayments->record(
            loan: $loan,
            amount: $intent->amount_ngwee,
            actor: $this->actorFor($intent),
            receivedOn: $intent->effectiveDate(),
            month: $intent->cycleMonth,
        );
    }

    protected function postFundContribution(PaymentIntent $intent): Model
    {
        return $this->fund->receive(
            cycle: $intent->cycle,
            type: SocialFundTransactionType::Contribution,
            amount: $intent->amount_ngwee,
            occurredOn: $intent->effectiveDate(),
            member: $this->memberFor($intent),
            actor: $this->actorFor($intent),
            reference: $intent,
        );
    }

    /**
     * A disbursement posts only once the money has actually left.
     *
     * The queue re-checks eligibility as it always does, so a member whose position
     * changed between approval and payment is caught here rather than left owing money
     * they should not have been given.
     */
    protected function postDisbursement(PaymentIntent $intent): ?Model
    {
        $loan = $intent->payable;

        if (! $loan instanceof Loan) {
            throw PaymentDeferredException::make('This disbursement is not attached to a loan.');
        }

        $month = $intent->cycleMonth ?? $intent->cycle->monthFor($intent->effectiveDate());

        if ($month === null) {
            throw PaymentDeferredException::make('This disbursement falls outside the cycle\'s months.');
        }

        $this->disbursements->disburse($loan, $month, $this->actorFor($intent));

        return $loan->transactions()
            ->where('type', LoanTransactionType::Disbursement->value)
            ->latest('id')
            ->first();
    }

    /**
     * The payout itself was executed and signed before any money moved; this only
     * records that it has now arrived.
     */
    protected function stampPayout(PaymentIntent $intent): Model
    {
        $payout = $intent->payable;

        if (! $payout instanceof Payout) {
            throw PaymentDeferredException::make('This transfer is not attached to a payout.');
        }

        $payout->forceFill([
            'paid_at' => $intent->effectiveDate(),
            'paid_method' => $intent->channel->value,
            'payment_intent_id' => $intent->id,
        ])->save();

        return $payout;
    }

    /** Debits the fund for a grant, using the two signatures captured at approval. */
    protected function payGrant(PaymentIntent $intent): Model
    {
        $claim = $intent->payable;

        if (! $claim instanceof FuneralGrantClaim && ! $claim instanceof UnityBabyClaim) {
            throw PaymentDeferredException::make('This transfer is not attached to a grant claim.');
        }

        $actor = $intent->approvedBy ?? $intent->requestedBy;
        $second = $intent->secondApprover;

        if ($actor === null || $second === null) {
            throw PaymentDeferredException::make('A grant needs both signatures recorded before it can be paid.');
        }

        return $this->grants->pay($claim, $actor, $second, $intent->effectiveDate());
    }

    /** A diaspora member's share, marked as sent. */
    protected function stampApportionment(PaymentIntent $intent): ?Model
    {
        $item = $intent->payable;

        if ($item === null) {
            throw PaymentDeferredException::make('This transfer is not attached to an apportionment.');
        }

        $item->forceFill([
            'paid_on' => $intent->effectiveDate(),
            'reference' => $intent->reference,
        ])->save();

        return $item;
    }

    /** The open session this savings payment belongs to, if the month has one yet. */
    protected function openSessionFor(PaymentIntent $intent): ?TradingSession
    {
        $month = $intent->cycleMonth ?? $intent->cycle->monthFor($intent->effectiveDate());

        if ($month === null) {
            return null;
        }

        $session = $this->trading->find($month);

        return $session !== null && $session->isOpen() ? $session : null;
    }

    protected function memberFor(PaymentIntent $intent): Member
    {
        $member = $intent->member;

        if ($member === null) {
            throw PaymentDeferredException::make('This payment has no member to record it against.');
        }

        return $member;
    }

    /**
     * Who the ledger records as having done this.
     *
     * A member paying their own dues is the actor; a payment the treasurer pushed to
     * somebody's handset is theirs. Neither is "the system": somebody asked for this
     * money, and the audit trail should say who.
     */
    protected function actorFor(PaymentIntent $intent): Member
    {
        return $intent->requestedBy ?? $this->memberFor($intent);
    }
}
