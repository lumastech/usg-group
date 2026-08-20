<?php

namespace App\Domain\Loans;

use App\Enums\LoanTransactionType;
use App\Models\CycleMonth;
use App\Models\LoanTransaction;
use App\Support\Kwacha;
use Brick\Money\Money;

/**
 * The month's interest pool: the interest portion of every repayment received.
 *
 * Only money actually collected counts. Interest that has been charged but not yet paid
 * is owed to the group, not earned by it, and distributing it would hand members a share
 * of income the fund does not hold.
 */
class LedgerInterestIncome implements MonthlyInterestIncome
{
    public function poolFor(CycleMonth $month): Money
    {
        $total = (int) LoanTransaction::query()
            ->where('cycle_month_id', $month->id)
            ->where('type', LoanTransactionType::Repayment->value)
            ->sum('interest_portion_ngwee');

        return Kwacha::ofNgwee($total);
    }
}
