<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\InterestAllocationMethod;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's share of one month's interest.
 *
 * The basis columns record how the share was worked out, so an allocation can always
 * be explained to a member without recomputing the whole month.
 *
 * @property Money $pool_total_ngwee
 * @property Money $member_basis_ngwee
 * @property Money $pool_basis_ngwee
 * @property Money $amount_ngwee
 * @property int $residual_ngwee
 * @property InterestAllocationMethod $method
 */
#[Fillable([
    'member_id', 'cycle_month_id', 'method', 'pool_total_ngwee', 'member_basis_ngwee',
    'pool_basis_ngwee', 'amount_ngwee', 'residual_ngwee',
])]
class InterestAllocation extends Model
{
    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<CycleMonth, $this> */
    public function cycleMonth(): BelongsTo
    {
        return $this->belongsTo(CycleMonth::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'method' => InterestAllocationMethod::class,
            'pool_total_ngwee' => MoneyCast::class,
            'member_basis_ngwee' => MoneyCast::class,
            'pool_basis_ngwee' => MoneyCast::class,
            'amount_ngwee' => MoneyCast::class,
        ];
    }
}
