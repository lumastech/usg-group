<?php

namespace App\Domain\Payments;

use App\Domain\Declarations\DeclarationService;
use App\Domain\Payments\Lenco\LencoOperator;
use App\Domain\Savings\SavingsLedger;
use App\Domain\SocialFund\SocialFundContributions;
use App\Domain\Wallets\TopUpService;
use App\Enums\LoanStatus;
use App\Enums\MobileMoneyOperator;
use App\Enums\PaymentChannel;
use App\Enums\PaymentPurpose;
use App\Exceptions\DomainRuleException;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\Declaration;
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
        protected TopUpService $topUps,
    ) {}

    /**
     * The month's savings, paid from a handset instead of across the table.
     *
     * An approved declaration is required. The trading sheet is built from
     * declarations, so a member with no row on it has nowhere for the money to land;
     * and a row the committee has not yet asked for is still a request they may send
     * back, which is not something to take money against.
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

        $this->declarations->assertPayable($member, $month);

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

    /**
     * The whole of one month's approved declaration, in a single prompt.
     *
     * The member is asked for what the committee approved — savings plus repayment,
     * to the ngwee — and for nothing else: a part payment leaves a variance on the
     * sheet for the table to chase, and a second prompt against the same declaration
     * would take the money twice. Anything the member is borrowing is not netted off
     * here; the loan is paid out to them separately once it is disbursed.
     *
     * The amount lands on the trading sheet as one figure and is split back into
     * savings and repayment when it is marked received, exactly as cash would be.
     */
    public function declaration(
        Declaration $declaration,
        Member $actor,
        ?string $phone = null,
        ?MobileMoneyOperator $operator = null,
    ): PaymentIntent {
        $ngwee = $this->assertDeclarationCollectable($declaration);

        return $this->start(
            PaymentPurpose::DeclarationSettlement,
            $declaration->member,
            Kwacha::ofNgwee($ngwee),
            $actor,
            $declaration->cycleMonth->cycle,
            payable: $declaration,
            month: $declaration->cycleMonth,
            phone: $phone,
            operator: $operator,
        );
    }

    /**
     * The same declaration, paid on the provider's hosted page instead of a handset.
     *
     * Nothing is sent anywhere: the intent is written down so the reference exists, and
     * the member finishes the payment in the widget, which is also the only place a
     * card number is ever typed. It is the same amount and the same refusals as the
     * prompt — a card must not be a way around a rule that stops a phone.
     */
    public function declarationByCard(Declaration $declaration, Member $actor): PaymentIntent
    {
        $ngwee = $this->assertDeclarationCollectable($declaration);

        $this->assertAboveMinimum($ngwee);

        return $this->intents->create(
            cycle: $declaration->cycleMonth->cycle,
            purpose: PaymentPurpose::DeclarationSettlement,
            amountNgwee: $ngwee,
            channel: PaymentChannel::Card,
            member: $declaration->member,
            payable: $declaration,
            month: $declaration->cycleMonth,
            requestedBy: $actor,
        );
    }

    /**
     * Every reason a declaration may not be collected against, and what it is worth.
     *
     * Applied whichever rail the money comes in on, so the two roads out of the
     * declaration screen can never disagree about what is owed or whether it is due.
     */
    protected function assertDeclarationCollectable(Declaration $declaration): int
    {
        $member = $declaration->member;
        $month = $declaration->cycleMonth;

        $this->declarations->assertPayable($member, $month);

        $standing = $declaration->standingPayment();

        /* A prompt nobody answered is not a payment in flight, it is a payment that
           never happened. Released here rather than left standing, or the member is
           locked out of paying by an attempt that will never conclude. */
        if ($standing !== null && $this->intents->abandonStalled($standing)) {
            $standing = $declaration->standingPayment();
        }

        if ($standing !== null) {
            throw DomainRuleException::make(
                $standing->status->hasSucceeded()
                    ? "The {$month->label()} declaration has already been paid."
                    : 'A payment for this declaration has already been started — approve the prompt on '
                        .'your phone, or wait for it to time out before starting another.'
            );
        }

        $ngwee = $declaration->expectedInNgwee();

        if ($ngwee <= 0) {
            throw DomainRuleException::make(
                "There is nothing to collect for {$month->label()}: the declaration brings no money to the table."
            );
        }

        /* The savings half is checked against the ledger that will take it, so a
           declaration approved before a rule changed cannot be collected against. */
        $this->savings->assertValidContribution(
            $member,
            $month,
            Kwacha::ofNgwee($declaration->getRawOriginal('saving_amount_ngwee')),
        );

        return $ngwee;
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

    /**
     * Money into the member's own wallet, and nowhere else yet.
     *
     * No domain rule is consulted, deliberately. There is no rule under which the group
     * will not take money into a member's own wallet, so this cannot produce the
     * failure the rest of this class exists to avoid: settled money the ledger then
     * refuses. The rules apply when the member pays the group out of the wallet, where
     * a refusal costs nothing because the money is still theirs.
     */
    public function topUp(
        Member $member,
        Cycle $cycle,
        Money $amount,
        Member $actor,
        ?string $phone = null,
        ?MobileMoneyOperator $operator = null,
    ): PaymentIntent {
        $this->topUps->assertAboveMinimum(Kwacha::toNgwee($amount));

        return $this->start(
            PaymentPurpose::WalletTopUp,
            $member,
            $amount,
            $actor,
            $cycle,
            phone: $phone,
            operator: $operator,
        );
    }

    /**
     * The same top-up, on the provider's hosted page instead of a handset.
     *
     * Nothing is sent anywhere: the intent is written down so the reference exists and
     * the member finishes the payment in the widget, which is the only place a card
     * number is ever typed.
     */
    public function topUpByCard(Member $member, Cycle $cycle, Money $amount, Member $actor): PaymentIntent
    {
        $ngwee = Kwacha::toNgwee($amount);

        $this->topUps->assertAboveMinimum($ngwee);
        $this->assertAboveMinimum($ngwee);

        return $this->intents->create(
            cycle: $cycle,
            purpose: PaymentPurpose::WalletTopUp,
            amountNgwee: $ngwee,
            channel: PaymentChannel::Card,
            member: $member,
            requestedBy: $actor,
        );
    }

    /**
     * The K250 the constitution asks for once, pushed to the member's handset.
     */
    public function socialFund(
        Member $member,
        Cycle $cycle,
        Money $amount,
        Member $actor,
        ?CycleMonth $month = null,
        ?string $phone = null,
        ?MobileMoneyOperator $operator = null,
    ): PaymentIntent {
        $this->assertFundContributionCollectable($member, $cycle, $amount);

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
     * The same contribution, paid on the provider's hosted page instead of a handset.
     *
     * Nothing is sent anywhere: the intent is written down so the reference exists and
     * the member finishes the payment in the widget, which is also the only place a
     * card number is ever typed. Same amount, same refusals as the prompt — a card must
     * not be a way around a rule that stops a phone.
     */
    public function socialFundByCard(
        Member $member,
        Cycle $cycle,
        Money $amount,
        Member $actor,
        ?CycleMonth $month = null,
    ): PaymentIntent {
        $this->assertFundContributionCollectable($member, $cycle, $amount);

        $ngwee = Kwacha::toNgwee($amount);

        $this->assertAboveMinimum($ngwee);

        return $this->intents->create(
            cycle: $cycle,
            purpose: PaymentPurpose::SocialFundContribution,
            amountNgwee: $ngwee,
            channel: PaymentChannel::Card,
            member: $member,
            month: $month,
            requestedBy: $actor,
        );
    }

    /**
     * Every reason the contribution may not be collected, whichever rail it comes in on.
     *
     * The fund takes this money once for the whole cycle, so a prompt already standing
     * is not something to send a second one against: two approved prompts take K500
     * from a member the ledger will only credit K250. A prompt nobody answered inside
     * the give-up window is released here rather than left blocking the next attempt.
     */
    public function assertFundContributionCollectable(Member $member, Cycle $cycle, Money $amount): void
    {
        $this->fund->assertPayable($member, $cycle, $amount);

        $standing = $this->standingFundContribution($member, $cycle);

        if ($standing !== null && $this->intents->abandonStalled($standing)) {
            $standing = $this->standingFundContribution($member, $cycle);
        }

        if ($standing !== null) {
            throw DomainRuleException::make(
                $standing->status->hasSucceeded()
                    ? 'Your social fund contribution has already been paid.'
                    : 'A payment for your social fund contribution has already been started — approve the '
                        .'prompt on your phone, or wait for it to time out before starting another.'
            );
        }
    }

    protected function standingFundContribution(Member $member, Cycle $cycle): ?PaymentIntent
    {
        return $this->intents->standingFor($member, PaymentPurpose::SocialFundContribution, $cycle);
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

        $this->assertAboveMinimum($ngwee);

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

    /** The floor the provider will not go below, checked before anything is written. */
    protected function assertAboveMinimum(int $ngwee): void
    {
        $minimum = (int) config('payments.collections.min_ngwee', 100);

        if ($ngwee < $minimum) {
            throw DomainRuleException::make(
                'A payment must be at least '.Kwacha::format($minimum).'.'
            );
        }
    }
}
