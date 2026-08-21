<?php

namespace App\Domain\Notifications;

use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Notifications\PenaltyApplied;

/**
 * Tells a member, immediately, that they have been charged a penalty.
 *
 * Both penalties in the constitution arrive here from their own event, so the copy
 * and the arithmetic are written once. A member whose ledgers are frozen — their
 * payout has been executed — is not notified: nothing can be posted against them
 * any more, so a penalty notice would describe a position they no longer hold.
 */
class PenaltyNotifier
{
    public function notify(Loan $loan, LoanTransaction $transaction, int $daysLate = 0): void
    {
        $member = $loan->member;

        if ($member === null || $member->ledgersFrozen()) {
            return;
        }

        $member->notify(new PenaltyApplied($loan, $transaction, $daysLate));
    }
}
