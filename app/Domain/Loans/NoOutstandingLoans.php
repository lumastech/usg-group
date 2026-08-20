<?php

namespace App\Domain\Loans;

use App\Models\CycleMonth;
use App\Models\Member;
use App\Support\Kwacha;
use Brick\Money\Money;
use Carbon\CarbonInterface;

/**
 * The stand-in used until the lending engine exists.
 *
 * Nobody can owe anything before loans can be issued, so every answer is zero and
 * net value reduces to savings plus interest.
 */
class NoOutstandingLoans implements OutstandingLoanProvider
{
    public function balanceFor(Member $member, CycleMonth $month): Money
    {
        return Kwacha::zero();
    }

    public function balanceOn(Member $member, CarbonInterface $date): Money
    {
        return Kwacha::zero();
    }

    public function socialFundBalanceFor(Member $member, CycleMonth $month): Money
    {
        return Kwacha::zero();
    }

    public function interestPaidTo(Member $member, CycleMonth $month): Money
    {
        return Kwacha::zero();
    }

    public function borrowedToDate(Member $member, CycleMonth $month): Money
    {
        return Kwacha::zero();
    }
}
