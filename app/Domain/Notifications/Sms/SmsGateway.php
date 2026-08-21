<?php

namespace App\Domain\Notifications\Sms;

/**
 * The seam a real SMS provider plugs into.
 *
 * Deliberately one method: everything the application knows about sending a text is
 * expressed here, so the day the group signs up with a provider the change is one
 * new class and one line of config. Implementations must not throw for an
 * unreachable number — a member who cannot be texted is not a failed trading day.
 */
interface SmsGateway
{
    public function send(SmsMessage $message): void;
}
