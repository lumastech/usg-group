<?php

namespace App\Domain\Loans;

use App\Domain\SocialFund\SocialFundLedger;
use App\Enums\LoanTransactionType;
use App\Models\CycleMonth;
use App\Models\LoanTransaction;
use App\Models\Member;
use App\Support\Kwacha;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The loan side of a member's position, read off the loan ledger.
 *
 * This is the real implementation of the seam the savings module has been asking
 * through since module 2 — it replaces NoOutstandingLoans, and nothing in the savings
 * domain had to change for it. Every figure is derived from loan_transactions, so a
 * summary rebuild stays idempotent.
 */
class LedgerOutstandingLoans implements OutstandingLoanProvider
{
    public function __construct(protected SocialFundLedger $socialFund) {}

    public function balanceFor(Member $member, CycleMonth $month): Money
    {
        $balance = (int) $this->entriesUpTo($member, $month)
            ->selectRaw("COALESCE(SUM(CASE WHEN loan_transactions.type IN ('repayment', 'write_off') THEN -loan_transactions.amount_ngwee ELSE loan_transactions.amount_ngwee END), 0) AS balance")
            ->value('balance');

        return Kwacha::ofNgwee(max(0, $balance));
    }

    public function balanceOn(Member $member, CarbonInterface $date): Money
    {
        $balance = (int) $this->entriesUpToDate($member, $date)
            ->selectRaw("COALESCE(SUM(CASE WHEN loan_transactions.type IN ('repayment', 'write_off') THEN -loan_transactions.amount_ngwee ELSE loan_transactions.amount_ngwee END), 0) AS balance")
            ->value('balance');

        return Kwacha::ofNgwee(max(0, $balance));
    }

    /**
     * The member's own position in the Social Fund at the end of the month.
     *
     * The fund keeps its own ledger, so this is read from there rather than derived
     * here — the savings summary shows the figure, it does not own it.
     */
    public function socialFundBalanceFor(Member $member, CycleMonth $month): Money
    {
        return $this->socialFund->memberBalanceAt($member, $month);
    }

    public function interestPaidTo(Member $member, CycleMonth $month): Money
    {
        return Kwacha::ofNgwee(
            (int) $this->entriesUpTo($member, $month)->sum('loan_transactions.interest_portion_ngwee')
        );
    }

    public function borrowedToDate(Member $member, CycleMonth $month): Money
    {
        return Kwacha::ofNgwee(
            (int) $this->entriesUpTo($member, $month)
                ->where('loan_transactions.type', LoanTransactionType::Disbursement->value)
                ->sum('loan_transactions.amount_ngwee')
        );
    }

    /**
     * Every ledger entry against this member up to the end of the given month.
     *
     * @return Builder<LoanTransaction>
     */
    protected function entriesUpTo(Member $member, CycleMonth $month): Builder
    {
        return $this->entriesUpToDate($member, $month->month->copy()->endOfMonth());
    }

    /**
     * Every ledger entry against this member up to and including a given day.
     *
     * @return Builder<LoanTransaction>
     */
    protected function entriesUpToDate(Member $member, CarbonInterface $date): Builder
    {
        return LoanTransaction::query()
            ->join('loans', 'loans.id', '=', 'loan_transactions.loan_id')
            ->where('loans.member_id', $member->id)
            ->whereDate('loan_transactions.occurred_on', '<=', $date->toDateString());
    }
}
