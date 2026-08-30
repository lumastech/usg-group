<?php

namespace App\Models;

use App\Enums\CycleMonthStatus;
use App\Enums\InterestAllocationMethod;
use App\Models\Concerns\BelongsToCycle;
use Carbon\CarbonInterface;
use Database\Factories\CycleMonthFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

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
    use BelongsToCycle, HasFactory, LogsActivity;

    /** Declarations are only accepted between the 1st at 08:00 and the end of the 3rd. */
    public function declarationsOpenAt(?CarbonInterface $at = null): bool
    {
        $at ??= Carbon::now();

        return $at->betweenIncluded($this->declarations_open_at, $this->declarations_close_at);
    }

    public function isLate(?CarbonInterface $at = null): bool
    {
        return ($at ?? Carbon::now())->greaterThan($this->declarations_close_at);
    }

    public function label(): string
    {
        return $this->month->format('F Y');
    }

    /**
     * Moving a window is a constitutional change, so it is logged like one: the audit
     * portal has to be able to answer why a declaration was accepted on the 9th.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
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
