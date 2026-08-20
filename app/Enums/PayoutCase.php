<?php

namespace App\Enums;

/**
 * How a member's position is settled when they leave the cycle.
 *
 * There is one case per way of going. Which one applies is never chosen by the
 * committee — it follows from the member's status, and PayoutCalculator refuses a
 * case that does not match. The differences between them are only two: whether the
 * member keeps the interest their savings earned, and where the interest stops.
 */
enum PayoutCase: string
{
    case ActiveShareOut = 'active_share_out';
    case LeftEarly = 'left_early';
    case Expelled = 'expelled';
    case Deceased = 'deceased';

    /** The case that settles a member in this status. */
    public static function forStatus(MemberStatus $status): self
    {
        return match ($status) {
            MemberStatus::Active => self::ActiveShareOut,
            MemberStatus::LeftEarly => self::LeftEarly,
            MemberStatus::Expelled => self::Expelled,
            MemberStatus::Deceased => self::Deceased,
        };
    }

    public function matches(MemberStatus $status): bool
    {
        return self::forStatus($status) === $this;
    }

    /** The status this case settles. */
    public function status(): MemberStatus
    {
        return match ($this) {
            self::ActiveShareOut => MemberStatus::Active,
            self::LeftEarly => MemberStatus::LeftEarly,
            self::Expelled => MemberStatus::Expelled,
            self::Deceased => MemberStatus::Deceased,
        };
    }

    /**
     * Whether the payout carries the interest the member's savings earned.
     *
     * Leaving early and being expelled both forfeit it; dying does not. This is the
     * same rule MemberStatus::earnsInterestOnPayout() states, read from the case.
     */
    public function includesInterest(): bool
    {
        return $this->status()->earnsInterestOnPayout();
    }

    /**
     * Whether interest stops on a date of the member's own rather than at cycle end.
     *
     * Only death does: the estate earns up to the day, not to November.
     */
    public function hasInterestCutoff(): bool
    {
        return $this === self::Deceased;
    }

    /**
     * Whether the committee may settle this case before the cycle reaches share-out.
     *
     * Only a death. A bereaved family should not be made to wait for November, so the
     * committee may settle compassionately and early, with two signatures and a note
     * saying why. Nobody else jumps the queue.
     */
    public function allowsEarlySettlement(): bool
    {
        return $this === self::Deceased;
    }

    /** Where a negative net is recorded, since it is never paid as a negative payout. */
    public function negativeNetOutcome(): string
    {
        return $this === self::Deceased ? 'next_of_kin_arrangement' : 'member_debt';
    }

    public function label(): string
    {
        return match ($this) {
            self::ActiveShareOut => 'Share-out',
            self::LeftEarly => 'Left early',
            self::Expelled => 'Expelled',
            self::Deceased => 'Deceased',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
