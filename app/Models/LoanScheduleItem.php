<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\LoanScheduleItemStatus;
use Brick\Money\Money;
use Database\Factories\LoanScheduleItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One month of a loan's repayment schedule.
 *
 * The `original_*` columns are the schedule as issued; the plain columns are what is
 * expected now. Interest runs on the reducing balance, so paying a month late or short
 * moves every later installment — the member keeps the original figures to compare to.
 *
 * @property int $id
 * @property int $loan_id
 * @property int $cycle_month_id
 * @property int $sequence
 * @property Carbon $due_month
 * @property Carbon $due_on
 * @property Money $original_amount_due_ngwee
 * @property Money $principal_due_ngwee
 * @property Money $interest_due_ngwee
 * @property Money $amount_due_ngwee
 * @property Money $amount_paid_ngwee
 * @property Carbon|null $paid_at
 * @property LoanScheduleItemStatus $status
 */
#[Fillable([
    'loan_id', 'cycle_month_id', 'sequence', 'due_month', 'due_on',
    'original_principal_ngwee', 'original_interest_ngwee', 'original_amount_due_ngwee',
    'principal_due_ngwee', 'interest_due_ngwee', 'amount_due_ngwee', 'amount_paid_ngwee',
    'paid_at', 'status',
])]
class LoanScheduleItem extends Model
{
    /** @use HasFactory<LoanScheduleItemFactory> */
    use HasFactory;

    /** @return BelongsTo<Loan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** @return BelongsTo<CycleMonth, $this> */
    public function cycleMonth(): BelongsTo
    {
        return $this->belongsTo(CycleMonth::class);
    }

    public function outstandingNgwee(): int
    {
        return max(0, $this->getRawOriginal('amount_due_ngwee') - $this->getRawOriginal('amount_paid_ngwee'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'due_month' => 'date',
            'due_on' => 'date',
            'original_principal_ngwee' => MoneyCast::class,
            'original_interest_ngwee' => MoneyCast::class,
            'original_amount_due_ngwee' => MoneyCast::class,
            'principal_due_ngwee' => MoneyCast::class,
            'interest_due_ngwee' => MoneyCast::class,
            'amount_due_ngwee' => MoneyCast::class,
            'amount_paid_ngwee' => MoneyCast::class,
            'paid_at' => 'datetime',
            'status' => LoanScheduleItemStatus::class,
        ];
    }
}
