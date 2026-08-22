<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\PayoutCase;
use App\Models\Concerns\BelongsToCycle;
use App\Policies\PayoutPolicy;
use Brick\Money\Money;
use Database\Factories\PayoutFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A member's settlement, as executed.
 *
 * The breakdown is stored, not recomputed: the ledgers behind it keep moving, and a
 * voucher must always read as it did on the day two committee members signed it.
 *
 * @property int $id
 * @property int $cycle_id
 * @property int $member_id
 * @property PayoutCase $case
 * @property array<int, array<string, mixed>> $breakdown
 * @property Money $net_value_ngwee
 * @property Money $round_off_ngwee
 * @property Money $amount_ngwee
 * @property Carbon|null $executed_at
 * @property int|null $executed_by_member_id
 * @property int|null $second_approver_member_id
 * @property Carbon|null $paid_at
 * @property string|null $paid_method
 * @property int|null $payment_intent_id
 * @property string|null $early_settlement_note
 * @property string|null $note
 */
#[Fillable([
    'cycle_id', 'member_id', 'case', 'breakdown', 'net_value_ngwee', 'round_off_ngwee',
    'amount_ngwee', 'executed_at', 'executed_by_member_id', 'second_approver_member_id',
    'paid_at', 'paid_method', 'payment_intent_id', 'early_settlement_note', 'note',
])]
#[UsePolicy(PayoutPolicy::class)]
class Payout extends Model
{
    /** @use HasFactory<PayoutFactory> */
    use BelongsToCycle, HasFactory, LogsActivity;

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * The transfer that put the money in the member's hands, if it was sent rather
     * than handed over.
     *
     * @return BelongsTo<PaymentIntent, $this>
     */
    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'executed_by_member_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function secondApprover(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'second_approver_member_id');
    }

    /** Whether this settlement was signed off before the cycle reached share-out. */
    public function wasSettledEarly(): bool
    {
        return filled($this->early_settlement_note);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** Whether the money has actually reached the member yet. */
    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'case' => PayoutCase::class,
            'breakdown' => 'array',
            'net_value_ngwee' => MoneyCast::class,
            'round_off_ngwee' => MoneyCast::class,
            'amount_ngwee' => MoneyCast::class,
            'executed_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }
}
