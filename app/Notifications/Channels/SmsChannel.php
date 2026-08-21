<?php

namespace App\Notifications\Channels;

use App\Domain\Notifications\Sms\SmsGateway;
use App\Domain\Notifications\Sms\SmsMessage;
use Illuminate\Notifications\Notification;

/**
 * Delivers a notification's toSms() through whichever gateway is bound.
 *
 * Registered as the "sms" channel in AppServiceProvider, so a notification opts in
 * simply by listing 'sms' in via() and defining toSms(). A notifiable with no phone
 * number on record is skipped silently: the member still gets the email, and the
 * treasurer chasing a missing number is a register problem, not a delivery failure.
 */
class SmsChannel
{
    public function __construct(protected SmsGateway $gateway) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSms') || ! method_exists($notifiable, 'routeNotificationFor')) {
            return;
        }

        $number = $notifiable->routeNotificationFor('sms', $notification);

        if (blank($number)) {
            return;
        }

        $message = $notification->toSms($notifiable);

        if (is_string($message)) {
            $message = SmsMessage::make($message);
        }

        if (! $message instanceof SmsMessage) {
            return;
        }

        $message = $message
            ->to($message->to ?? (string) $number)
            ->from($message->from ?? (string) config('notifications.sms.from'))
            ->truncated((int) config('notifications.sms.max_length', 320));

        $this->gateway->send($message);
    }
}
