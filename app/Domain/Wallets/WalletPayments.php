<?php

namespace App\Domain\Wallets;

use App\Domain\Declarations\DeclarationService;
use App\Domain\Loans\LoanRepaymentService;
use App\Domain\Savings\SavingsLedger;
use App\Domain\SocialFund\SocialFundContributions;
use App\Domain\Trading\TradingSessionService;
use App\Enums\LoanStatus;
use App\Enums\SavingsTransactionType;
use App\Enums\TransactionSource;
use App\Enums\WalletTransferPurpose;
use App\Exceptions\DomainRuleException;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\Declaration;
use App\Models\Loan;
use App\Models\Member;
use App\Models\TradingEntry;
use App\Models\WalletTransfer;
use App\Support\Kwacha;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What a member pays the group, out of their own wallet.
 *
 * Every guard that used to live in `CollectionInitiator` — checked before a provider
 * request went out, because checking afterwards was too late — lives here instead. The
 * difference is not where the code sits but what a refusal costs: nothing has moved,
 * the money is still the member's, and there is no settled payment for a committee
 * member to decide about. That was the whole point.
 *
 * The rules themselves are unchanged. A K750 contribution in a K500-increment month is
 * refused here by the same `SavingsLedger` that refuses cash across the table.
 */
class WalletPayments
{
    public function __construct(
        protected WalletTransferService $transfers,
        protected WalletRegistry $wallets,
        protected SavingsLedger $savings,
        protected DeclarationService $declarations,
        protected TradingSessionService $trading,
        protected SocialFundContributions $fund,
        protected LoanRepaymentService $repayments,
    ) {}

    /**
     * The month's savings, paid out of the wallet instead of across the table.
     *
     * The money does not post on arrival: it marks the member's row on the trading
     * sheet and takes its turn when `TradingConcluder::conclude()` runs. The posting
     * order inside that is the constitution's — missed installments, then interest,
     * then savings, then repayments — and a wallet payment must not jump it any more
     * than a gateway payment could.
     *
     * The two outcomes the poster used to have to invent an answer for are refusals
     * now, before anything moves: no open session, and no row on the sheet.
     */
    public function paySavings(
        Member $member,
        CycleMonth $month,
        Money $amount,
        Member $actor,
        ?CarbonInterface $at = null,
    ): WalletTransfer {
        $this->savings->assertValidContribution($member, $month, $amount);
        $this->declarations->assertPayable($member, $month);

        $entry = $this->sheetRowFor($member, $month);
        $at ??= Carbon::now();

        return $this->transfers->transfer(
            from: $this->wallets->forMember($member, $month->cycle),
            to: $this->wallets->group($month->cycle),
            amount: $amount,
            purpose: WalletTransferPurpose::SavingsContribution,
            actor: $actor,
            payable: $entry,
            occurredAt: $at,
            note: 'Savings for '.$month->label(),
            record: fn (): Model => $this->markOnSheet($entry, $amount, $at, $actor),
        );
    }

    /**
     * The whole of one month's approved declaration, in one movement.
     *
     * Savings plus repayment, to the ngwee — not less, because a part payment leaves a
     * variance for the table to chase, and not twice: a declaration already settled
     * from a wallet is refused here rather than by a guard against a payment in flight.
     * There is no "in flight" any more, which is one whole class of state gone.
     */
    public function settleDeclaration(
        Declaration $declaration,
        Member $actor,
        ?CarbonInterface $at = null,
    ): WalletTransfer {
        $member = $declaration->member;
        $month = $declaration->cycleMonth;

        $this->declarations->assertPayable($member, $month);

        if ($this->settlementFor($declaration) !== null) {
            throw DomainRuleException::make("The {$month->label()} declaration has already been paid.");
        }

        $ngwee = $declaration->expectedInNgwee();

        if ($ngwee <= 0) {
            throw DomainRuleException::make(
                "There is nothing to collect for {$month->label()}: the declaration brings no money to the table."
            );
        }

        /* The savings half is checked against the ledger that will take it, so a
           declaration approved before a rule changed cannot be settled against. */
        $this->savings->assertValidContribution(
            $member,
            $month,
            Kwacha::ofNgwee($declaration->getRawOriginal('saving_amount_ngwee')),
        );

        $entry = $this->sheetRowFor($member, $month);
        $at ??= Carbon::now();
        $amount = Kwacha::ofNgwee($ngwee);

        return $this->transfers->transfer(
            from: $this->wallets->forMember($member, $month->cycle),
            to: $this->wallets->group($month->cycle),
            amount: $amount,
            purpose: WalletTransferPurpose::DeclarationSettlement,
            actor: $actor,
            payable: $declaration,
            occurredAt: $at,
            note: 'Declaration for '.$month->label(),
            record: fn (): Model => $this->markOnSheet($entry, $amount, $at, $actor),
        );
    }

