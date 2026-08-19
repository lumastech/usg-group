<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\CycleStatus;
use App\Enums\WeekendTradingPolicy;
use Brick\Money\Money;
use Database\Factories\CycleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A single savings-and-lending cycle. Every record in the application is scoped to one.
 *
 * @property int $id
 * @property string $name
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property int $registration_closes_after_month
 * @property int $loan_lockdown_starts_month
 * @property Carbon $final_repayment_date
 * @property Money $lockdown_savings_cap_ngwee
 * @property Money $joining_fee_ngwee
 * @property Money $late_joining_fee_ngwee
 * @property Money $social_fund_contribution_ngwee
 * @property Money $min_savings_ngwee
 * @property Money $savings_increment_ngwee
 * @property Money $borrowing_target_ngwee
 * @property Money $late_transfer_penalty_per_day_ngwee
 * @property int $monthly_interest_bps
 * @property int $social_fund_interest_bps
 * @property int $missed_installment_penalty_bps
 * @property int $max_loan_multiple
 * @property WeekendTradingPolicy $weekend_trading_policy
 * @property CycleStatus $status
 */
#[Fillable([
    'name', 'starts_on', 'ends_on', 'registration_closes_after_month',
    'loan_lockdown_starts_month', 'final_repayment_date', 'lockdown_savings_cap_ngwee',
    'joining_fee_ngwee', 'late_joining_fee_ngwee', 'social_fund_contribution_ngwee',
    'min_savings_ngwee', 'savings_increment_ngwee', 'borrowing_target_ngwee',
    'late_transfer_penalty_per_day_ngwee', 'monthly_interest_bps', 'social_fund_interest_bps',
    'missed_installment_penalty_bps', 'max_loan_multiple', 'weekend_trading_policy', 'status',
])]
class Cycle extends Model
{
    /** @use HasFactory<CycleFactory> */
    use HasFactory, LogsActivity;

    /** @return HasMany<CycleMonth, $this> */
    public function months(): HasMany
    {
        return $this->hasMany(CycleMonth::class)->orderBy('sequence');
    }

    /** @return HasMany<Member, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class)->orderBy('member_number');
    }

    public function monthAt(int $sequence): ?CycleMonth
    {
        return $this->months()->where('sequence', $sequence)->first();
    }

    /** Whether new members may still register, given the month they would join in. */
    public function registrationOpenForMonth(int $sequence): bool
    {
        return $sequence <= $this->registration_closes_after_month;
    }

    /** From September to cycle end no new loans may be issued and savings are capped. */
    public function isLockdownMonth(int $sequence): bool
    {
        return $sequence >= $this->loan_lockdown_starts_month;
    }

    /** The savings ceiling that applies in a given month, or null when uncapped. */
    public function savingsCapForMonth(int $sequence): ?Money
    {
        return $this->isLockdownMonth($sequence) ? $this->lockdown_savings_cap_ngwee : null;
    }

    public function daysToFinalRepayment(?Carbon $from = null): int
    {
        return (int) ($from ?? Carbon::today())->diffInDays($this->final_repayment_date, false);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'final_repayment_date' => 'date',
            'lockdown_savings_cap_ngwee' => MoneyCast::class,
            'joining_fee_ngwee' => MoneyCast::class,
            'late_joining_fee_ngwee' => MoneyCast::class,
            'social_fund_contribution_ngwee' => MoneyCast::class,
            'min_savings_ngwee' => MoneyCast::class,
            'savings_increment_ngwee' => MoneyCast::class,
            'borrowing_target_ngwee' => MoneyCast::class,
            'late_transfer_penalty_per_day_ngwee' => MoneyCast::class,
            'weekend_trading_policy' => WeekendTradingPolicy::class,
            'status' => CycleStatus::class,
        ];
    }
}
