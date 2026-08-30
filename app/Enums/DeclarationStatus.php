<?php

namespace App\Enums;

/**
 * Where a member's monthly declaration sits.
 *
 * Submitted is a request: the member may still change their mind, and no money may be
 * asked for yet. Approved is the committee's "ask" — the figures are accepted, the
 * member can no longer edit them, and either side may now start the payment. Locked
 * once the window closes and the figures become the trading session's expectations;
 * Processed once that session has been concluded and the money posted.
 *
 * Approval is stamped on the row (`approved_at`) as well as reflected here, because a
 * declaration approved on the trading day is already Locked and its status cannot say
 * so. `Declaration::isApproved()` is the gate payments ask; this enum is the lifecycle.
 */
enum DeclarationStatus: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
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

    /** A declaration the committee has not yet asked for. */
    public function isAwaitingApproval(): bool
    {
        return $this === self::Submitted;
    }

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Awaiting approval',
            self::Approved => 'Pending payment',
            self::Locked => 'Locked',
            self::Processed => 'Processed',
        };
    }
}
