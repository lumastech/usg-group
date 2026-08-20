<?php

namespace App\Domain\Loans;

use App\Models\CycleMonth;
use App\Support\Kwacha;
use Brick\Money\Money;

/** No lending, so no interest income. Replaced by the lending engine in module 3. */
class NoInterestIncome implements MonthlyInterestIncome
{
    public function poolFor(CycleMonth $month): Money
    {
        return Kwacha::zero();
    }
}
