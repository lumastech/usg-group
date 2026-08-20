<?php

namespace App\Domain\Payouts;

use App\Domain\Loans\OutstandingLoanProvider;
use App\Domain\Savings\SavingsLedger;
use App\Enums\PayoutCase;
use App\Exceptions\DomainRuleException;
use App\Models\CycleMonth;
use App\Models\InterestAllocation;
use App\Models\Member;
use App\Support\Kwacha;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * What each member is owed when they leave the cycle, and how it was worked out.
 *
 * One method per case, because the four are genuinely different rules rather than one
 * rule with flags: a member at share-out keeps their interest, a member who left early
 * or was expelled forfeits it, and an estate keeps only what accrued up to the day of
 * death. Nothing here writes — a breakdown is a value, and PayoutExecutor is what
 * turns one into a payout, a debt or a repayment arrangement.
 *
 * What is deducted is the member's outstanding loan, and only that. Their Social Fund
 * position is not netted off: the fund is the group's own money for bereavements and
 * celebrations, and a member's K250 contribution to it was never part of their savings
 * to be handed back. A funeral grant is shown beside a deceased member's closure for
 * the same reason — it belongs to the fund, not inside this sum.
 */
class PayoutCalculator
{
    public function __construct(
        protected SavingsLedger $savings,
        protected OutstandingLoanProvider $loans,
        protected RoundingPolicy $rounding,
    ) {}

    /** The breakdown for whichever case this member's status calls for. */
    public function for(Member $member): PayoutBreakdown
    {
        return $this->using($member, PayoutCase::forStatus($member->status));
    }

    /**
     * The breakdown for a named case, refusing one that does not match the status.
     *
     * The committee never chooses the case — it follows from the member's record — so
     * a mismatch is a bug or a tampered request, and either way it stops here.
     */
    public function using(Member $member, PayoutCase $case): PayoutBreakdown
    {
        $this->assertCaseMatchesStatus($member, $case);

        return match ($case) {
            PayoutCase::ActiveShareOut => $this->activeShareOut($member),
            PayoutCase::LeftEarly => $this->leftEarly($member),
            PayoutCase::Expelled => $this->expelled($member),
            PayoutCase::Deceased => $this->deceased($member),
        };
    }

    /**
     * Case 1 — a member who stayed the course.
     *
     * Savings plus every ngwee of interest their savings earned, less whatever they
     * still owe, then the round-off adjustment.
     */
    public function activeShareOut(Member $member): PayoutBreakdown
    {
        $this->assertCaseMatchesStatus($member, PayoutCase::ActiveShareOut);

        $savings = $this->savingsNgwee($member);
        $interest = $this->interestNgwee($member);
        $loan = $this->loanNgwee($member);

        return $this->build(PayoutCase::ActiveShareOut, $member, [
            PayoutLine::credit(
                'Total savings',
                'Every contribution recorded across the cycle',
                $savings,
            ),
            PayoutLine::credit(
                'Interest earned',
                'This member\'s share of the group\'s lending income, month by month',
                $interest,
            ),
            PayoutLine::subtotal(
                'Member value',
                'Total savings + interest earned',
                $savings + $interest,
            ),
            PayoutLine::debit(
                'Outstanding loan',
                'Principal, interest and penalties still owed on the loan ledger',
                $loan,
            ),
        ], $savings + $interest - $loan);
    }

    /**
     * Case 2 — a member who left before the cycle ended.
     *
     * They are paid their savings and nothing else. The interest their savings earned
     * stays with the group, but it is still shown, because "what happened to my
     * interest?" is the first question they ask. Their loan is still deducted.
     */
    public function leftEarly(Member $member): PayoutBreakdown
    {
        $this->assertCaseMatchesStatus($member, PayoutCase::LeftEarly);

        return $this->withoutInterest($member, PayoutCase::LeftEarly, 'left the group before the cycle ended');
    }

    /** Case 3 — an expelled member. Savings only, loan still deducted. */
    public function expelled(Member $member): PayoutBreakdown
    {
        $this->assertCaseMatchesStatus($member, PayoutCase::Expelled);

        return $this->withoutInterest($member, PayoutCase::Expelled, 'was expelled from the group');
    }

