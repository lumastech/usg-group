<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\CollateralClaimStatus;
use Brick\Money\Money;
use Database\Factories\CollateralClaimFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The itemised claim raised against a defaulting member's household goods.
 *
 * @property int $id
 * @property int $loan_id
 * @property CollateralClaimStatus $status
 * @property int|null $prepared_by_member_id
 * @property int|null $second_signer_member_id
 * @property array<int, array{description: string, estimated_value_ngwee: int}> $items
 * @property Money $claimed_value_ngwee
 * @property Money $outstanding_at_claim_ngwee
 * @property Carbon|null $signed_off_at
 * @property Carbon|null $enforced_at
 * @property Carbon|null $released_at
 */
#[Fillable([
    'loan_id', 'status', 'prepared_by_member_id', 'second_signer_member_id', 'items',
    'claimed_value_ngwee', 'outstanding_at_claim_ngwee', 'signed_off_at', 'enforced_at',
    'released_at', 'note',
])]
class CollateralClaim extends Model
{
    /** @use HasFactory<CollateralClaimFactory> */
    use HasFactory, LogsActivity;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => CollateralClaimStatus::Draft->value,
        'claimed_value_ngwee' => 0,
        'outstanding_at_claim_ngwee' => 0,
    ];

    /** @return BelongsTo<Loan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'prepared_by_member_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function secondSigner(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'second_signer_member_id');
    }

    /** Whether the pledged goods cover what is still owed. */
    public function coversOutstanding(): bool
    {
        return $this->getRawOriginal('claimed_value_ngwee') >= $this->getRawOriginal('outstanding_at_claim_ngwee');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CollateralClaimStatus::class,
            'items' => 'array',
            'claimed_value_ngwee' => MoneyCast::class,
            'outstanding_at_claim_ngwee' => MoneyCast::class,
            'signed_off_at' => 'datetime',
            'enforced_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }
}
