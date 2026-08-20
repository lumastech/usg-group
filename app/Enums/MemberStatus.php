<?php

namespace App\Enums;

enum MemberStatus: string
{
    case Active = 'active';
    case LeftEarly = 'left_early';
    case Expelled = 'expelled';
    case Deceased = 'deceased';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }

    /** Members who may transact; everyone else is settled at cycle end only. */
    public function canTransact(): bool
    {
        return $this === self::Active;
    }

    /** Whether the member's payout includes interest earned. */
    public function earnsInterestOnPayout(): bool
    {
        return match ($this) {
            self::Active, self::Deceased => true,
            self::LeftEarly, self::Expelled => false,
        };
    }

    /**
     * The statuses this one may move to.
     *
     * An active member may leave, be expelled or die. A member wrongly recorded as
     * having left or been expelled can be reinstated, which is how a mistaken entry
     * is corrected. Death is final: it is never transitioned out of.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Active => [self::LeftEarly, self::Expelled, self::Deceased],
            self::LeftEarly, self::Expelled => [self::Active],
            self::Deceased => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    /** Whether recording this status requires an expulsion ground. */
    public function requiresExpulsionGround(): bool
    {
        return $this === self::Expelled;
    }

    /** Whether recording this status requires a date of death. */
    public function requiresDateOfDeath(): bool
    {
        return $this === self::Deceased;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::LeftEarly => 'Left early',
            self::Expelled => 'Expelled',
            self::Deceased => 'Deceased',
        };
    }
}
