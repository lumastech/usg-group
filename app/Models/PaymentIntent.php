<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\FeeBearer;
use App\Enums\PaymentChannel;
use App\Enums\PaymentDirection;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToCycle;
use App\Policies\PaymentIntentPolicy;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Database\Factories\PaymentIntentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One attempt to move money through the payment gateway, in either direction.
 *
 * This is not a ledger. It records what the provider was asked to do and what it said
 * happened; the group's book of record is still the savings, loan and social fund
 * ledgers, and a payment only reaches them through PaymentPoster. An intent may
 * therefore sit at Successful for a while — money moved, the book does not say so yet —
 * and that window is precisely what the poller and the reconciliation report exist for.
 *
 * @property int $id
 * @property int $cycle_id
 * @property int|null $member_id
 * @property int|null $cycle_month_id
 * @property PaymentDirection $direction
 * @property PaymentPurpose $purpose
 * @property PaymentChannel $channel
 * @property Money $amount_ngwee
 * @property Money|null $fee_ngwee
 * @property FeeBearer|null $fee_bearer
 * @property string $reference
 * @property string|null $provider_id
 * @property string|null $provider_reference
 * @property PaymentStatus $status
 * @property string|null $status_reason
 * @property string|null $payable_type
 * @property int|null $payable_id
 * @property int|null $payout_destination_id
 * @property Carbon|null $initiated_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $settled_at
 * @property Carbon|null $posted_at
 * @property string|null $posted_transaction_type
 * @property int|null $posted_transaction_id
 * @property int|null $requested_by_member_id
 * @property int|null $approved_by_member_id
 * @property int|null $second_approver_member_id
 * @property int|null $retry_of_payment_intent_id
 * @property int $attempt
 * @property Carbon|null $last_polled_at
 * @property int $poll_attempts
 * @property array<string, mixed>|null $payload
 */
#[Fillable([
    'cycle_id', 'member_id', 'cycle_month_id', 'direction', 'purpose', 'channel',
    'amount_ngwee', 'fee_ngwee', 'fee_bearer', 'reference', 'provider_id',
    'provider_reference', 'status', 'status_reason', 'payable_type', 'payable_id',
    'payout_destination_id', 'initiated_at', 'completed_at', 'settled_at', 'posted_at',
    'posted_transaction_type', 'posted_transaction_id', 'requested_by_member_id',
    'approved_by_member_id', 'second_approver_member_id', 'retry_of_payment_intent_id',
    'attempt', 'last_polled_at', 'poll_attempts', 'payload',
])]
#[UsePolicy(PaymentIntentPolicy::class)]
class PaymentIntent extends Model
{
    /** @use HasFactory<PaymentIntentFactory> */
    use BelongsToCycle, HasFactory, LogsActivity;

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<CycleMonth, $this> */
    public function cycleMonth(): BelongsTo
    {
        return $this->belongsTo(CycleMonth::class);
    }

