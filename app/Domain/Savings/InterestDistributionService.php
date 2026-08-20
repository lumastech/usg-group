<?php

namespace App\Domain\Savings;

use App\Domain\Loans\MonthlyInterestIncome;
use App\Domain\Support\MoneyMutator;
use App\Models\CycleMonth;
use App\Models\InterestAllocation;
use App\Models\Member;
use App\Support\Kwacha;
use Brick\Money\Money;
use Illuminate\Support\Collection;

/**
 * The single write path for a month's interest.
 *
 * It takes the month's pool — the interest borrowers paid, or an amount stated
 * explicitly while the lending engine does not yet exist — hands it to the month's
 * allocation strategy, and refreshes the balances the statements read from. The whole
 * thing is one transaction with one activity-log entry, so a month is either credited
 * in full or not at all.
 *
 * Rounding: the pool is split in whole ngwee by largest remainder, which means the odd
 * ngwee left over goes to the members with the largest fractional shares rather than to
 * an administrative fund. Every ngwee of the pool therefore reaches a member, and the
 * allocation rows record who received a residual ngwee (`residual_ngwee`) so the split
 * can be explained.
 */
class InterestDistributionService
{
    public function __construct(
        protected InterestPoolAllocator $allocator,
        protected MemberBalanceCalculator $balances,
        protected MonthlyInterestIncome $income,
        protected MoneyMutator $mutator,
    ) {}

    /**
     * Credits a month's interest to every member and rebuilds their snapshots.
     *
     * @return Collection<int, InterestAllocation>
     */
    public function distribute(CycleMonth $month, Member $actor, ?Money $pool = null): Collection
    {
        $pool ??= $this->income->poolFor($month);

        return $this->mutator->mutate(
            $actor,
            'Distributed '.Kwacha::format($pool)." of interest for {$month->label()}",
            function () use ($month, $pool): Collection {
                $allocations = $this->allocator->allocate($month, $pool);

                $this->balances->rebuildMonth($month);

                return $allocations;
            },
            [
                'cycle_month_id' => $month->id,
                'pool_ngwee' => Kwacha::toNgwee($pool),
                'method' => $month->interest_allocation_method->value,
            ],
        );
    }

    /** What the lending engine says the month's pool is, before it is distributed. */
    public function poolFor(CycleMonth $month): Money
    {
        return $this->income->poolFor($month);
    }
}
