<?php

namespace App\Domain\Loans;

use App\Models\CycleMonth;
use Brick\Money\Money;

/**
 * The interest the group earned from lending in one month — the pool that is then
 * shared out among the members.
 *
 * The lending engine supplies the real figure in module 3 by summing the interest
 * portion of every repayment received. Until then NoInterestIncome answers zero, and
 * a pool can still be passed explicitly to distribute a known amount.
 */
interface MonthlyInterestIncome
{
    public function poolFor(CycleMonth $month): Money;
}
