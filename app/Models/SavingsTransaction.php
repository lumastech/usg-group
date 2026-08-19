<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\SavingsTransactionType;
use App\Enums\TransactionSource;
use Brick\Money\Money;
use Database\Factories\SavingsTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A single movement on a member's savings ledger.
 *
 * @property int $id
 * @property int $member_id
 * @property int $cycle_month_id
 * @property SavingsTransactionType $type
 * @property Money $amount_ngwee
 * @property Money|null $declared_amount_ngwee
 * @property TransactionSource $source
 * @property Carbon $occurred_on
 * @property string|null $note
 */
#[Fillable([
    'member_id', 'cycle_month_id', 'type', 'amount_ngwee', 'declared_amount_ngwee',
    'recorded_by_member_id', 'source', 'occurred_on', 'note',
])]
class SavingsTransaction extends Model
{
    /** @use HasFactory<SavingsTransactionFactory> */
    use HasFactory, LogsActivity;

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

    /** The member declared one amount and paid another. */
    public function varianceNgwee(): int
    {
        if ($this->declared_amount_ngwee === null) {
            return 0;
        }

        return $this->getRawOriginal('amount_ngwee') - $this->getRawOriginal('declared_amount_ngwee');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => SavingsTransactionType::class,
            'source' => TransactionSource::class,
            'amount_ngwee' => MoneyCast::class,
            'declared_amount_ngwee' => MoneyCast::class,
            'occurred_on' => 'date',
        ];
    }
}