    /** The joining fee the constitution asks for once, paid out of the wallet. */
    public function payJoiningFee(
        Member $member,
        CycleMonth $month,
        Money $amount,
        Member $actor,
        ?CarbonInterface $at = null,
    ): WalletTransfer {
        if ($member->joining_fee_paid) {
            throw DomainRuleException::make("{$member->full_name} has already paid the joining fee.");
        }

        $this->savings->assertMemberMaySave($member);

        $at ??= Carbon::now();

        return $this->transfers->transfer(
            from: $this->wallets->forMember($member, $month->cycle),
            to: $this->wallets->group($month->cycle),
            amount: $amount,
            purpose: WalletTransferPurpose::JoiningFee,
            actor: $actor,
            occurredAt: $at,
            note: 'Joining fee',
            record: function () use ($member, $month, $amount, $actor, $at): Model {
                $transaction = $this->savings->record(
                    member: $member,
                    month: $month,
                    amount: $amount,
                    actor: $actor,
                    type: SavingsTransactionType::JoiningFee,
                    source: TransactionSource::System,
                    occurredOn: $at,
                );

                if (! $member->joining_fee_paid) {
                    $member->forceFill(['joining_fee_paid' => true])->save();
                }

                return $transaction;
            },
        );
    }

    /**
     * The K250 the fund asks for once.
     *
     * The guard that used to stop a second prompt going out against a live one is gone
     * with the prompt: `SocialFundContributions::assertPayable()` refuses a second
     * contribution on its own, and no money moves until it has agreed.
     */
    public function payFundContribution(
        Member $member,
        Money $amount,
        Member $actor,
        ?Cycle $cycle = null,
        ?CarbonInterface $at = null,
    ): WalletTransfer {
        $cycle ??= $member->cycle;

        $this->fund->assertPayable($member, $cycle, $amount);

        $at ??= Carbon::now();

        return $this->transfers->transfer(
            from: $this->wallets->forMember($member, $cycle),
            to: $this->wallets->group($cycle),
            amount: $amount,
            purpose: WalletTransferPurpose::SocialFundContribution,
            actor: $actor,
            occurredAt: $at,
            note: 'Social fund contribution',
            record: fn (): Model => $this->fund->record($member, $amount, $actor, $at),
        );
    }

    /**
     * A repayment against a running loan.
     *
     * Dated by the wallet transfer, not by the queue that processed it: a member who
     * paid at 23:50 on the 7th is allocated on the 7th, or they are charged the daily
     * late penalty for the depth of somebody else's queue.
     */
    public function repayLoan(
        Loan $loan,
        Money $amount,
        Member $actor,
        ?CycleMonth $month = null,
        ?CarbonInterface $at = null,
    ): WalletTransfer {
        if (! in_array($loan->status, [LoanStatus::Disbursed, LoanStatus::Repaying, LoanStatus::Defaulted], true)) {
            throw DomainRuleException::make(
                'Only a loan that has been disbursed can take a repayment; this one is '
                    .strtolower($loan->status->label()).'.'
            );
        }

        $at ??= Carbon::now();

        return $this->transfers->transfer(
            from: $this->wallets->forMember($loan->member, $loan->cycle),
            to: $this->wallets->group($loan->cycle),
            amount: $amount,
            purpose: WalletTransferPurpose::LoanRepayment,
            actor: $actor,
            payable: $loan,
            occurredAt: $at,
            note: "Repayment on loan #{$loan->id}",
            record: fn (): Model => $this->repayments->record($loan, $amount, $actor, $at, $month),
        );
    }

    /** The transfer that already settled a declaration, if there is one. */
    public function settlementFor(Declaration $declaration): ?WalletTransfer
    {
        return WalletTransfer::query()
            ->acrossCycles()
            ->where('payable_type', $declaration->getMorphClass())
            ->where('payable_id', $declaration->getKey())
            ->where('purpose', WalletTransferPurpose::DeclarationSettlement->value)
            ->first();
    }

    /**
     * The member's row on the open trading sheet.
     *
     * Both refusals are synchronous now. A month with no open session cannot take money
     * for the sheet, and the sheet is built from declarations — a member who did not
     * declare has no row for their money to land on, exactly as they would have none if
     * they turned up at the table with cash. Neither is ours to invent.
     */
    protected function sheetRowFor(Member $member, CycleMonth $month): TradingEntry
    {
        $session = $this->trading->find($month);

        if ($session === null || ! $session->isOpen()) {
            throw DomainRuleException::make(
                "The trading session for {$month->label()} is not open, so money cannot go on the sheet yet."
            );
        }

        $entry = $session->entries()
            ->where('member_id', $member->id)
            ->with(['session', 'member', 'declaration'])
            ->first();

        if ($entry === null) {
            throw DomainRuleException::make(
                "{$member->full_name} has no declaration for {$month->label()}, so there is no row on the "
                    .'trading sheet for this money.'
            );
        }

        return $entry;
    }

    /** Adds this payment to whatever the row has already taken. */
    protected function markOnSheet(
        TradingEntry $entry,
        Money $amount,
        CarbonInterface $at,
        Member $actor,
    ): TradingEntry {
        $already = (int) $entry->getRawOriginal('actual_in_ngwee');

        return $this->trading->markReceived(
            $entry,
            Kwacha::ofNgwee($already + Kwacha::toNgwee($amount)),
            $at,
            $actor,
        );
    }
}
