<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Brick\Money\Money;
use Database\Factories\TradingEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One member's line on the trading-day sheet.
 *
 * `expected_*` is what the declaration and the disbursement queue said should happen;
 * `actual_*` is what the treasurer marked as it happened at the table. The variance
 * between them, and the days a payment ran late, are derived here rather than typed,
 * so the sheet cannot disagree with its own arithmetic.
 *
 * @property int $id
 * @property int $trading_session_id
 * @property int $member_id
 * @property int|null $declaration_id
 * @property Money $expected_in_ngwee
 * @property Money $actual_in_ngwee
 * @property Carbon|null $received_at
 * @property Money $expected_out_ngwee
 * @property Money $actual_out_ngwee
 * @property Carbon|null $disbursed_at
 * @property Money $variance_ngwee
 * @property int $penalty_days
 * @property Money $savings_portion_ngwee
 * @property Money $repayment_portion_ngwee
 */
#[Fillable([
    'trading_session_id', 'member_id', 'declaration_id', 'expected_in_ngwee',
    'actual_in_ngwee', 'received_at', 'expected_out_ngwee', 'actual_out_ngwee',
    'disbursed_at', 'variance_ngwee', 'penalty_days', 'savings_portion_ngwee',
    'repayment_portion_ngwee',
])]
class TradingEntry extends Model
{
    /** @use HasFactory<TradingEntryFactory> */
    use HasFactory;

    /** @return BelongsTo<TradingSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TradingSession::class, 'trading_session_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<Declaration, $this> */
    public function declaration(): BelongsTo
    {
        return $this->belongsTo(Declaration::class);
    }

    public function isReceived(): bool
    {
        return $this->received_at !== null;
    }

    public function isDisbursed(): bool
    {
        return $this->disbursed_at !== null;
    }

    /** Actual money in, less what was expected. Negative means the member fell short. */
    public function varianceNgwee(): int
    {
        return $this->getRawOriginal('actual_in_ngwee') - $this->getRawOriginal('expected_in_ngwee');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expected_in_ngwee' => MoneyCast::class,
            'actual_in_ngwee' => MoneyCast::class,
            'expected_out_ngwee' => MoneyCast::class,
            'actual_out_ngwee' => MoneyCast::class,
            'variance_ngwee' => MoneyCast::class,
            'savings_portion_ngwee' => MoneyCast::class,
            'repayment_portion_ngwee' => MoneyCast::class,
            'received_at' => 'datetime',
            'disbursed_at' => 'datetime',
        ];
    }
}
