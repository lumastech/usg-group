<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\DeclarationStatus;
use App\Models\Concerns\BelongsToCycle;
use Brick\Money\Money;
use Database\Factories\DeclarationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * What a member told the group they would bring to, and take from, one month's table.
 *
 * The declaration is a promise, not a movement of money: nothing is posted to any
 * ledger until the trading session it feeds is concluded. That is why this row stays
 * editable while the window is open and is only ever read afterwards.
 *
 * @property int $id
 * @property int $cycle_id
 * @property int $cycle_month_id
 * @property int $member_id
 * @property Money $saving_amount_ngwee
 * @property Money $loan_repayment_amount_ngwee
 * @property Money $loan_requested_amount_ngwee
 * @property Money $total_expected_payment_ngwee
 * @property Carbon|null $submitted_at
 * @property bool $is_late
 * @property DeclarationStatus $status
 * @property string|null $note
 */
#[Fillable([
    'cycle_id', 'cycle_month_id', 'member_id', 'saving_amount_ngwee',
    'loan_repayment_amount_ngwee', 'loan_requested_amount_ngwee',
    'total_expected_payment_ngwee', 'submitted_at', 'is_late', 'status',
    'recorded_by_member_id', 'note',
])]
class Declaration extends Model
{
    /** @use HasFactory<DeclarationFactory> */
    use BelongsToCycle, HasFactory, LogsActivity;

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

    /** @return BelongsTo<Member, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'recorded_by_member_id');
    }

    /** @return HasOne<TradingEntry, $this> */
    public function tradingEntry(): HasOne
    {
        return $this->hasOne(TradingEntry::class);
    }

    /** @param  Builder<Declaration>  $query */
    public function scopeForMonth(Builder $query, CycleMonth|int $month): void
    {
        $query->where('cycle_month_id', $month instanceof CycleMonth ? $month->id : $month);
    }

    /**
     * What the member owes the table: savings plus repayment, less the loan they asked
     * for. Negative means the fund pays them the difference on the day.
     */
    public function totalExpectedNgwee(): int
    {
        return $this->getRawOriginal('saving_amount_ngwee')
            + $this->getRawOriginal('loan_repayment_amount_ngwee')
            - $this->getRawOriginal('loan_requested_amount_ngwee');
    }

    /** The cash the member physically brings, ignoring anything they are owed. */
    public function expectedInNgwee(): int
    {
        return $this->getRawOriginal('saving_amount_ngwee')
            + $this->getRawOriginal('loan_repayment_amount_ngwee');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'saving_amount_ngwee' => MoneyCast::class,
            'loan_repayment_amount_ngwee' => MoneyCast::class,
            'loan_requested_amount_ngwee' => MoneyCast::class,
            'total_expected_payment_ngwee' => MoneyCast::class,
            'submitted_at' => 'datetime',
            'is_late' => 'boolean',
            'status' => DeclarationStatus::class,
        ];
    }
}
