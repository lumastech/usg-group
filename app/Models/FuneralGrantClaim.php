<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\FuneralRelationship;
use App\Enums\GrantClaimStatus;
use App\Enums\SocialFundTransactionType;
use App\Models\Concerns\BelongsToCycle;
use App\Models\Concerns\IsGrantClaim;
use Brick\Money\Money;
use Database\Factories\FuneralGrantClaimFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A claim on the K1,000 funeral grant.
 *
 * The relationship column is cast to an enum with only three cases, so a claim for a
 * sibling, cousin or in-law cannot be stored, never mind approved.
 *
 * @property int $id
 * @property int $cycle_id
 * @property int $member_id
 * @property string $deceased_name
 * @property FuneralRelationship $relationship
 * @property Carbon $claim_date
 * @property GrantClaimStatus $status
 * @property Money $amount_ngwee
 * @property int|null $first_approver_member_id
 * @property int|null $second_approver_member_id
 * @property Carbon|null $approved_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $rejected_at
 * @property string|null $note
 */
#[Fillable([
    'cycle_id', 'member_id', 'deceased_name', 'relationship', 'claim_date', 'status',
    'amount_ngwee', 'first_approver_member_id', 'second_approver_member_id',
    'approved_at', 'paid_at', 'rejected_at', 'rejected_by_member_id', 'note',
])]
class FuneralGrantClaim extends Model
{
    /** @use HasFactory<FuneralGrantClaimFactory> */
    use BelongsToCycle, HasFactory, IsGrantClaim, LogsActivity;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => GrantClaimStatus::Submitted->value,
        'amount_ngwee' => 100_000,
    ];

    public function grantType(): SocialFundTransactionType
    {
        return SocialFundTransactionType::FuneralGrant;
    }

    public function subject(): string
    {
        return "{$this->deceased_name} ({$this->relationship->label()})";
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'relationship' => FuneralRelationship::class,
            'status' => GrantClaimStatus::class,
            'amount_ngwee' => MoneyCast::class,
            'claim_date' => 'date',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }
}
