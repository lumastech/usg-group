<?php

namespace App\Listeners;

use App\Domain\Notifications\PenaltyNotifier;
use App\Events\MissedInstallmentPenaltyCharged;

/**
 * The member's copy of the 10% missed-installment penalty.
 *
 * This penalty is NOT mirrored into the Social Fund — see .ai/rules/listeners.md —
 * so this listener is the only thing that reacts to it.
 */
class NotifyMemberOfMissedInstallmentPenalty
{
    public function __construct(protected PenaltyNotifier $notifier) {}

    public function handle(MissedInstallmentPenaltyCharged $event): void
    {
        $this->notifier->notify($event->loan, $event->transaction);
    }
}