    /**
     * Case 4 — a member who died.
     *
     * The estate keeps the interest, but only what had accrued by the day of death:
     * interest is credited at the end of each trading month, so the months that closed
     * on or before that day count and the month they died in does not. The loan is
     * struck on the same day.
     */
    public function deceased(Member $member): PayoutBreakdown
    {
        $this->assertCaseMatchesStatus($member, PayoutCase::Deceased);

        $cutoff = $member->date_of_death;

        if ($cutoff === null) {
            throw DomainRuleException::make(
                "{$member->full_name} is recorded as deceased without a date of death, so there is no day to strike the interest on."
            );
        }

        $savings = $this->savingsNgwee($member);
        $interest = $this->interestNgwee($member, $cutoff);
        $loan = Kwacha::toNgwee($this->loans->balanceOn($member, $cutoff));

        return $this->build(PayoutCase::Deceased, $member, [
            PayoutLine::credit(
                'Total savings',
                'Every contribution recorded across the cycle',
                $savings,
            ),
            PayoutLine::credit(
                'Interest earned to '.$cutoff->format('j F Y'),
                'Only months that closed on or before the date of death earn interest',
                $interest,
            ),
            PayoutLine::subtotal(
                'Member value',
                'Total savings + interest to date of death',
                $savings + $interest,
            ),
            PayoutLine::debit(
                'Outstanding loan at '.$cutoff->format('j F Y'),
                'What was owed on the loan ledger on the date of death',
                $loan,
            ),
        ], $savings + $interest - $loan, $cutoff);
    }

    /** The two forfeiting cases differ only in the wording of the forfeit line. */
    protected function withoutInterest(Member $member, PayoutCase $case, string $because): PayoutBreakdown
    {
        $savings = $this->savingsNgwee($member);
        $forfeited = $this->interestNgwee($member);
        $loan = $this->loanNgwee($member);

        return $this->build($case, $member, [
            PayoutLine::credit(
                'Total savings',
                'Every contribution recorded across the cycle',
                $savings,
            ),
            PayoutLine::note(
                'Interest forfeited',
                Kwacha::format($forfeited).' earned but not payable: this member '.$because,
                $forfeited,
            ),
            PayoutLine::subtotal(
                'Member value',
                'Total savings only — no interest is paid on this closure',
                $savings,
            ),
            PayoutLine::debit(
                'Outstanding loan',
                'Principal, interest and penalties still owed on the loan ledger',
                $loan,
            ),
        ], $savings - $loan);
    }

    /**
     * Closes a breakdown with its net value, round-off adjustment and net payable.
     *
     * @param  array<int, PayoutLine>  $lines
     */
    protected function build(
        PayoutCase $case,
        Member $member,
        array $lines,
        int $netValueNgwee,
        ?CarbonInterface $cutoff = null,
    ): PayoutBreakdown {
        $adjustment = $this->rounding->adjustmentFor($netValueNgwee);

        $lines[] = PayoutLine::total('Net value', 'Member value − outstanding loan', $netValueNgwee);
        $lines[] = PayoutLine::adjustment('Round-off adjustment', $this->rounding->describe(), $adjustment);
        $lines[] = PayoutLine::total('Net payable', 'Net value + round-off adjustment', $netValueNgwee + $adjustment);

        return PayoutBreakdown::make($case, $member, $lines, $netValueNgwee, $adjustment, $cutoff);
    }

    /** @throws DomainRuleException */
    public function assertCaseMatchesStatus(Member $member, PayoutCase $case): void
    {
        if (! $case->matches($member->status)) {
            throw DomainRuleException::make(
                "{$member->full_name} is {$member->status->label()}, so their closure is settled as "
                .PayoutCase::forStatus($member->status)->label().', not as '.$case->label().'.'
            );
        }
    }

    /** Everything the member contributed across the cycle. */
    protected function savingsNgwee(Member $member): int
    {
        $lastMonth = $this->lastMonth($member);

        return $lastMonth === null
            ? 0
            : Kwacha::toNgwee($this->savings->cumulativeSavings($member, $lastMonth));
    }

    /**
     * The interest credited to this member, optionally stopping at a date.
     *
     * A month's interest is credited when the month closes, so a cutoff includes every
     * month whose last day falls on or before it and excludes the one still running.
     */
    protected function interestNgwee(Member $member, ?CarbonInterface $upTo = null): int
    {
        $query = InterestAllocation::query()->where('member_id', $member->id);

        if ($upTo !== null) {
            $query->whereIn('cycle_month_id', $this->monthIdsClosedBy($member, $upTo));
        }

        return (int) $query->sum('amount_ngwee');
    }

    /** What the member still owes today, across every loan they took. */
    protected function loanNgwee(Member $member): int
    {
        return Kwacha::toNgwee($this->loans->balanceOn($member, Carbon::today()));
    }

    /**
     * The cycle months that had closed by a given day.
     *
     * @return array<int, int>
     */
    protected function monthIdsClosedBy(Member $member, CarbonInterface $date): array
    {
        return CycleMonth::query()
            ->acrossCycles()
            ->where('cycle_id', $member->cycle_id)
            ->get()
            ->filter(fn (CycleMonth $month): bool => $month->month->copy()->endOfMonth()->lessThanOrEqualTo($date))
            ->pluck('id')
            ->all();
    }

    protected function lastMonth(Member $member): ?CycleMonth
    {
        return CycleMonth::query()
            ->acrossCycles()
            ->where('cycle_id', $member->cycle_id)
            ->orderByDesc('sequence')
            ->first();
    }
}
