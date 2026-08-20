<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\LoanTransactionType;
use App\Exceptions\ImmutableLedgerException;
use Brick\Money\Money;
use Database\Factories\LoanTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A single movement on a loan's ledger.
 *
 * Append-only, exactly like the savings ledger: a charge that was posted in error is
 * corrected with a reversing entry, never by editing the line that recorded it.
 *
 * @property int $id
 * @property int $loan_id
 * @property int|null $cycle_month_id
 * @property LoanTransactionType $type
 * @property Money $amount_ngwee
 * @property Carbon $occurred_on
 * @property Money $balance_after_ngwee
 * @property Money $principal_portion_ngwee
 * @property Money $interest_portion_ngwee
 * @property Money $penalty_portion_ngwee
 * @property string|null $notes
 */
#[Fillable([
    'loan_id', 'cycle_month_id', 'recorded_by_member_id', 'type', 'amount_ngwee',
    'occurred_on', 'balance_after_ngwee', 'principal_portion_ngwee',
    'interest_portion_ngwee', 'penalty_portion_ngwee', 'notes',
])]
class LoanTransaction extends Model
{
    /** @use HasFactory<LoanTransactionFactory> */
    use HasFactory, LogsActivity;

    /** @var array<string, mixed> */
    protected $attributes = [
        'principal_portion_ngwee' => 0,
        'interest_portion_ngwee' => 0,
        'penalty_portion_ngwee' => 0,
    ];

    /** The loan ledger is append-only; corrections are posted as reversing entries. */
    protected static function booted(): void
    {
        static::updating(function (self $transaction): void {
            throw new ImmutableLedgerException(
                'Loan ledger entries cannot be edited. Post a reversing entry instead.'
            );
        });

        static::deleting(function (self $transaction): void {
            throw new ImmutableLedgerException(
                'Loan ledger entries cannot be deleted. Post a reversing entry instead.'
            );
        });
    }

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

    /** @return BelongsTo<Member, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'recorded_by_member_id');
    }

    /** The entry's effect on the balance: positive when it adds to what is owed. */
    public function signedAmountNgwee(): int
    {
        return $this->getRawOriginal('amount_ngwee') * $this->type->signedFactor();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => LoanTransactionType::class,
            'amount_ngwee' => MoneyCast::class,
            'balance_after_ngwee' => MoneyCast::class,
            'principal_portion_ngwee' => MoneyCast::class,
            'interest_portion_ngwee' => MoneyCast::class,
            'penalty_portion_ngwee' => MoneyCast::class,
            'occurred_on' => 'date',
        ];
    }
}
