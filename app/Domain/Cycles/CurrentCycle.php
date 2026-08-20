<?php

namespace App\Domain\Cycles;

use App\Enums\CycleStatus;
use App\Models\Cycle;
use RuntimeException;

/**
 * Resolves the cycle the application is currently operating in.
 *
 * Registered as a singleton, so the lookup happens at most once per request. The
 * resolved cycle also drives the CycleScope global scope, which is inert until
 * something (the web middleware, a console command, a test) sets a cycle here.
 */
class CurrentCycle
{
    protected ?Cycle $cycle = null;

    protected bool $resolved = false;

    /** Pin a cycle explicitly, bypassing the active-cycle lookup. */
    public function set(?Cycle $cycle): static
    {
        $this->cycle = $cycle;
        $this->resolved = true;

        return $this;
    }

    /** Forget the pinned cycle so the next read looks it up again. */
    public function forget(): static
    {
        $this->cycle = null;
        $this->resolved = false;

        return $this;
    }

    /**
     * The current cycle, or null when the group has none running.
     *
     * Falls back to the single Active cycle. A group is only ever meant to run one
     * at a time, so the most recently started Active cycle wins if that slips.
     */
    public function get(): ?Cycle
    {
        if (! $this->resolved) {
            $this->cycle = Cycle::query()
                ->where('status', CycleStatus::Active)
                ->orderByDesc('starts_on')
                ->first();

            $this->resolved = true;
        }

        return $this->cycle;
    }

    public function getOrFail(): Cycle
    {
        return $this->get() ?? throw new RuntimeException('No active cycle is configured.');
    }

    public function id(): ?int
    {
        return $this->get()?->id;
    }

    /** Whether a cycle has been resolved without triggering a lookup. */
    public function isPinned(): bool
    {
        return $this->resolved && $this->cycle !== null;
    }
}
