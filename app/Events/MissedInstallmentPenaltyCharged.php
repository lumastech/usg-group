<?php

namespace App\Events;

use App\Models\CycleMonth;
use App\Models\Loan;
use App\Models\LoanTransaction;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A month closed with an installment missed or short, and 10% was charged.
 *
 * Deliberately a different event from LatePenaltyCharged. That one is mirrored into
 * the Social Fund; this one is a charge on the loan and stays with the lending pool,
 * and `unity:reconcile-social-fund` compares the two sides on exactly that basis. A
 * listener that treated them as the same thing would break the reconciliation, so
 * they are not the same event.
 */
class MissedInstallmentPenaltyCharged
{
    use Dispatchable;

    public function __construct(
        public readonly Loan $loan,
        public readonly LoanTransaction $transaction,
        public readonly CycleMonth $month,
    ) {}
}
