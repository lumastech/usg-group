<?php

namespace App\Events;

use App\Models\Member;
use App\Models\TradingSession;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The month has been posted: savings, repayments, interest and penalties are final.
 *
 * Raised after the concluding transaction has committed, never inside it. The
 * statement pack listener renders thirty PDFs off the back of this, and none of
 * that work belongs in the transaction that is holding the month's ledger rows.
 */
class TradingSessionConcluded
{
    use Dispatchable;

    public function __construct(
        public readonly TradingSession $session,
        public readonly Member $actor,
    ) {}
}
