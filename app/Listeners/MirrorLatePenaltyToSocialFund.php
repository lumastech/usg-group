<?php

namespace App\Listeners;

use App\Events\LatePenaltyCharged;

/**
 * Placeholder for the Social Fund's mirror of the daily late penalty.
 *
 * Module 4 owns the fund's ledger. Registering the listener now means the event has a
 * real subscriber from the start, so wiring the fund up is a change in one file rather
 * than a hunt for every place a penalty is charged.
 */
class MirrorLatePenaltyToSocialFund
{
    public function handle(LatePenaltyCharged $event): void
    {
        // Implemented in module 4, when the Social Fund ledger exists.
    }
}
