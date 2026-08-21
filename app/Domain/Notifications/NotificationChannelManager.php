<?php

namespace App\Domain\Notifications;

use App\Enums\NotificationChannel;
use App\Models\Member;

/**
 * Decides which channels a given notifiable actually gets a notification on.
 *
 * Two things are folded together here, and both matter. The member's stated
 * preference says what they want; the addresses on their record say what is
 * possible. A member who asks for SMS but has no phone number still gets the email
 * rather than nothing at all, and a member with neither address is simply not
 * notified — which the scheduled run reports as a skip rather than swallowing.
 */
class NotificationChannelManager
{
    /**
     * The channels to deliver on, in preference order.
     *
     * @return array<int, string>
     */
    public function for(Member $notifiable): array
    {
        $wanted = $this->preferenceFor($notifiable)->channels();

        $available = array_values(array_filter(
            $wanted,
            fn (string $channel): bool => filled($notifiable->routeNotificationFor($channel)),
        ));

        /*
         * Preference is a wish, not a filter. Falling back to any channel we do hold
         * an address for is the difference between a member missing one reminder and
         * a member missing the whole month.
         */
        return $available !== [] ? $available : $this->fallbackFor($notifiable);
    }

    public function preferenceFor(Member $notifiable): NotificationChannel
    {
        return $notifiable->notification_channel ?? NotificationChannel::Mail;
    }

    /**
     * @return array<int, string>
     */
    protected function fallbackFor(Member $notifiable): array
    {
        return array_values(array_filter(
            ['mail', 'sms'],
            fn (string $channel): bool => filled($notifiable->routeNotificationFor($channel)),
        ));
    }
}
