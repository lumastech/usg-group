<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\LoanScheduleItemStatus;
use App\Enums\LoanStatus;
use App\Models\Concerns\BelongsToCycle;
use Brick\Money\Money;
use Database\Factories\LoanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One member's loan for one cycle.
 *
 * `current_balance_ngwee` is a denormalised convenience for listing screens. The
 * authority is always the ledger — LoanLedger::rebuildBalance() recomputes it from
 * loan_transactions, and a test asserts the two agree.
 *
 * @property int $id
 * @property int $cycle_id
 * @property int $member_id
 * @property Money $principal_ngwee
 * @property int $tenor_months
 * @property bool $schedule_compressed
 * @property LoanStatus $status
 * @property Carbon $requested_at
 * @property int|null $approved_by_member_id
 * @property int|null $second_approver_member_id
 * @property Carbon|null $approved_at
 * @property Carbon|null $disbursed_at
 * @property int|null $disbursement_position
 * @property string|null $out_of_order_reason
 * @property Carbon|null $settled_at
 * @property Carbon|null $defaulted_at
 * @property bool $discretion_override
 * @property string|null $discretion_note
 * @property Money $current_balance_ngwee
 */
#[Fillable([
    'cycle_id', 'member_id', 'principal_ngwee', 'tenor_months', 'schedule_compressed',
    'status', 'requested_at', 'approved_by_member_id', 'second_approver_member_id',
    'approved_at', 'rejected_by_member_id', 'rejected_at', 'rejection_reason',
    'disbursed_at', 'disbursed_by_member_id', 'disbursement_cycle_month_id',
    'disbursement_position', 'out_of_order_reason', 'settled_at', 'defaulted_at',
    'discretion_override', 'discretion_note', 'current_balance_ngwee',
])]
class Loan extends Model
{
    /** @use HasFactory<LoanFactory> */
    use BelongsToCycle, HasFactory, LogsActivity;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => LoanStatus::Requested->value,
        'schedule_compressed' => false,
        'discretion_override' => false,
        'current_balance_ngwee' => 0,
    ];

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return HasMany<LoanScheduleItem, $this> */
    public function scheduleItems(): HasMany
    {
        return $this->hasMany(LoanScheduleItem::class)->orderBy('sequence');
    }

    /** @return HasMany<LoanTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(LoanTransaction::class)->orderBy('occurred_on')->orderBy('id');
    }

    /** @return HasOne<CollateralClaim, $this> */
    public function collateralClaim(): HasOne
    {
        return $this->hasOne(CollateralClaim::class)->latestOfMany();
    }

    /** @return BelongsTo<Member, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'approved_by_member_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function secondApprover(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'second_approver_member_id');
    }

    /** @return BelongsTo<CycleMonth, $this> */
    public function disbursementMonth(): BelongsTo
    {
        return $this->belongsTo(CycleMonth::class, 'disbursement_cycle_month_id');
    }

    /** Loans whose money is out of the fund and still owed. */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereIn('status', array_column(LoanStatus::outstanding(), 'value'));
    }

    /** Loans that stop the member requesting another one. */
    public function scopeBlocking(Builder $query): void
    {
        $query->whereIn('status', array_column(LoanStatus::blocking(), 'value'));
    }

    /** The next installment still awaiting payment, if any. */
    public function nextDueItem(): ?LoanScheduleItem
    {
        return $this->scheduleItems()
            ->whereIn('status', [LoanScheduleItemStatus::Pending->value, LoanScheduleItemStatus::PartiallyPaid->value])
            ->orderBy('sequence')
            ->first();
    }

    /** How much of the principal the member has not yet repaid. */
    public function principalOutstandingNgwee(): int
    {
        $repaid = (int) $this->transactions()->sum('principal_portion_ngwee');

        return max(0, $this->getRawOriginal('principal_ngwee') - $repaid);
    }

    public function penaltiesChargedNgwee(): int
    {
        return (int) $this->transactions()
            ->whereIn('type', ['late_penalty_daily', 'missed_installment_penalty'])
            ->sum('amount_ngwee');
    }

    public function interestChargedNgwee(): int
    {
        return (int) $this->transactions()->where('type', 'interest_charge')->sum('amount_ngwee');
    }

    public function interestPaidNgwee(): int
    {
        return (int) $this->transactions()->sum('interest_portion_ngwee');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'principal_ngwee' => MoneyCast::class,
            'current_balance_ngwee' => MoneyCast::class,
            'status' => LoanStatus::class,
            'schedule_compressed' => 'boolean',
            'discretion_override' => 'boolean',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'disbursed_at' => 'datetime',
            'settled_at' => 'datetime',
            'defaulted_at' => 'datetime',
        ];
    }
}
