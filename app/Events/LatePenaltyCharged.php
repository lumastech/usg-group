<?php

namespace App\Events;

use App\Models\Loan;
use App\Models\LoanTransaction;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A member paid an installment after the trading date and was charged K100 a day.
 *
 * The Social Fund mirrors the same penalty in module 4, which is why this is an event
 * rather than a direct call — the lending engine must not know the fund exists.
 */
class LatePenaltyCharged
{
    use Dispatchable;

    public function __construct(
        public readonly Loan $loan,
        public readonly LoanTransaction $transaction,
        public readonly int $daysLate,
    ) {}
}
