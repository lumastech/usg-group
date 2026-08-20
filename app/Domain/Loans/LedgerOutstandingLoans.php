<?php

namespace App\Domain\Loans;

use App\Enums\LoanTransactionType;
use App\Models\CycleMonth;
use App\Models\LoanTransaction;
use App\Models\Member;
use App\Support\Kwacha;
use Brick\Money\Money;
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
    public function balanceFor(Member $member, CycleMonth $month): Money
    {
        $balance = (int) $this->entriesUpTo($member, $month)
            ->selectRaw("COALESCE(SUM(CASE WHEN loan_transactions.type IN ('repayment', 'write_off') THEN -loan_transactions.amount_ngwee ELSE loan_transactions.amount_ngwee END), 0) AS balance")
            ->value('balance');

        return Kwacha::ofNgwee(max(0, $balance));
    }

    /** The Social Fund keeps its own ledger; module 4 supplies this figure. */
    public function socialFundBalanceFor(Member $member, CycleMonth $month): Money
    {
        return Kwacha::zero();
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
        return LoanTransaction::query()
            ->join('loans', 'loans.id', '=', 'loan_transactions.loan_id')
            ->where('loans.member_id', $member->id)
            ->whereDate('loan_transactions.occurred_on', '<=', $month->month->copy()->endOfMonth());
    }
}
