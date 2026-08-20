<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\SocialFundTransactionType;
use App\Exceptions\ImmutableLedgerException;
use App\Models\Concerns\BelongsToCycle;
use Brick\Money\Money;
use Database\Factories\SocialFundTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One movement on the Social Fund ledger.
 *
 * The amount is signed — inflows positive, outflows negative — so the fund's balance
 * is the sum of this column and nothing else caches it.
 *
 * @property int $id
 * @property int $cycle_id
 * @property int|null $cycle_month_id
 * @property int|null $member_id
 * @property SocialFundTransactionType $type
 * @property Money $amount_ngwee
 * @property Carbon $occurred_on
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $recorded_by_member_id
 * @property int|null $second_approver_member_id
 * @property string|null $note
 */
#[Fillable([
    'cycle_id', 'cycle_month_id', 'member_id', 'type', 'amount_ngwee', 'occurred_on',
    'reference_type', 'reference_id', 'recorded_by_member_id', 'second_approver_member_id', 'note',
])]
class SocialFundTransaction extends Model
{
    /** @use HasFactory<SocialFundTransactionFactory> */
    use BelongsToCycle, HasFactory, LogsActivity;

    /**
     * The fund's ledger is append-only, exactly as the savings ledger is.
     *
     * A grant paid in error is corrected with a reversing Adjustment, so both the
     * payment and the correction stay on the record the group reads at share-out.
     */
    protected static function booted(): void
    {
        static::updating(function (self $transaction): void {
            throw new ImmutableLedgerException(
                'Social fund entries cannot be edited. Post a reversing adjustment instead.'
            );
        });

        static::deleting(function (self $transaction): void {
            throw new ImmutableLedgerException(
                'Social fund entries cannot be deleted. Post a reversing adjustment instead.'
            );
        });
    }

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

    /** @return BelongsTo<Member, $this> */
    public function secondApprover(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'second_approver_member_id');
    }

    /** What this entry mirrors: a loan penalty, a grant claim, an apportionment share. */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => SocialFundTransactionType::class,
            'amount_ngwee' => MoneyCast::class,
            'occurred_on' => 'date',
        ];
    }
}
