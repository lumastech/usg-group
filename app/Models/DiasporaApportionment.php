<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\ApportionmentItemStatus;
use App\Models\Concerns\BelongsToCycle;
use Brick\Money\Money;
use Database\Factories\DiasporaApportionmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One equal split of a sum across the members living abroad.
 *
 * The split is stored as a batch of pending shares rather than as ledger entries: the
 * fund is only debited when a transfer is actually confirmed, so a share still waiting
 * on a bank never overstates what has left the fund.
 *
 * @property int $id
 * @property int $cycle_id
 * @property Money $total_ngwee
 * @property Money $apportioned_ngwee
 * @property Money $share_ngwee
 * @property Money $remainder_ngwee
 * @property Carbon $declared_on
 * @property string|null $note
 */
#[Fillable([
    'cycle_id', 'total_ngwee', 'apportioned_ngwee', 'share_ngwee', 'remainder_ngwee',
    'declared_on', 'recorded_by_member_id', 'second_approver_member_id', 'note',
])]
class DiasporaApportionment extends Model
{
    /** @use HasFactory<DiasporaApportionmentFactory> */
    use BelongsToCycle, HasFactory, LogsActivity;

    /** @return HasMany<DiasporaApportionmentItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(DiasporaApportionmentItem::class);
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

    /** Every transfer has been ticked off. */
    public function isSettled(): bool
    {
        return ! $this->items()->where('status', ApportionmentItemStatus::Pending)->exists();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'total_ngwee' => MoneyCast::class,
            'apportioned_ngwee' => MoneyCast::class,
            'share_ngwee' => MoneyCast::class,
            'remainder_ngwee' => MoneyCast::class,
            'declared_on' => 'date',
        ];
    }
}
