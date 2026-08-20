<?php

namespace App\Domain\Savings\Strategies;

use App\Domain\Savings\SavingsLedger;
use App\Enums\InterestAllocationMethod;
use App\Models\CycleMonth;
use App\Models\Member;
use App\Support\Kwacha;
use Brick\Money\Money;
use Illuminate\Support\Collection;

/**
 * The opening month: each member earns the cycle's monthly rate on their own savings.
 *
 * December has no lending history to pool, so there is nothing to share out. The pool
 * is ignored entirely and the group simply credits 5% of what each member put in.
 */
class FlatOwnSavingsStrategy implements InterestAllocationStrategy
{
    public function __construct(protected SavingsLedger $ledger) {}

    public function method(): InterestAllocationMethod
    {
        return InterestAllocationMethod::OwnSavingsFlat;
    }

    /**
     * @param  Collection<int, Member>  $members
     * @return array<int, array{member_basis_ngwee: int, pool_basis_ngwee: int, pool_total_ngwee: int, amount_ngwee: int, residual_ngwee: int}>
     */
    public function allocate(CycleMonth $month, Collection $members, Money $pool): array
    {
        $rateBps = $month->cycle->monthly_interest_bps;

        return $members->mapWithKeys(function (Member $member) use ($month, $rateBps): array {
            $savings = Kwacha::toNgwee($this->ledger->cumulativeSavings($member, $month));

            return [$member->id => [
                'pool_total_ngwee' => 0,
                'member_basis_ngwee' => $savings,
                'pool_basis_ngwee' => $savings,
                'amount_ngwee' => intdiv($savings * $rateBps, 10_000),
                'residual_ngwee' => 0,
            ]];
        })->all();
    }
}
