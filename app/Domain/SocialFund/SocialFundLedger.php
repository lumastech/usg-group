<?php

namespace App\Domain\SocialFund;

use App\Domain\Approvals\TwoPersonRule;
use App\Domain\Payouts\LedgerFreeze;
use App\Domain\Support\MoneyMutator;
use App\Enums\SocialFundTransactionType;
use App\Exceptions\DomainRuleException;
use App\Exceptions\InsufficientSocialFundException;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\Member;
use App\Models\SocialFundTransaction;
use App\Support\Kwacha;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The Social Fund's append-only ledger.
 *
 * Everything the fund receives or pays out lands here as a signed entry, so the
 * balance is a SUM of one column and there is no total anywhere to fall out of step.
 *
 * Two rules are enforced on every posting and nowhere else: money leaving the fund
 * carries a second committee signature, and the balance may never go below zero.
 */
class SocialFundLedger
{
    public function __construct(
        protected MoneyMutator $mutator,
        protected TwoPersonRule $twoPersonRule,
        protected LedgerFreeze $freeze,
    ) {}

    /**
     * Posts an inflow. The amount is stored positive whatever sign it arrives with.
     */
    public function receive(
        Cycle $cycle,
        SocialFundTransactionType $type,
        Money $amount,
        CarbonInterface $occurredOn,
        ?Member $member = null,
        ?Member $actor = null,
        ?Model $reference = null,
        ?string $note = null,
    ): SocialFundTransaction {
        return $this->post(
            $cycle,
            $type,
            Kwacha::ofNgwee(abs(Kwacha::toNgwee($amount))),
            $occurredOn,
            $member,
            $actor,
            null,
            $reference,
            $note,
        );
    }

    /**
     * Posts an outflow, which always needs two distinct committee signatures.
     *
     * The amount is stored negative whatever sign it arrives with, so a caller cannot
     * accidentally credit the fund by asking it to pay something out.
     */
    public function pay(
        Cycle $cycle,
        SocialFundTransactionType $type,
        Money $amount,
        CarbonInterface $occurredOn,
        Member $actor,
        Member $secondApprover,
        ?Member $member = null,
        ?Model $reference = null,
        ?string $note = null,
    ): SocialFundTransaction {
        return $this->post(
            $cycle,
            $type,
            Kwacha::ofNgwee(-abs(Kwacha::toNgwee($amount))),
            $occurredOn,
            $member,
            $actor,
            $secondApprover,
            $reference,
            $note,
        );
    }

    /**
     * The single door into the ledger.
     *
     * @param  Money  $amount  signed: positive into the fund, negative out of it
     */
    public function post(
        Cycle $cycle,
        SocialFundTransactionType $type,
        Money $amount,
        CarbonInterface $occurredOn,
        ?Member $member = null,
        ?Member $actor = null,
        ?Member $secondApprover = null,
        ?Model $reference = null,
        ?string $note = null,
    ): SocialFundTransaction {
        $ngwee = Kwacha::toNgwee($amount);

        if ($ngwee === 0) {
            throw DomainRuleException::make('A social fund entry must move a non-zero amount.');
        }

        $this->freeze->assertOpen($member);
        $this->assertApproved($type, $ngwee, $actor, $secondApprover, $member);
        $this->assertCovered($cycle, $ngwee);

        $month = $cycle->monthFor(Carbon::parse($occurredOn->toDateString()));

        $write = fn (): SocialFundTransaction => SocialFundTransaction::create([
            'cycle_id' => $cycle->id,
            'cycle_month_id' => $month?->id,
            'member_id' => $member?->id,
            'type' => $type,
            'amount_ngwee' => $amount,
            'occurred_on' => $occurredOn,
            'reference_type' => $reference === null ? null : $reference->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'recorded_by_member_id' => $actor?->id,
            'second_approver_member_id' => $secondApprover?->id,
            'note' => $note,
        ]);

        $reason = 'Social fund '.$type->label().' of '.Kwacha::format($amount)
            .($member === null ? '' : " for {$member->full_name}");

        $context = ['cycle_id' => $cycle->id, 'member_id' => $member?->id, 'type' => $type->value];

        return $actor === null
            ? $this->mutator->system($reason, $write, $context)
            : $this->mutator->mutate($actor, $reason, $write, $context);
    }

    /** What the fund holds. */
    public function balance(Cycle $cycle): Money
    {
        return Kwacha::ofNgwee((int) $this->entries($cycle)->sum('amount_ngwee'));
    }

    /** What the fund held at the end of a given month. */
    public function balanceAt(Cycle $cycle, CycleMonth $month): Money
    {
        return Kwacha::ofNgwee((int) $this->entries($cycle)
            ->whereDate('occurred_on', '<=', $month->month->copy()->endOfMonth())
            ->sum('amount_ngwee'));
    }

    /** One member's own position in the fund up to the end of a month. */
    public function memberBalanceAt(Member $member, CycleMonth $month): Money
    {
        return Kwacha::ofNgwee((int) SocialFundTransaction::query()
            ->acrossCycles()
            ->where('member_id', $member->id)
            ->whereDate('occurred_on', '<=', $month->month->copy()->endOfMonth())
            ->sum('amount_ngwee'));
    }

    /** Everything received under one type, as a positive figure. */
    public function totalReceived(Cycle $cycle, SocialFundTransactionType $type): Money
    {
        return Kwacha::ofNgwee((int) $this->entries($cycle)->where('type', $type->value)->sum('amount_ngwee'));
    }

    /**
     * Every entry in the cycle, read regardless of which cycle is pinned.
     *
     * @return Builder<SocialFundTransaction>
     */
    public function entries(Cycle $cycle): Builder
    {
        return SocialFundTransaction::query()->forCycle($cycle);
    }

    /**
     * Money only leaves the fund behind two distinct committee signatures.
     *
     * The sign decides, not the type: a negative Adjustment takes as much out of the
     * fund as a grant does, so it is held to the same rule.
     */
    protected function assertApproved(
        SocialFundTransactionType $type,
        int $ngwee,
        ?Member $actor,
        ?Member $secondApprover,
        ?Member $subject,
    ): void {
        if ($ngwee > 0 && ! $type->requiresSecondApprover()) {
            return;
        }

        if ($actor === null || $secondApprover === null) {
            throw DomainRuleException::make(
                'Money may only leave the social fund with a second committee member confirming it.'
            );
        }

        $this->twoPersonRule->assertDistinctCommittee($actor, $secondApprover, $subject);
    }

    /** The fund is not allowed to lend itself money. */
    protected function assertCovered(Cycle $cycle, int $ngwee): void
    {
        if ($ngwee >= 0) {
            return;
        }

        $balance = Kwacha::toNgwee($this->balance($cycle));

        if ($balance + $ngwee < 0) {
            throw new InsufficientSocialFundException(
                'The social fund holds '.Kwacha::format($balance).', which does not cover '
                .Kwacha::format(abs($ngwee)).'.'
            );
        }
    }
}
