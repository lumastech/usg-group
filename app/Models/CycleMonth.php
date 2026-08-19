<?php

namespace App\Models;

use App\Enums\CycleMonthStatus;
use App\Enums\InterestAllocationMethod;
use Database\Factories\CycleMonthFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One month of a cycle, with its declaration window and trading dates pre-resolved.
 *
 * @property int $id
 * @property int $cycle_id
 * @property int $sequence
 * @property Carbon $month
 * @property Carbon $declarations_open_at
 * @property Carbon $declarations_close_at
 * @property Carbon $trading_starts_on
 * @property Carbon $trading_concludes_on
 * @property Carbon $disbursement_on
 * @property InterestAllocationMethod $interest_allocation_method
 * @property CycleMonthStatus $status
 */
#[Fillable([
    'cycle_id', 'sequence', 'month', 'declarations_open_at', 'declarations_close_at',
    'trading_starts_on', 'trading_concludes_on', 'disbursement_on',
    'interest_allocation_method', 'status',
])]
class CycleMonth extends Model
{
    /** @use HasFactory<CycleMonthFactory> */
    use HasFactory;

    /** @return BelongsTo<Cycle, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    /** Declarations are only accepted between the 1st at 08:00 and the end of the 3rd. */
    public function declarationsOpenAt(?Carbon $at = null): bool
    {
        $at ??= Carbon::now();

        return $at->betweenIncluded($this->declarations_open_at, $this->declarations_close_at);
    }

    public function isLate(?Carbon $at = null): bool
    {
        return ($at ?? Carbon::now())->greaterThan($this->declarations_close_at);
    }

    public function label(): string
    {
        return $this->month->format('F Y');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'declarations_open_at' => 'datetime',
            'declarations_close_at' => 'datetime',
            'trading_starts_on' => 'date',
            'trading_concludes_on' => 'date',
            'disbursement_on' => 'date',
            'interest_allocation_method' => InterestAllocationMethod::class,
            'status' => CycleMonthStatus::class,
        ];
    }
}
