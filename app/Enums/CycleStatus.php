<?php

namespace App\Enums;

/**
 * Where a cycle stands in its year.
 *
 * Closing and ShareOut are deliberately two states, not one. Closing is the
 * pre-flight: lending has stopped and the committee is working the checklist down
 * to zero (every loan settled, every session concluded, the fund reconciled).
 * ShareOut is what that checklist opens — the state in which members are actually
 * paid out and exits are settled. Nothing pays money while a cycle is merely Closing.
 */
enum CycleStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Closing = 'closing';
    case ShareOut = 'share_out';
    case Closed = 'closed';

    /** Whether the cycle has reached the point where members may be paid out. */
    public function isSharingOut(): bool
    {
        return $this === self::ShareOut;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Closing => 'Closing',
            self::ShareOut => 'Share-out',
            self::Closed => 'Closed',
        };
    }
}
