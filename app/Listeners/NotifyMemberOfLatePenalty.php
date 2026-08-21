<?php

namespace App\Listeners;

use App\Domain\Notifications\PenaltyNotifier;
use App\Events\LatePenaltyCharged;

/**
 * The member's copy of the daily late-transfer penalty.
 *
 * Sits alongside MirrorLatePenaltyToSocialFund on the same event: the fund takes the
 * money, the member is told why. Neither knows about the other.
 */
class NotifyMemberOfLatePenalty
{
    public function __construct(protected PenaltyNotifier $notifier) {}

    public function handle(LatePenaltyCharged $event): void
    {
        $this->notifier->notify($event->loan, $event->transaction, $event->daysLate);
    }
}
