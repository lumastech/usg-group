<?php

namespace App\Domain\Savings\Strategies;

use App\Domain\Savings\LargestRemainder;
use App\Domain\Savings\SavingsLedger;
use App\Enums\InterestAllocationMethod;
use App\Models\CycleMonth;
use App\Models\Member;
use App\Support\Kwacha;
use Brick\Money\Money;
use Illuminate\Support\Collection;

/**
 * Every month after the first: the whole pool of loan interest, shared out in
 * proportion to each member's cumulative savings.
 *
 * Savers earn from the group's lending whether or not they borrowed themselves, so a
 * member who borrows heavily can show a net loss once the interest they paid is set
 * against the share they earned.
 */
class PooledProRataStrategy implements InterestAllocationStrategy
{
    public function __construct(protected SavingsLedger $ledger) {}

    public function method(): InterestAllocationMethod
    {
        return InterestAllocationMethod::PooledProRata;
    }

    /**
     * @param  Collection<int, Member>  $members
     * @return array<int, array{member_basis_ngwee: int, pool_basis_ngwee: int, pool_total_ngwee: int, amount_ngwee: int, residual_ngwee: int}>
     */
    public function allocate(CycleMonth $month, Collection $members, Money $pool): array
    {
        $poolNgwee = Kwacha::toNgwee($pool);

        $bases = $members->mapWithKeys(fn (Member $member): array => [
            $member->id => Kwacha::toNgwee($this->ledger->cumulativeSavings($member, $month)),
        ])->all();

        $totalBasis = array_sum($bases);

        // Nothing to share, or nobody has saved yet: everyone is credited zero, but the
        // rows are still written so the month has a complete, explainable record.
        if ($totalBasis <= 0 || $poolNgwee === 0) {
            return array_map(fn (int $basis): array => [
                'pool_total_ngwee' => $poolNgwee,
                'member_basis_ngwee' => max(0, $basis),
                'pool_basis_ngwee' => max(0, $totalBasis),
                'amount_ngwee' => 0,
                'residual_ngwee' => 0,
            ], $bases);
        }

        $shares = LargestRemainder::split($bases, $totalBasis, $poolNgwee);
        $allocations = [];

        foreach ($bases as $memberId => $basis) {
            $allocations[$memberId] = [
                'pool_total_ngwee' => $poolNgwee,
                'member_basis_ngwee' => $basis,
                'pool_basis_ngwee' => $totalBasis,
                'amount_ngwee' => $shares[$memberId]['amount'],
                'residual_ngwee' => $shares[$memberId]['residual'],
            ];
        }

        return $allocations;
    }
}
