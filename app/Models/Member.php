<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use Brick\Money\Money;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A member of the group for one cycle.
 *
 * @property int $id
 * @property int $cycle_id
 * @property int|null $user_id
 * @property int $member_number
 * @property string $full_name
 * @property string|null $nrc_number
 * @property string|null $physical_address
 * @property string|null $phone
 * @property string|null $next_of_kin_name
 * @property string|null $next_of_kin_phone
 * @property string|null $next_of_kin_relationship
 * @property bool $is_diaspora
 * @property MemberStatus $status
 * @property Carbon|null $status_effective_on
 * @property Carbon $joined_on
 * @property int $joining_month_sequence
 * @property Money $joining_fee_ngwee
 * @property bool $joining_fee_paid
 */
#[Fillable([
    'cycle_id', 'user_id', 'member_number', 'full_name', 'nrc_number', 'physical_address',
    'phone', 'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relationship',
    'is_diaspora', 'status', 'status_effective_on', 'joined_on', 'joining_month_sequence',
    'joining_fee_ngwee', 'joining_fee_paid',
])]
class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory, LogsActivity;

    /** @return BelongsTo<Cycle, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    /** @return HasMany<SavingsTransaction, $this> */
    public function savingsTransactions(): HasMany
    {
        return $this->hasMany(SavingsTransaction::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<Member>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', MemberStatus::Active);
    }

    public function hasRole(MemberRole $role): bool
    {
        return $this->user?->hasRole($role->value) ?? false;
    }

    /** Whether this member may stand as one of the two approvers on a sensitive action. */
    public function isCommitteeMember(): bool
    {
        foreach (MemberRole::committee() as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_diaspora' => 'boolean',
            'status' => MemberStatus::class,
            'status_effective_on' => 'date',
            'joined_on' => 'date',
            'joining_fee_ngwee' => MoneyCast::class,
            'joining_fee_paid' => 'boolean',
        ];
    }
}
