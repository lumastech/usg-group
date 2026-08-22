<?php

namespace App\Listeners;

use App\Events\PayoutDestinationChanged;
use App\Notifications\PayoutDestinationChangedNotice;

/**
 * The out-of-band half of the destination controls.
 *
 * Deliberately not conditional on who made the change: the message a member expects is
 * cheap, and it is the only thing that makes the message they do not expect arrive.
 */
class NotifyMemberOfPayoutDestinationChange
{
    public function handle(PayoutDestinationChanged $event): void
    {
        $member = $event->destination->member;

        $member?->notify(new PayoutDestinationChangedNotice(
            $event->destination,
            $event->actor,
            $event->change,
        ));
    }
}
