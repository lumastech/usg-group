<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\SettlementStatus;
use App\Models\Concerns\BelongsToCycle;
use Brick\Money\Money;
use Database\Factories\NextOfKinRepaymentArrangementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * How a deceased member's outstanding debt is to be repaid.
 *
 * Stands in place of a payout when the estate's loans outrun their savings. The
 * funeral grant is a separate matter and is not netted off here — it belongs to the
 * Social Fund and is shown alongside on the closure screen, never inside the sum.
 *
 * @property int $id
 * @property int|null $next_of_kin_id
 * @property Money $amount_owed_ngwee
 * @property string $agreed_terms
 * @property SettlementStatus $status
 * @property array<int, array<string, mixed>> $breakdown
 * @property Carbon|null $agreed_on
 */
#[Fillable([
    'cycle_id', 'member_id', 'next_of_kin_id', 'amount_owed_ngwee', 'agreed_terms',
    'status', 'breakdown', 'agreed_on', 'recorded_by_member_id',
    'second_approver_member_id', 'settled_at', 'note',
])]
class NextOfKinRepaymentArrangement extends Model
{
    /** @use HasFactory<NextOfKinRepaymentArrangementFactory> */
    use BelongsToCycle, HasFactory, LogsActivity;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => SettlementStatus::Outstanding->value,
    ];

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<NextOfKin, $this> */
    public function nextOfKin(): BelongsTo
    {
        return $this->belongsTo(NextOfKin::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SettlementStatus::class,
            'breakdown' => 'array',
            'amount_owed_ngwee' => MoneyCast::class,
            'agreed_on' => 'date',
            'settled_at' => 'datetime',
        ];
    }
}
