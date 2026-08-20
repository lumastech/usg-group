<?php

namespace App\Domain\Loans;

use App\Models\CycleMonth;
use App\Models\Member;
use Brick\Money\Money;
use Carbon\CarbonInterface;

/**
 * The loan side of a member's position, as the savings module sees it.
 *
 * Net value is savings plus interest earned minus what the member still owes, so the
 * savings module has to ask about loans — but it must not know how lending works.
 * The lending engine supplies the real implementation in module 3; until then
 * NoOutstandingLoans answers zero to everything and net value is simply what the
 * member holds.
 */
interface OutstandingLoanProvider
{
    /** Ordinary loan principal still owed at the end of the month. */
    public function balanceFor(Member $member, CycleMonth $month): Money;

    /**
     * Ordinary loan principal still owed on a given day.
     *
     * The month-end figure is what the savings summaries want; a closure sometimes
     * needs a date instead, because a deceased member's position is struck on the day
     * they died rather than at the end of the month they died in.
     */
    public function balanceOn(Member $member, CarbonInterface $date): Money;

    /** Social fund loan principal still owed at the end of the month. */
    public function socialFundBalanceFor(Member $member, CycleMonth $month): Money;

    /** Interest the member has paid into the pool across the cycle to date. */
    public function interestPaidTo(Member $member, CycleMonth $month): Money;

    /** Everything the member has borrowed so far, against the cycle's borrowing target. */
    public function borrowedToDate(Member $member, CycleMonth $month): Money;
}
