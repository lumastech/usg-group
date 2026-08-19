<?php

namespace App\Domain\Savings;

use App\Enums\InterestAllocationMethod;
use App\Models\CycleMonth;
use App\Models\InterestAllocation;
use App\Models\Member;
use App\Support\Kwacha;
use Brick\Money\Money;
use Illuminate\Support\Collection;

/**
 * Splits a month's interest across the membership.
 *
 * Borrowers pay interest into a pool; that pool is then shared out among every member
 * in proportion to their cumulative savings, so savers earn from the group's lending
 * whether or not they borrowed themselves. The opening month of a cycle is different:
 * there is no lending history to draw on, so each member simply earns the monthly rate
 * on their own savings.
 *
 * Shares are computed in whole ngwee using the largest-remainder method, which
 * guarantees the allocations sum to exactly the pool with nothing lost to rounding.
 */
class InterestPoolAllocator
{
    public function __construct(protected SavingsLedger $ledger) {}

    /**
     * @return Collection<int, InterestAllocation>
     */
    public function allocate(CycleMonth $month, Money $pool): Collection
    {
        $members = $month->cycle->members()->get();

        return $month->interest_allocation_method === InterestAllocationMethod::OwnSavingsFlat
            ? $this->allocateFlat($month, $members)
            : $this->allocateProRata($month, $members, $pool);
    }

    /**
     * Opening month: each member earns the cycle's monthly rate on their own savings.
     *
     * @param  Collection<int, Member>  $members
     * @return Collection<int, InterestAllocation>
     */
    protected function allocateFlat(CycleMonth $month, Collection $members): Collection
    {
        $rateBps = $month->cycle->monthly_interest_bps;

        return $members->map(function (Member $member) use ($month, $rateBps): InterestAllocation {
            $savings = Kwacha::toNgwee($this->ledger->cumulativeSavings($member, $month));
            $amount = intdiv($savings * $rateBps, 10_000);

            return $this->store($month, $member, [
                'pool_total_ngwee' => 0,
                'member_basis_ngwee' => $savings,
                'pool_basis_ngwee' => $savings,
                'amount_ngwee' => $amount,
                'residual_ngwee' => 0,
            ]);
        });
    }

    /**
     * Every later month: pro-rata by cumulative savings, largest remainder first.
     *
     * @param  Collection<int, Member>  $members
     * @return Collection<int, InterestAllocation>
     */
    protected function allocateProRata(CycleMonth $month, Collection $members, Money $pool): Collection
    {
        $poolNgwee = Kwacha::toNgwee($pool);

        $bases = $members->mapWithKeys(fn (Member $member): array => [
            $member->id => Kwacha::toNgwee($this->ledger->cumulativeSavings($member, $month)),
        ]);

        $totalBasis = $bases->sum();

        if ($totalBasis <= 0 || $poolNgwee === 0) {
            return $members->map(fn (Member $member): InterestAllocation => $this->store($month, $member, [
                'pool_total_ngwee' => $poolNgwee,
                'member_basis_ngwee' => max(0, $bases[$member->id]),
                'pool_basis_ngwee' => max(0, $totalBasis),
                'amount_ngwee' => 0,
                'residual_ngwee' => 0,
            ]));
        }

        $shares = $this->largestRemainder($bases->all(), $totalBasis, $poolNgwee);

        return $members->map(fn (Member $member): InterestAllocation => $this->store($month, $member, [
            'pool_total_ngwee' => $poolNgwee,
            'member_basis_ngwee' => $bases[$member->id],
            'pool_basis_ngwee' => $totalBasis,
            'amount_ngwee' => $shares[$member->id]['amount'],
            'residual_ngwee' => $shares[$member->id]['residual'],
        ]));
    }

    /**
     * Distributes a whole-ngwee pool proportionally without losing or inventing a ngwee.
     *
     * Each member first takes the floor of their exact share. The ngwee left over are
     * handed out one at a time, largest fractional part first, ties broken by the
     * lower member id so the result is stable across runs.
     *
     * @param  array<int, int>  $bases  member id => basis in ngwee
     * @return array<int, array{amount: int, residual: int}>
     */
    public function largestRemainder(array $bases, int $totalBasis, int $pool): array
    {
        $shares = [];
        $allocated = 0;

        foreach ($bases as $memberId => $basis) {
            $exact = $pool * $basis;
            $amount = intdiv($exact, $totalBasis);

            $shares[$memberId] = ['amount' => $amount, 'remainder' => $exact % $totalBasis, 'residual' => 0];
            $allocated += $amount;
        }

        $leftover = $pool - $allocated;

        $order = collect($shares)
            ->map(fn (array $share, int $memberId): array => $share + ['member_id' => $memberId])
            ->sortBy([['remainder', 'desc'], ['member_id', 'asc']])
            ->take(max(0, $leftover));

        foreach ($order as $share) {
            $shares[$share['member_id']]['amount']++;
            $shares[$share['member_id']]['residual'] = 1;
        }

        return array_map(
            fn (array $share): array => ['amount' => $share['amount'], 'residual' => $share['residual']],
            $shares,
        );
    }

    /**
     * @param  array<string, int>  $attributes
     */
    protected function store(CycleMonth $month, Member $member, array $attributes): InterestAllocation
    {
        return InterestAllocation::updateOrCreate(
            ['member_id' => $member->id, 'cycle_month_id' => $month->id],
            $attributes + ['method' => $month->interest_allocation_method],
        );
    }
}
