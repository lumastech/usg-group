<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\ExpulsionGround;
use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Enums\NotificationChannel;
use App\Models\Concerns\BelongsToCycle;
use Brick\Money\Money;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
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
 * @property NotificationChannel $notification_channel
 * @property bool $is_diaspora
 * @property MemberStatus $status
 * @property Carbon|null $status_effective_on
 * @property Carbon|null $status_changed_at
 * @property string|null $status_reason
 * @property ExpulsionGround|null $expulsion_ground
 * @property Carbon|null $date_of_death
 * @property Carbon|null $ledgers_frozen_at
 * @property Carbon $joined_on
 * @property int $joining_month_sequence
 * @property Money $joining_fee_ngwee
 * @property bool $joining_fee_paid
 */
#[Fillable([
    'cycle_id', 'user_id', 'member_number', 'full_name', 'nrc_number', 'physical_address',
    'phone', 'notification_channel', 'is_diaspora', 'status', 'status_effective_on', 'status_changed_at',
    'status_reason', 'expulsion_ground', 'date_of_death', 'ledgers_frozen_at', 'joined_on',
    'joining_month_sequence', 'joining_fee_ngwee', 'joining_fee_paid',
])]
class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use BelongsToCycle, HasFactory, LogsActivity, Notifiable;

    /**
     * Defaults mirrored from the migration.
     *
     * Eloquent does not know a database default, so without this a member created in
     * this request reads back a null channel until it is refreshed.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'notification_channel' => NotificationChannel::Mail->value,
    ];

    /** @return HasMany<SavingsTransaction, $this> */
    public function savingsTransactions(): HasMany
    {
        return $this->hasMany(SavingsTransaction::class);
    }

    /** @return HasMany<Loan, $this> */
    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class)->orderByDesc('requested_at');
    }

    /** @return HasMany<SocialFundTransaction, $this> */
    public function socialFundTransactions(): HasMany
    {
        return $this->hasMany(SocialFundTransaction::class);
    }

    /** @return HasMany<FuneralGrantClaim, $this> */
    public function funeralGrantClaims(): HasMany
    {
        return $this->hasMany(FuneralGrantClaim::class);
    }

    /** @return HasMany<UnityBabyClaim, $this> */
    public function unityBabyClaims(): HasMany
    {
        return $this->hasMany(UnityBabyClaim::class);
    }

    /** @return HasMany<NextOfKin, $this> */
    public function nextOfKin(): HasMany
    {
        return $this->hasMany(NextOfKin::class);
    }

    /** @return HasOne<Payout, $this> */
    public function payout(): HasOne
    {
        return $this->hasOne(Payout::class);
    }

    /** @return HasOne<MemberDebt, $this> */
    public function debt(): HasOne
    {
        return $this->hasOne(MemberDebt::class);
    }

    /** @return HasOne<NextOfKinRepaymentArrangement, $this> */
    public function repaymentArrangement(): HasOne
    {
        return $this->hasOne(NextOfKinRepaymentArrangement::class);
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

    /**
     * Whether this member's ledgers have been closed to further movement.
     *
     * Set when their payout is executed. From then on nothing may be posted against
     * them — the settlement was computed from those ledgers and must stay explicable.
     */
    public function ledgersFrozen(): bool
    {
        return $this->ledgers_frozen_at !== null;
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

    /** Whether a portal login has been attached to this member. */
    public function hasLogin(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * Every status change recorded against this member, oldest first.
     *
     * The timeline on the profile is read straight from the activity log rather
     * than a separate history table, so what is shown is the audit trail itself.
     *
     * @return Builder<Activity>
     */
    public function statusHistory(): Builder
    {
        return Activity::query()
            ->where('subject_type', self::class)
            ->where('subject_id', $this->id)
            ->orderBy('id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /**
     * Where this member's email goes.
     *
     * The address belongs to the portal login, not to the member record, so a member
     * the group has never invited has no mail route and is reached by SMS alone.
     */
    public function routeNotificationForMail(): ?string
    {
        return $this->user?->email;
    }

    /**
     * Where this member's texts go.
     *
     * The phone number is on the member record rather than the login, which is what
     * lets the group text somebody who has never signed in.
     */
    public function routeNotificationForSms(): ?string
    {
        return $this->phone;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'notification_channel' => NotificationChannel::class,
            'is_diaspora' => 'boolean',
            'status' => MemberStatus::class,
            'status_effective_on' => 'date',
            'status_changed_at' => 'datetime',
            'expulsion_ground' => ExpulsionGround::class,
            'date_of_death' => 'date',
            'ledgers_frozen_at' => 'datetime',
            'joined_on' => 'date',
            'joining_fee_ngwee' => MoneyCast::class,
            'joining_fee_paid' => 'boolean',
        ];
    }
}
