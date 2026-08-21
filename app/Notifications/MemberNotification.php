<?php

namespace App\Notifications;

use App\Domain\Notifications\NotificationChannelManager;
use App\Domain\Notifications\Sms\SmsMessage;
use App\Models\Member;
use App\Support\Kwacha;
use Brick\Money\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The shape every notification in this application shares.
 *
 * One thing is centralised here and it is the important one: `via()` asks
 * NotificationChannelManager rather than hard-coding a channel list, so a member's
 * mail/SMS preference is honoured by every notification without each of them
 * having to remember to look. Subclasses supply the copy and nothing else.
 *
 * The SMS body is deliberately written separately from the email rather than
 * derived from it — a text is 160 characters, so it has to say the one thing that
 * matters, not a squeezed version of a five-line email.
 */
abstract class MemberNotification extends Notification
{
    use Queueable;

    /** @return array<int, string> */
    public function via(Member $notifiable): array
    {
        return app(NotificationChannelManager::class)->for($notifiable);
    }

    /** The text-message form of this notification. */
    abstract public function toSms(Member $notifiable): SmsMessage;

    protected function money(Money|int $amount): string
    {
        return Kwacha::format($amount);
    }
}
