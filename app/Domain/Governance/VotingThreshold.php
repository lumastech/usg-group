<?php

namespace App\Domain\Governance;

/**
 * The constitution's 60%, and the one decision it leaves open: how to round.
 *
 * The group settled on the ceiling. Sixty percent of 29 members is 17.4, and 17 votes
 * would be 58.6% — under the bar the constitution actually sets — so the requirement
 * rounds up to 18. Where the arithmetic comes out whole, that whole number is enough:
 * 18 of 30 is exactly 60% and carries. So a motion needs `votes_for >= ceil(0.6 x base)`
 * and never passes on less than sixty percent.
 *
 * The same shape answers quorum, which is the same 60% asked of attendance.
 */
class VotingThreshold
{
    /** Sixty percent, in basis points, so the arithmetic stays in integers. */
    public const BPS = 6_000;

    private const SCALE = 10_000;

    /**
     * How many votes (or heads) a base of this size requires.
     *
     * Integer ceiling: no floats touch this, because a 0.6 that lands at 17.399999
     * would quietly drop the requirement by one vote.
     */
    public function needed(int $base): int
    {
        if ($base <= 0) {
            return 0;
        }

        return intdiv($base * self::BPS + self::SCALE - 1, self::SCALE);
    }

    /** Whether a tally clears the bar for a base of this size. */
    public function isMet(int $count, int $base): bool
    {
        return $base > 0 && $count >= $this->needed($base);
    }

    /** The threshold read back in words, e.g. "18 of 30 total active members". */
    public function explain(int $base, string $noun): string
    {
        return sprintf('%d of %d %s', $this->needed($base), $base, $noun);
    }
}
