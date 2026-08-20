<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\PayoutCase;
use App\Enums\SettlementStatus;
use App\Models\Concerns\BelongsToCycle;
use Brick\Money\Money;
use Database\Factories\MemberDebtFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * What a departed member still owes the group.
 *
 * Created instead of a payout when the closure comes out negative, so the group's
 * books never carry a payment of a negative amount.
 *
 * @property int $id
 * @property PayoutCase $case
 * @property Money $amount_owed_ngwee
 * @property SettlementStatus $status
 * @property array<int, array<string, mixed>> $breakdown
 * @property Carbon|null $settled_at
 */
#[Fillable([
    'cycle_id', 'member_id', 'case', 'amount_owed_ngwee', 'status', 'breakdown',
    'recorded_by_member_id', 'second_approver_member_id', 'settled_at', 'note',
])]
class MemberDebt extends Model
{
    /** @use HasFactory<MemberDebtFactory> */
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'case' => PayoutCase::class,
            'status' => SettlementStatus::class,
            'breakdown' => 'array',
            'amount_owed_ngwee' => MoneyCast::class,
            'settled_at' => 'datetime',
        ];
    }
}
