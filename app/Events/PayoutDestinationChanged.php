<?php

namespace App\Events;

use App\Models\Member;
use App\Models\PayoutDestination;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Where a member's money goes has been added to, made default, or removed.
 *
 * An event rather than a direct call because the telling is a security control, not a
 * courtesy: the member has to hear about it on the contacts they had before the change,
 * so somebody whose account has been taken over finds out. The domain service should
 * not have to know how the group reaches people.
 */
class PayoutDestinationChanged
{
    use Dispatchable;

    public function __construct(
        public readonly PayoutDestination $destination,
        public readonly Member $actor,
        /** One of: added, default, removed. */
        public readonly string $change,
    ) {}
}
