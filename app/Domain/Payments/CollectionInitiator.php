<?php

namespace App\Domain\Payments;

use App\Domain\Declarations\DeclarationService;
use App\Domain\Payments\Lenco\LencoOperator;
use App\Domain\Savings\SavingsLedger;
use App\Domain\SocialFund\SocialFundContributions;
use App\Enums\LoanStatus;
use App\Enums\MobileMoneyOperator;
use App\Enums\PaymentChannel;
use App\Enums\PaymentPurpose;
use App\Exceptions\DomainRuleException;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\Loan;
use App\Models\Member;
use App\Models\PaymentIntent;
use App\Support\Kwacha;
use Brick\Money\Money;

/**
 * Asks a member for money, having first checked the group could accept it.
 *
 * Every rule that would refuse the payment is applied here, before the request goes
 * out — a K750 contribution in a K500-increment month, a September payment over the
 * cap, a repayment against a loan that is not running. Money that has already left a
 * member's wallet and cannot be recorded is the worst outcome this module has, and
 * checking first is what avoids it.
 *
 * What comes back is always a payment waiting on the member's handset. Nothing here
 * settles anything; PaymentPoster does that when the provider says the money moved.
 */
class CollectionInitiator
{
    public function __construct(
        protected PaymentIntentService $intents,
        protected SavingsLedger $savings,
        protected SocialFundContributions $fund,
        protected DeclarationService $declarations,
    ) {}

    /**
     * The month's savings, paid from a handset instead of across the table.
     *
     * A declaration is required, because the trading sheet is built from declarations
     * and a member with no row on it has nowhere for the money to land. That is the
     * same position they would be in turning up with cash, so it is not a rule this
     * module is inventing.
     */
    public function savings(
        Member $member,
        CycleMonth $month,
        Money $amount,
        Member $actor,
        ?string $phone = null,
        ?MobileMoneyOperator $operator = null,
    ): PaymentIntent {
        $this->savings->assertValidContribution($member, $month, $amount);

        if ($this->declarations->find($member, $month) === null) {
            throw DomainRuleException::make(
                "{$member->full_name} has not declared for {$month->label()}, so there is nowhere to record this "
                    .'payment. The declaration has to come first.'
            );
        }

        return $this->start(
            PaymentPurpose::SavingsContribution,
            $member,
            $amount,
            $actor,
            $month->cycle,
            month: $month,
            phone: $phone,
            operator: $operator,
        );
    }

    public function joiningFee(
        Member $member,
        CycleMonth $month,
        Money $amount,
        Member $actor,
        ?string $phone = null,
        ?MobileMoneyOperator $operator = null,
    ): PaymentIntent {
        if ($member->joining_fee_paid) {
            throw DomainRuleException::make("{$member->full_name} has already paid the joining fee.");
        }

        $this->savings->assertMemberMaySave($member);

        return $this->start(
            PaymentPurpose::JoiningFee,
            $member,
            $amount,
            $actor,
            $month->cycle,
            month: $month,
            phone: $phone,
            operator: $operator,
        );
    }

    public function repayment(
        Loan $loan,
        Money $amount,
        Member $actor,
        ?CycleMonth $month = null,
        ?string $phone = null,
        ?MobileMoneyOperator $operator = null,
    ): PaymentIntent {
        if (! in_array($loan->status, [LoanStatus::Disbursed, LoanStatus::Repaying, LoanStatus::Defaulted], true)) {
            throw DomainRuleException::make(
                'Only a loan that has been disbursed can take a repayment; this one is '
                    .strtolower($loan->status->label()).'.'
            );
        }

        return $this->start(
            PaymentPurpose::LoanRepayment,
            $loan->member,
            $amount,
            $actor,
            $loan->cycle,
            payable: $loan,
            month: $month,
            phone: $phone,
            operator: $operator,
        );
    }

    public function socialFund(
        Member $member,
        Cycle $cycle,
        Money $amount,
        Member $actor,
        ?CycleMonth $month = null,
        ?string $phone = null,
        ?MobileMoneyOperator $operator = null,
    ): PaymentIntent {
        $this->fund->assertPayable($member, $cycle, $amount);

        return $this->start(
            PaymentPurpose::SocialFundContribution,
            $member,
            $amount,
            $actor,
            $cycle,
            month: $month,
            phone: $phone,
            operator: $operator,
        );
    }

    /**
     * Writes the payment down, then asks the provider for it.
     *
     * The number is the member's own unless one is passed — a treasurer pushing a
     * request at the trading table is asking the member on the phone in front of them,
     * and that number is not always the one on the member record.
     */
    protected function start(
        PaymentPurpose $purpose,
        Member $member,
        Money $amount,
        Member $actor,
        Cycle $cycle,
        mixed $payable = null,
        ?CycleMonth $month = null,
        ?string $phone = null,
        ?MobileMoneyOperator $operator = null,
    ): PaymentIntent {
        $ngwee = Kwacha::toNgwee($amount);
        $minimum = (int) config('payments.collections.min_ngwee', 100);

        if ($ngwee < $minimum) {
            throw DomainRuleException::make(
                'A payment must be at least '.Kwacha::format($minimum).'.'
            );
        }

        $number = $phone ?? $member->phone;

        if ($number === null || ! LencoOperator::isValidPhone($number)) {
            throw DomainRuleException::make(
                "There is no Zambian mobile number on record for {$member->full_name} to ask for this payment."
            );
        }

        $intent = $this->intents->create(
            cycle: $cycle,
            purpose: $purpose,
            amountNgwee: $ngwee,
            channel: PaymentChannel::MobileMoney,
            member: $member,
            payable: $payable,
            month: $month,
            requestedBy: $actor,
        );

        return $this->intents->sendCollection(
            $intent,
            CollectionRequest::from($intent, $number, $operator ?? LencoOperator::forPhone($number)),
        );
    }
}