    /** @return BelongsTo<PayoutDestination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(PayoutDestination::class, 'payout_destination_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'requested_by_member_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'approved_by_member_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function secondApprover(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'second_approver_member_id');
    }

    /** @return BelongsTo<self, $this> */
    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_payment_intent_id');
    }

    /**
     * What the money is for: a Loan, a Payout, a claim, a trading entry.
     *
     * @return MorphTo<Model, $this>
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The ledger row this payment produced, once it has produced one.
     *
     * @return MorphTo<Model, $this>
     */
    public function postedTransaction(): MorphTo
    {
        return $this->morphTo();
    }

    /** @param Builder<static> $query */
    public function scopeCollections(Builder $query): void
    {
        $query->where('direction', PaymentDirection::Collection->value);
    }

    /** @param Builder<static> $query */
    public function scopeTransfers(Builder $query): void
    {
        $query->where('direction', PaymentDirection::Transfer->value);
    }

    /** @param Builder<static> $query */
    public function scopeAwaitingOutcome(Builder $query): void
    {
        $query->whereIn('status', [
            PaymentStatus::Pending->value,
            PaymentStatus::AwaitingAuthorization->value,
        ]);
    }

    /** @param Builder<static> $query */
    public function scopeNeedsAttention(Builder $query): void
    {
        $query->where('status', PaymentStatus::NeedsAttention->value);
    }

    /**
     * Money the provider says moved but the ledgers have not yet taken.
     *
     * @param  Builder<static>  $query
     */
    public function scopeUnposted(Builder $query): void
    {
        $query->whereIn('status', [PaymentStatus::Successful->value, PaymentStatus::Settled->value]);
    }

    public function isCollection(): bool
    {
        return $this->direction->isCollection();
    }

    public function isTransfer(): bool
    {
        return $this->direction->isTransfer();
    }

    /**
     * A collection nobody ever answered.
     *
     * Mobile money is authorised on a handset, so a prompt that is not approved simply
     * stops existing for the member — no refusal ever comes back, and the intent would
     * otherwise sit in flight forever, blocking the next attempt at the same thing.
     * Past the give-up window it is treated as gone, which is the same cutoff
     * `unity:poll-payments` uses; the rule lives here so the poller, the member's own
     * screen and the next attempt cannot disagree about when a prompt is dead.
     *
     * Never true of a transfer: money may have left the group's account, and giving up
     * on that is a person's job, not a clock's.
     */
    public function hasStalled(): bool
    {
        if (! $this->isCollection() || ! $this->status->isInFlight()) {
            return false;
        }

        if ($this->wasNeverSent()) {
            return true;
        }

        $started = $this->initiated_at ?? $this->created_at;

        return $started !== null && $started->lessThan(Carbon::now()->subMinutes(
            (int) config('payments.collections.poll.give_up_after_minutes', 60)
        ));
    }

    /**
     * Written down, but never actually handed to the provider.
     *
     * A push that died between `create()` and `sendCollection()` leaves a Draft with no
     * `initiated_at`: the request never left this application, so no money can have
     * moved and the member should not wait out the whole give-up window for a prompt
     * that was never sent. The grace is the poll interval, comfortably longer than the
     * gateway's own timeout and retries — without it, a double-tapped button could
     * abandon an attempt whose call is still in flight, and charge the member twice.
     *
     * A card draft is deliberately not this: the member may be inside the provider's
     * hosted page with it open right now, and that one waits out the full window.
     */
    protected function wasNeverSent(): bool
    {
        if ($this->status !== PaymentStatus::Draft
            || $this->channel === PaymentChannel::Card
            || $this->initiated_at !== null) {
            return false;
        }

        return $this->created_at?->lessThan(Carbon::now()->subMinutes(
            (int) config('payments.collections.poll.every_minutes', 5)
        )) === true;
    }

    /** Whether the ledgers already have this money. */
    public function isPosted(): bool
    {
        return $this->posted_transaction_id !== null;
    }

    /**
     * The date the money actually moved, for anything that is date-sensitive.
     *
     * Always the provider's timestamp where there is one. A repayment made at 23:50 on
     * the 7th and processed by us on the 8th is allocated on the 7th, so the member is
     * not charged a late penalty for the depth of our queue.
     */
    public function effectiveDate(): CarbonInterface
    {
        return $this->completed_at ?? $this->initiated_at ?? $this->created_at ?? Carbon::now();
    }

    /** What the member is out of pocket: the amount, plus their own fee if they bear it. */
    public function memberCostNgwee(): int
    {
        $fee = $this->fee_bearer === FeeBearer::Customer && $this->fee_ngwee !== null
            ? $this->fee_ngwee->getMinorAmount()->toInt()
            : 0;

        return $this->amount_ngwee->getMinorAmount()->toInt() + $fee;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'status_reason', 'amount_ngwee', 'fee_ngwee', 'provider_id', 'provider_reference'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'direction' => PaymentDirection::class,
            'purpose' => PaymentPurpose::class,
            'channel' => PaymentChannel::class,
            'status' => PaymentStatus::class,
            'fee_bearer' => FeeBearer::class,
            'amount_ngwee' => MoneyCast::class,
            'fee_ngwee' => MoneyCast::class,
            'initiated_at' => 'datetime',
            'completed_at' => 'datetime',
            'settled_at' => 'datetime',
            'posted_at' => 'datetime',
            'last_polled_at' => 'datetime',
            'payload' => 'array',
        ];
    }
}
