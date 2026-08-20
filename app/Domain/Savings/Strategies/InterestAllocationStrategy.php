<?php

namespace App\Domain\Savings\Strategies;

use App\Enums\InterestAllocationMethod;
use App\Models\CycleMonth;
use App\Models\Member;
use Brick\Money\Money;
use Illuminate\Support\Collection;

/**
 * How one month's interest is worked out for each member.
 *
 * A strategy computes and returns figures only — persisting them is the allocator's
 * job — so the arithmetic of a month can be tested and explained on its own. Which
 * strategy runs is decided by the month's InterestAllocationMethod, which means the
 * group can change how a future month is credited without touching past months.
 */
interface InterestAllocationStrategy
{
    public function method(): InterestAllocationMethod;

    /**
     * @param  Collection<int, Member>  $members
     * @return array<int, array{member_basis_ngwee: int, pool_basis_ngwee: int, pool_total_ngwee: int, amount_ngwee: int, residual_ngwee: int}>
     *                                                                                                                                          keyed by member id
     */
    public function allocate(CycleMonth $month, Collection $members, Money $pool): array;
}
