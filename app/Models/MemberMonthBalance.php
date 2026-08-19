<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rebuildable snapshot of where a member stands at the end of one month.
 *
 * Nothing here is authoritative; every column can be recomputed from the ledgers.
 * It exists so statements, dashboards and eligibility checks do not each have to
 * walk the full transaction history.
 *
 * @property Money $savings_ngwee
 * @property Money $cumulative_savings_ngwee
 * @property Money $interest_earned_ngwee
 * @property Money $cumulative_interest_earned_ngwee
 * @property Money $cumulative_interest_paid_ngwee
 * @property Money $loan_balance_ngwee
 * @property Money $social_loan_balance_ngwee
 * @property Money $member_value_ngwee
 * @property Money $net_value_ngwee
 * @property Money $two_times_savings_ngwee
 * @property Money $eligible_to_borrow_ngwee
 * @property Money $borrowed_to_date_ngwee
 * @property Money $borrowing_target_balance_ngwee
 */
#[Fillable([
    'member_id', 'cycle_month_id', 'savings_ngwee', 'cumulative_savings_ngwee',
    'interest_earned_ngwee', 'cumulative_interest_earned_ngwee', 'cumulative_interest_paid_ngwee',
    'loan_balance_ngwee', 'social_loan_balance_ngwee', 'member_value_ngwee', 'net_value_ngwee',
    'two_times_savings_ngwee', 'eligible_to_borrow_ngwee', 'borrowed_to_date_ngwee',
    'borrowing_target_balance_ngwee',
])]
class MemberMonthBalance extends Model
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

    /** A member owing more than they hold is the group's main risk signal. */
    public function hasNegativeNetValue(): bool
    {
        return $this->getRawOriginal('net_value_ngwee') < 0;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'savings_ngwee' => MoneyCast::class,
            'cumulative_savings_ngwee' => MoneyCast::class,
            'interest_earned_ngwee' => MoneyCast::class,
            'cumulative_interest_earned_ngwee' => MoneyCast::class,
            'cumulative_interest_paid_ngwee' => MoneyCast::class,
            'loan_balance_ngwee' => MoneyCast::class,
            'social_loan_balance_ngwee' => MoneyCast::class,
            'member_value_ngwee' => MoneyCast::class,
            'net_value_ngwee' => MoneyCast::class,
            'two_times_savings_ngwee' => MoneyCast::class,
            'eligible_to_borrow_ngwee' => MoneyCast::class,
            'borrowed_to_date_ngwee' => MoneyCast::class,
            'borrowing_target_balance_ngwee' => MoneyCast::class,
        ];
    }
}
