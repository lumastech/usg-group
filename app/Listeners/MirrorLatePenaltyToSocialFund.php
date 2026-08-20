<?php

namespace App\Listeners;

use App\Domain\SocialFund\LatePenaltyMirror;
use App\Events\LatePenaltyCharged;

/**
 * The Social Fund's mirror of the daily late-transfer penalty.
 *
 * The lending engine raises LatePenaltyCharged and knows nothing more; this is the one
 * place that turns it into money in the fund. The mirror is keyed on the loan entry, so
 * a replayed event posts nothing twice.
 */
class MirrorLatePenaltyToSocialFund
{
    public function __construct(protected LatePenaltyMirror $mirror) {}

    public function handle(LatePenaltyCharged $event): void
    {
        $this->mirror->mirror($event->transaction);
    }
}
