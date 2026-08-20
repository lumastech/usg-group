<?php

namespace App\Domain\Savings;

use App\Domain\Loans\OutstandingLoanProvider;
use App\Models\CycleMonth;
use App\Models\InterestAllocation;
use App\Models\Member;
use App\Models\MemberMonthBalance;
use App\Models\SavingsTransaction;
use App\Support\Kwacha;
use Illuminate\Support\Collection;

/**
 * Rebuilds the per-member month-end snapshots.
 *
 * Every column is derived, so a rebuild is idempotent: running it twice over the same
 * ledgers produces the same rows. The loan figures come from the OutstandingLoanProvider
 * rather than from the row being rebuilt, which is what keeps that true — reading them
 * back off the snapshot would let a stale value survive a rebuild forever.
 */
class MemberBalanceCalculator
{
    public function __construct(
        protected SavingsLedger $ledger,
        protected OutstandingLoanProvider $loans,
    ) {}

    /**
     * @return Collection<int, MemberMonthBalance>
     */
    public function rebuildMonth(CycleMonth $month): Collection
    {
        return $month->cycle->members()->get()
            ->map(fn (Member $member): MemberMonthBalance => $this->rebuildFor($member, $month));
    }

    public function rebuildFor(Member $member, CycleMonth $month): MemberMonthBalance
    {
        $monthIds = $this->ledger->monthIdsUpTo($month);

        $savings = (int) SavingsTransaction::query()
            ->where('member_id', $member->id)
            ->where('cycle_month_id', $month->id)
            ->sum('amount_ngwee');

        $cumulativeSavings = Kwacha::toNgwee($this->ledger->cumulativeSavings($member, $month));

        $interest = (int) InterestAllocation::query()
            ->where('member_id', $member->id)
            ->where('cycle_month_id', $month->id)
            ->sum('amount_ngwee');

        $cumulativeInterest = (int) InterestAllocation::query()
            ->where('member_id', $member->id)
            ->whereIn('cycle_month_id', $monthIds)
            ->sum('amount_ngwee');

        $existing = MemberMonthBalance::firstOrNew([
            'member_id' => $member->id,
            'cycle_month_id' => $month->id,
        ]);

        $loanBalance = Kwacha::toNgwee($this->loans->balanceFor($member, $month));
        $socialLoanBalance = Kwacha::toNgwee($this->loans->socialFundBalanceFor($member, $month));
        $interestPaid = Kwacha::toNgwee($this->loans->interestPaidTo($member, $month));
        $borrowedToDate = Kwacha::toNgwee($this->loans->borrowedToDate($member, $month));

        $memberValue = $cumulativeSavings + $cumulativeInterest;
        $twoTimesSavings = $cumulativeSavings * $month->cycle->max_loan_multiple;

        $existing->fill([
            'savings_ngwee' => $savings,
            'cumulative_savings_ngwee' => $cumulativeSavings,
            'interest_earned_ngwee' => $interest,
            'cumulative_interest_earned_ngwee' => $cumulativeInterest,
            'cumulative_interest_paid_ngwee' => $interestPaid,
            'loan_balance_ngwee' => $loanBalance,
            'social_loan_balance_ngwee' => $socialLoanBalance,
            'member_value_ngwee' => $memberValue,
            'net_value_ngwee' => $memberValue - $loanBalance - $socialLoanBalance,
            'two_times_savings_ngwee' => $twoTimesSavings,
            'eligible_to_borrow_ngwee' => $twoTimesSavings - $loanBalance,
            'borrowed_to_date_ngwee' => $borrowedToDate,
            'borrowing_target_balance_ngwee' => max(
                0,
                Kwacha::toNgwee($month->cycle->borrowing_target_ngwee) - $borrowedToDate,
            ),
        ])->save();

        return $existing;
    }
}
