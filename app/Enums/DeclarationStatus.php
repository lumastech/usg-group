<?php

namespace App\Enums;

/**
 * Where a member's monthly declaration sits.
 *
 * Submitted while the window is open and the member may still change their mind;
 * Locked once the window closes and the figures become the trading session's
 * expectations; Processed once that session has been concluded and the money posted.
 */
enum DeclarationStatus: string
{
    case Submitted = 'submitted';
    case Locked = 'locked';
    case Processed = 'processed';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }

    /** Only a Submitted declaration may still be changed by the member. */
    public function isEditable(): bool
    {
        return $this === self::Submitted;
    }

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::Locked => 'Locked',
            self::Processed => 'Processed',
        };
    }
}
