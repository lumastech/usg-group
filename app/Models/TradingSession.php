<?php

namespace App\Models;

use App\Enums\TradingSessionStatus;
use App\Models\Concerns\BelongsToCycle;
use Database\Factories\TradingSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One month's trading day, from the moment declarations close to the moment the
 * treasurer concludes it.
 *
 * The session holds no money of its own. It is a worksheet: who was expected to bring
 * what, what actually arrived and when. Concluding it is the single act that turns all
 * of that into savings deposits, repayments, interest and penalties.
 *
 * @property int $id
 * @property int $cycle_id
 * @property int $cycle_month_id
 * @property Carbon $scheduled_conclude_date
 * @property TradingSessionStatus $status
 * @property int|null $concluded_by_member_id
 * @property Carbon|null $concluded_at
 */
#[Fillable([
    'cycle_id', 'cycle_month_id', 'scheduled_conclude_date', 'status',
    'concluded_by_member_id', 'concluded_at',
])]
class TradingSession extends Model
{
    /** @use HasFactory<TradingSessionFactory> */
    use BelongsToCycle, HasFactory, LogsActivity;

    /** @return BelongsTo<CycleMonth, $this> */
    public function cycleMonth(): BelongsTo
    {
        return $this->belongsTo(CycleMonth::class);
    }

    /** @return HasMany<TradingEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(TradingEntry::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function concludedBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'concluded_by_member_id');
    }

    public function isOpen(): bool
    {
        return $this->status === TradingSessionStatus::Open;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scheduled_conclude_date' => 'date',
            'status' => TradingSessionStatus::class,
            'concluded_at' => 'datetime',
        ];
    }
}
