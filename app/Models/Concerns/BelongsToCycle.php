<?php

namespace App\Models\Concerns;

use App\Models\Cycle;
use App\Models\Scopes\CycleScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every model whose rows belong to exactly one cycle.
 *
 * @phpstan-require-extends Model
 */
#[ScopedBy(CycleScope::class)]
trait BelongsToCycle
{
    /** @return BelongsTo<Cycle, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    /**
     * Read across every cycle, ignoring the pinned one.
     *
     * @param  Builder<static>  $query
     */
    public function scopeAcrossCycles(Builder $query): void
    {
        $query->withoutGlobalScope(CycleScope::class);
    }

    /**
     * Read one specific cycle regardless of which is pinned.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForCycle(Builder $query, Cycle|int $cycle): void
    {
        $query->withoutGlobalScope(CycleScope::class)
            ->where($this->qualifyColumn('cycle_id'), $cycle instanceof Cycle ? $cycle->id : $cycle);
    }
}
