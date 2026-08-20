<?php

namespace App\Enums;

/**
 * What a motion is asking the group to decide.
 *
 * The type fixes which base the 60% is taken against — see
 * App\Enums\ThresholdBasis and App\Domain\Governance\VotingThreshold.
 */
enum MotionType: string
{
    case NoConfidence = 'no_confidence';
    case Amendment = 'amendment';
    case General = 'general';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::NoConfidence => 'No confidence',
            self::Amendment => 'Amendment',
            self::General => 'General',
        };
    }

    /**
     * The base this motion's 60% is measured against.
     *
     * Removing an officer needs 60% of the WHOLE membership, so staying away cannot
     * remove somebody; amending the constitution needs 60% of those PRESENT, so the
     * group can still amend without a full house. The difference is deliberate.
     */
    public function thresholdBasis(): ThresholdBasis
    {
        return match ($this) {
            self::NoConfidence => ThresholdBasis::TotalMembers,
            self::Amendment, self::General => ThresholdBasis::MembersPresent,
        };
    }
}
