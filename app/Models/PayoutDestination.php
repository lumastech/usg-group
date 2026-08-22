<?php

namespace App\Models;

use App\Enums\MobileMoneyOperator;
use App\Enums\PayoutDestinationType;
use App\Policies\PayoutDestinationPolicy;
use Carbon\CarbonInterface;
use Database\Factories\PayoutDestinationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Where a member has asked to be paid.
 *
 * A member may keep a bank account and a mobile money wallet and switch between them;
 * whichever is default at the moment a payout is sent is the one that is used. Changing
 * this row changes where money goes, which makes it the most attractive thing in the
 * system to tamper with — every write is activity-logged, every destination is checked
 * against the provider's record of the account holder's name, and one changed on the
 * eve of a payout needs a second committee signature before it can be paid to.
 *
 * @property int $id
 * @property int $member_id
 * @property PayoutDestinationType $type
 * @property string|null $bank_id
 * @property string|null $bank_name
 * @property string|null $account_number
 * @property string|null $phone
 * @property MobileMoneyOperator|null $operator
 * @property string|null $resolved_account_name
 * @property int|null $name_match_score
 * @property int|null $name_match_confirmed_by_member_id
 * @property Carbon|null $name_match_confirmed_at
 * @property string|null $provider_recipient_id
 * @property Carbon|null $verified_at
 * @property bool $is_default
 * @property Carbon|null $disabled_at
 * @property int|null $created_by_member_id
 * @property string $fingerprint
 */
#[Fillable([
    'member_id', 'type', 'bank_id', 'bank_name', 'account_number', 'phone', 'operator',
    'resolved_account_name', 'name_match_score', 'name_match_confirmed_by_member_id',
    'name_match_confirmed_at', 'provider_recipient_id', 'verified_at', 'is_default',
    'disabled_at', 'created_by_member_id', 'fingerprint',
])]
#[UsePolicy(PayoutDestinationPolicy::class)]
class PayoutDestination extends Model
{
    /** @use HasFactory<PayoutDestinationFactory> */
    use HasFactory, LogsActivity;

    /**
     * Keeps the fingerprint in step with the account it identifies.
     *
     * The unique index is on the hash rather than on the columns because MySQL treats
     * NULLs as distinct — a wallet added twice would slip past a plain composite index
     * on the strength of its empty bank columns.
     */
    protected static function booted(): void
    {
        static::saving(function (self $destination): void {
            $destination->fingerprint = $destination->computeFingerprint();
        });
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function nameMatchConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'name_match_confirmed_by_member_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'created_by_member_id');
    }

    /** @param Builder<static> $query */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('disabled_at')->whereNotNull('verified_at');
    }

    /** Whether this destination may be sent money right now. */
    public function isUsable(): bool
    {
        return $this->disabled_at === null
            && ($this->verified_at !== null || ! config('payments.transfers.require_verified_destination'));
    }

    /**
     * Whether the account holder's name came back looking like somebody else's.
     *
     * Unconfirmed mismatches are shown in red beside the amount on the confirm dialog.
     * They do not block: a member paid into a spouse's wallet is ordinary here.
     */
    public function hasUnconfirmedNameMismatch(): bool
    {
        return $this->name_match_score !== null
            && $this->name_match_score < 80
            && $this->name_match_confirmed_at === null;
    }

    /** Whether the destination was added or changed inside the cooling-off window. */
    public function isWithinCoolingOff(?CarbonInterface $asOf = null): bool
    {
        $hours = (int) config('payments.transfers.destination_cooling_off_hours');

        if ($hours <= 0) {
            return false;
        }

        return $this->updated_at?->greaterThan(($asOf ?? Carbon::now())->subHours($hours)) ?? false;
    }

    /** How the destination reads on a voucher: "Airtel 0977…571", "Absa …4321". */
    public function label(): string
    {
        return match ($this->type) {
            PayoutDestinationType::MobileMoney => trim(
                ($this->operator?->label() ?? 'Mobile money').' '.$this->maskedIdentifier()
            ),
            PayoutDestinationType::BankAccount => trim(
                ($this->bank_name ?? 'Bank').' '.$this->maskedIdentifier()
            ),
        };
    }

    /** The account number or phone with everything but the last four digits hidden. */
    public function maskedIdentifier(): string
    {
        $identifier = $this->type === PayoutDestinationType::MobileMoney
            ? (string) $this->phone
            : (string) $this->account_number;

        if (mb_strlen($identifier) <= 4) {
            return $identifier;
        }

        return '…'.mb_substr($identifier, -4);
    }

    /** The identity of the account, hashed, so the unique index bites through nulls. */
    public function computeFingerprint(): string
    {
        return sha1(implode('|', [
            $this->type->value,
            mb_strtolower((string) $this->bank_id),
            preg_replace('/\D/', '', (string) $this->account_number) ?? '',
            preg_replace('/\D/', '', (string) $this->phone) ?? '',
            $this->operator->value ?? '',
        ]));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => PayoutDestinationType::class,
            'operator' => MobileMoneyOperator::class,
            'name_match_confirmed_at' => 'datetime',
            'verified_at' => 'datetime',
            'disabled_at' => 'datetime',
            'is_default' => 'boolean',
        ];
    }
}
