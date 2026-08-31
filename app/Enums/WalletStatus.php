<?php

namespace App\Enums;

/**
 * Whether a wallet may move money.
 *
 * A Closed wallet still reads and still pays out: a member who does not rejoin the
 * next cycle withdraws from the closed cycle's wallet, which is why withdrawal reads
 * `acrossCycles()` on purpose. Frozen is the committee's hold, and stops both sides.
 */
enum WalletStatus: string
{
    case Open = 'open';
    case Frozen = 'frozen';
    case Closed = 'closed';

    /** Whether money may still be put in. */
    public function acceptsCredits(): bool
    {
        return $this === self::Open;
    }

    /**
     * Whether money may still be taken out.
     *
     * A closed wallet may be drained but not filled — that is what closing means.
     */
    public function acceptsDebits(): bool
    {
        return $this !== self::Frozen;
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Frozen => 'Frozen',
            self::Closed => 'Closed',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }
}
