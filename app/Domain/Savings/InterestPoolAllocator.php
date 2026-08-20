<?php

namespace App\Domain\Savings;

use App\Domain\Savings\Strategies\FlatOwnSavingsStrategy;
use App\Domain\Savings\Strategies\InterestAllocationStrategy;
use App\Domain\Savings\Strategies\PooledProRataStrategy;
use App\Models\CycleMonth;
use App\Models\InterestAllocation;
use App\Models\Member;
use Brick\Money\Money;
use Illuminate\Support\Collection;

/**
 * Writes a month's interest allocations.
 *
 * The arithmetic belongs to the strategy the month nominates — pooled pro-rata for
 * most months, a flat rate on own savings for the opening one — and this class turns
 * the figures it returns into rows. Writing is an upsert keyed on member and month,
 * so re-running a month corrects it rather than crediting it twice.
 */
class InterestPoolAllocator
{
    public function __construct(
        protected FlatOwnSavingsStrategy $flat,
        protected PooledProRataStrategy $proRata,
    ) {}

    /**
     * @return Collection<int, InterestAllocation>
     */
    public function allocate(CycleMonth $month, Money $pool): Collection
    {
        $members = $month->cycle->members()->get();
        $figures = $this->strategyFor($month)->allocate($month, $members, $pool);

        return $members->map(fn (Member $member): InterestAllocation => InterestAllocation::updateOrCreate(
            ['member_id' => $member->id, 'cycle_month_id' => $month->id],
            $figures[$member->id] + ['method' => $month->interest_allocation_method],
        ));
    }

    /** The strategy nominated by the month's allocation method. */
    public function strategyFor(CycleMonth $month): InterestAllocationStrategy
    {
        return match ($month->interest_allocation_method) {
            $this->flat->method() => $this->flat,
            default => $this->proRata,
        };
    }

    /**
     * Kept as the module's named entry point to the rounding rule.
     *
     * @param  array<int, int>  $bases  member id => basis in ngwee
     * @return array<int, array{amount: int, residual: int}>
     */
    public function largestRemainder(array $bases, int $totalBasis, int $pool): array
    {
        return LargestRemainder::split($bases, $totalBasis, $pool);
    }
}
