<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\GrantClaimStatus;
use App\Enums\SocialFundTransactionType;
use App\Models\Concerns\BelongsToCycle;
use App\Models\Concerns\IsGrantClaim;
use Brick\Money\Money;
use Database\Factories\UnityBabyClaimFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A claim on the K500 grant for a child born to a member during the cycle.
 *
 * @property int $id
 * @property int $cycle_id
 * @property int $member_id
 * @property string|null $child_name
 * @property Carbon $born_on
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
    'cycle_id', 'member_id', 'child_name', 'born_on', 'claim_date', 'status',
    'amount_ngwee', 'first_approver_member_id', 'second_approver_member_id',
    'approved_at', 'paid_at', 'rejected_at', 'rejected_by_member_id', 'note',
])]
class UnityBabyClaim extends Model
{
    /** @use HasFactory<UnityBabyClaimFactory> */
    use BelongsToCycle, HasFactory, IsGrantClaim, LogsActivity;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => GrantClaimStatus::Submitted->value,
        'amount_ngwee' => 50_000,
    ];

    public function grantType(): SocialFundTransactionType
    {
        return SocialFundTransactionType::UnityBabyGrant;
    }

    public function subject(): string
    {
        return $this->child_name ?: 'Baby born '.$this->born_on->format('j M Y');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => GrantClaimStatus::class,
            'amount_ngwee' => MoneyCast::class,
            'born_on' => 'date',
            'claim_date' => 'date',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }
}
