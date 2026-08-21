<?php

namespace App\Enums;

/**
 * How a member wants to hear from the group.
 *
 * Most of the group reaches the portal by phone and several members have no email
 * address at all, so the preference is a member-level setting rather than a user
 * one. NotificationChannelManager turns it into the channel list a notification's
 * via() returns, dropping any channel the member has no address for.
 */
enum NotificationChannel: string
{
    case Mail = 'mail';
    case Sms = 'sms';
    case Both = 'both';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $channel): string => $channel->value, self::cases());
    }

    /**
     * The Laravel notification channels this preference asks for.
     *
     * @return array<int, string>
     */
    public function channels(): array
    {
        return match ($this) {
            self::Mail => ['mail'],
            self::Sms => ['sms'],
            self::Both => ['mail', 'sms'],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Mail => 'Email only',
            self::Sms => 'SMS only',
            self::Both => 'Email and SMS',
        };
    }
}
