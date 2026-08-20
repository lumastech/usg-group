<?php

namespace App\Domain\Governance;

use App\Enums\MotionType;
use App\Exceptions\AmendmentWindowClosedException;
use App\Models\Cycle;
use App\Models\Motion;
use Carbon\CarbonInterface;

/**
 * The six months the constitution must be left alone between changes.
 *
 * The clock starts at the last amendment the group actually carried; where none has,
 * it starts at the cycle. Only a passed amendment moves it — proposing one that fails
 * does not buy another six months of silence, and does not cost the group six either.
 */
class AmendmentWindow
{
    /** The rest the constitution is entitled to between changes. */
    public const SPACING_MONTHS = 6;

    /** The last amendment the group carried, if any. */
    public function lastPassed(Cycle $cycle): ?Motion
    {
        return Motion::query()
            ->forCycle($cycle->id)
            ->where('type', MotionType::Amendment)
            ->where('passed', true)
            ->whereNotNull('decided_at')
            ->orderByDesc('decided_at')
            ->first();
    }

    /** The day the constitution may next be amended. */
    public function opensOn(Cycle $cycle): CarbonInterface
    {
        $last = $this->lastPassed($cycle);

        $from = $last?->decided_at?->copy()->startOfDay() ?? $cycle->starts_on->copy()->startOfDay();

        return $from->addMonthsNoOverflow(self::SPACING_MONTHS);
    }

    public function isOpen(Cycle $cycle, ?CarbonInterface $at = null): bool
    {
        return ($at ?? now())->startOfDay()->gte($this->opensOn($cycle));
    }

    /** Whole days still to wait; zero once the window is open. */
    public function daysUntilOpen(Cycle $cycle, ?CarbonInterface $at = null): int
    {
        $at = ($at ?? now())->copy()->startOfDay();

        return max(0, (int) $at->diffInDays($this->opensOn($cycle), absolute: false));
    }

    public function assertOpen(Cycle $cycle, ?CarbonInterface $at = null): void
    {
        if ($this->isOpen($cycle, $at)) {
            return;
        }

        throw new AmendmentWindowClosedException(sprintf(
            'The constitution was last amended less than six months ago. It may next be amended on %s, in %d days.',
            $this->opensOn($cycle)->format('j M Y'),
            $this->daysUntilOpen($cycle, $at),
        ));
    }

    /**
     * The countdown the proposal form reads from.
     *
     * @return array{
     *     is_open: bool,
     *     opens_on: string,
     *     days_until_open: int,
     *     last_amended_on: string|null,
     *     last_amended_section: string|null,
     * }
     */
    public function payload(Cycle $cycle, ?CarbonInterface $at = null): array
    {
        $last = $this->lastPassed($cycle);

        return [
            'is_open' => $this->isOpen($cycle, $at),
            'opens_on' => $this->opensOn($cycle)->toDateString(),
            'days_until_open' => $this->daysUntilOpen($cycle, $at),
            'last_amended_on' => $last?->decided_at?->toDateString(),
            'last_amended_section' => $last?->amendment?->section_reference,
        ];
    }
}
