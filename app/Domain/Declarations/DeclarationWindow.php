<?php

namespace App\Domain\Declarations;

use App\Models\CycleMonth;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Where in the month we are, and what that means for the declaration form.
 *
 * The group's month has a fixed shape: declarations from 08:00 on the 1st to the end
 * of the 3rd, trading from the 4th to the adjusted 7th, and nothing in between or
 * after. Every banner, countdown and guard in the portal reads that shape from here,
 * so a single month has exactly one answer no matter which screen is asking.
 */
class DeclarationWindow
{
    public const BEFORE = 'before_declarations';

    public const DECLARATIONS = 'declarations';

    public const BETWEEN = 'between';

    public const TRADING = 'trading';

    public const CLOSED = 'closed';

    /** Between 08:00 on the 1st and the last second of the 3rd, inclusive. */
    public function isOpen(CycleMonth $month, ?CarbonInterface $at = null): bool
    {
        return $month->declarationsOpenAt($this->at($at));
    }

    /** Past the close of the window: a declaration captured now is a late one. */
    public function isLate(CycleMonth $month, ?CarbonInterface $at = null): bool
    {
        return $month->isLate($this->at($at));
    }

    /** Before the window has even opened, nothing may be captured by anybody. */
    public function isBeforeOpen(CycleMonth $month, ?CarbonInterface $at = null): bool
    {
        return $this->at($at)->lessThan($month->declarations_open_at);
    }

    public function isTrading(CycleMonth $month, ?CarbonInterface $at = null): bool
    {
        $now = $this->at($at);

        return $now->betweenIncluded(
            $month->trading_starts_on->copy()->startOfDay(),
            $month->trading_concludes_on->copy()->endOfDay(),
        );
    }

    /** A single word the UI banners key off. */
    public function state(CycleMonth $month, ?CarbonInterface $at = null): string
    {
        $now = $this->at($at);

        return match (true) {
            $this->isBeforeOpen($month, $now) => self::BEFORE,
            $this->isOpen($month, $now) => self::DECLARATIONS,
            $now->lessThan($month->trading_starts_on->copy()->startOfDay()) => self::BETWEEN,
            $this->isTrading($month, $now) => self::TRADING,
            default => self::CLOSED,
        };
    }

    /**
     * Seconds until the state changes, or null when nothing is being counted down to.
     *
     * Before the window it counts to the opening; inside it, to the close; during the
     * days between, to the start of trading. Once trading has concluded the month has
     * nothing left to wait for.
     */
    public function secondsRemaining(CycleMonth $month, ?CarbonInterface $at = null): ?int
    {
        $now = $this->at($at);

        $target = match ($this->state($month, $now)) {
            self::BEFORE => $month->declarations_open_at,
            self::DECLARATIONS => $month->declarations_close_at,
            self::BETWEEN => $month->trading_starts_on->copy()->startOfDay(),
            self::TRADING => $month->trading_concludes_on->copy()->endOfDay(),
            default => null,
        };

        return $target === null ? null : max(0, (int) $now->diffInSeconds($target, false));
    }

    /**
     * The window as the frontend reads it, shared on every page and reused by the
     * declaration screens so the banner and the form can never disagree.
     *
     * @return array<string, mixed>
     */
    public function payload(CycleMonth $month, ?CarbonInterface $at = null): array
    {
        $now = $this->at($at);

        return [
            'id' => $month->id,
            'sequence' => $month->sequence,
            'label' => $month->label(),
            'status' => $month->status,
            'declarations_open_at' => $month->declarations_open_at->toIso8601String(),
            'declarations_close_at' => $month->declarations_close_at->toIso8601String(),
            'trading_starts_on' => $month->trading_starts_on->toDateString(),
            'trading_concludes_on' => $month->trading_concludes_on->toDateString(),
            'disbursement_on' => $month->disbursement_on->toDateString(),
            'declarations_open' => $this->isOpen($month, $now),
            'trading_open' => $this->isTrading($month, $now),
            'window' => $this->state($month, $now),
            'seconds_remaining' => $this->secondsRemaining($month, $now),
        ];
    }

    protected function at(?CarbonInterface $at): CarbonInterface
    {
        return $at ?? Carbon::now();
    }
}
