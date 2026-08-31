<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Lenco\LencoReference;
use App\Enums\FeeBearer;
use App\Enums\PaymentChannel;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentGatewayException;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\Member;
use App\Models\PaymentIntent;
use App\Models\PayoutDestination;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Creates payments and moves them through their states.
 *
 * The intent is written down before the provider is called, always. If the process
 * dies between the two there is a Draft row with a reference on it, and the poller can
 * ask the provider what became of it — whereas a call made first and recorded second
 * can move money nobody has a record of.
 *
 * Nothing here touches a ledger. Settled money reaches the group's books through
 * PaymentPoster and nowhere else.
 */
class PaymentIntentService
{
    public function __construct(protected PaymentGateway $gateway) {}

    /**
     * Writes down what we are about to try, and mints its reference.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(
        Cycle $cycle,
        PaymentPurpose $purpose,
        int $amountNgwee,
        PaymentChannel $channel,
        ?Member $member = null,
        ?Model $payable = null,
        ?CycleMonth $month = null,
        ?PayoutDestination $destination = null,
        ?Member $requestedBy = null,
        array $attributes = [],
    ): PaymentIntent {
        return DB::transaction(function () use (
            $cycle, $purpose, $amountNgwee, $channel, $member, $payable, $month, $destination, $requestedBy, $attributes
        ): PaymentIntent {
            $intent = new PaymentIntent($attributes + [
                'cycle_id' => $cycle->id,
                'member_id' => $member?->id,
                'cycle_month_id' => $month?->id,
                'direction' => $purpose->direction(),
                'purpose' => $purpose,
                'channel' => $channel,
                'amount_ngwee' => $amountNgwee,
                'fee_bearer' => $purpose->direction()->isCollection()
                    ? FeeBearer::from((string) config('payments.collections.bearer', 'customer'))
                    : FeeBearer::Merchant,
                'status' => PaymentStatus::Draft,
                'payout_destination_id' => $destination?->id,
                'requested_by_member_id' => $requestedBy?->id,
                'attempt' => 1,

                /* Replaced below; the reference is derived from the id. */
                'reference' => 'pending-'.bin2hex(random_bytes(8)),
            ]);

            if ($payable !== null) {
                $intent->payable()->associate($payable);
            }

            $intent->save();

            $intent->forceFill(['reference' => LencoReference::for($intent)])->save();

            return $intent->refresh();
        });
    }

    /**
     * Hands a drafted collection to the provider.
     *
     * A refusal is recorded on the intent before it is re-thrown: the group needs to
     * be able to see what was tried and why it did not go, not just that a screen
     * showed an error once.
     */
    public function sendCollection(PaymentIntent $intent, CollectionRequest $request): PaymentIntent
    {
        return $this->send($intent, fn (): PaymentResult => $this->gateway->collect($request));
    }

    public function sendTransfer(PaymentIntent $intent, TransferRequest $request): PaymentIntent
    {
        return $this->send($intent, fn (): PaymentResult => $this->gateway->transfer($request));
    }

    /** Asks the provider where a payment got to and records the answer. */
    public function refresh(PaymentIntent $intent): PaymentIntent
    {
        $result = $intent->isCollection()
            ? $this->gateway->collectionStatus($intent->reference)
            : $this->gateway->transferStatus($intent->reference);

        $intent->forceFill([
            'last_polled_at' => Carbon::now(),
            'poll_attempts' => $intent->poll_attempts + 1,
        ])->save();

        $this->apply($intent, $result);

        return $intent;
    }

    /**
     * Records what the provider said.
     *
     * Returns false when the news is stale — a webhook and a poll race each other on
     * every payment, and a Successful arriving after a Posted must change nothing.
     *
     * `initiated_at` is stamped once and never moved: it is when the request went out,
     * and it is what the give-up window is measured from. A provider that answers a
     * status query with a fresh timestamp would otherwise push the clock forward on
     * every poll, and a prompt nobody ever approves would never be allowed to die.
     */
    public function apply(PaymentIntent $intent, PaymentResult $result): bool
    {
        $advanced = $this->transition($intent, $result->status);

        $intent->forceFill(array_filter([
            'provider_id' => $result->providerId,
            'provider_reference' => $result->providerReference,
            'fee_ngwee' => $result->feeNgwee,
            'fee_bearer' => $result->feeBearer,
            'initiated_at' => $intent->initiated_at ?? $result->initiatedAt,
            'completed_at' => $result->completedAt,
            'settled_at' => $result->settledAt,
            'status_reason' => $result->reasonForFailure,
            'payload' => $result->raw === [] ? null : $result->raw,
        ], fn (mixed $value): bool => $value !== null))->save();

        return $advanced;
    }

    /** Moves the status on, or leaves it alone when the move is not a legal one. */
    public function transition(PaymentIntent $intent, PaymentStatus $next): bool
    {
        if ($intent->status === $next) {
            return false;
        }

        if (! $intent->status->canTransitionTo($next)) {
            Log::info('Payment status ignored as out of order', [
                'payment_intent_id' => $intent->id,
                'from' => $intent->status->value,
                'to' => $next->value,
            ]);

            return false;
        }

        $intent->forceFill(['status' => $next])->save();

        return true;
    }

    /**
     * The money moved and the ledger would not take it.
     *
     * Parked for a person to look at. Never retried on its own — the answer is a
     * refund, a reclassification or a hold, and none of those are a machine's to pick.
     */
    public function markNeedsAttention(PaymentIntent $intent, string $reason): void
    {
        $intent->forceFill([
            'status' => PaymentStatus::NeedsAttention,
            'status_reason' => $reason,
        ])->save();
    }

    /**
     * A drafted payment the member never went through with.
     *
     * Only ever a Draft: nothing was sent to the provider, so nothing can have moved,
     * and leaving the row standing would block the next attempt at the same thing —
     * a member who opens the card page and closes it must not be locked out of paying.
     */
    public function abandonDraft(PaymentIntent $intent, string $reason): bool
    {
        if ($intent->status !== PaymentStatus::Draft) {
            return false;
        }

        $intent->forceFill([
            'status' => PaymentStatus::Abandoned,
            'status_reason' => $reason,
        ])->save();

        return true;
    }

    /**
     * Gives up on a collection nobody answered in time.
     *
     * An unapproved handset prompt never comes back as a refusal — it simply goes
     * unanswered — so something has to declare it dead, or the member is locked out of
     * paying by an attempt that will never conclude. Only past `PaymentIntent::hasStalled()`,
     * and only for collections: a transfer whose outcome is unknown may have moved the
     * group's money and is escalated instead.
     */
    public function abandonStalled(PaymentIntent $intent, ?string $reason = null): bool
    {
        if (! $intent->hasStalled()) {
            return false;
        }

        $intent->forceFill([
            'status' => PaymentStatus::Abandoned,
            'status_reason' => $reason ?? $intent->status_reason ?? 'Nobody approved this payment in time.',
        ])->save();

        return true;
    }

    /**
     * The payment standing against a member's once-per-cycle dues, if there is one.
     *
     * A contribution paid once for the whole cycle has no payable row to hang the
     * question on, so the purpose and the cycle are what identify it. Failed and
     * abandoned attempts are not standing — nothing moved, and the member is free to
     * try again.
     */
    public function standingFor(Member $member, PaymentPurpose $purpose, Cycle $cycle): ?PaymentIntent
    {
        return PaymentIntent::query()
            ->forCycle($cycle)
            ->where('member_id', $member->id)
            ->where('purpose', $purpose->value)
            ->whereNotIn('status', [PaymentStatus::Failed->value, PaymentStatus::Abandoned->value])
            ->latest('id')
            ->first();
    }

    /** Ties the payment to the ledger row it produced. Idempotent by unique index. */
    public function markPosted(PaymentIntent $intent, Model $transaction): void
    {
        $intent->postedTransaction()->associate($transaction);

        $intent->forceFill([
            'status' => PaymentStatus::Posted,
            'posted_at' => Carbon::now(),
        ])->save();
    }

    /**
     * A fresh attempt at the same thing.
     *
     * Deliberately a new row with a new reference: the provider rejects a reference it
     * has already seen, and reusing one would also collapse two attempts into a single
     * history the group could not read back.
     */
    public function retry(PaymentIntent $intent, ?Member $requestedBy = null): PaymentIntent
    {
        return DB::transaction(function () use ($intent, $requestedBy): PaymentIntent {
            $retry = $intent->replicate([
                'reference', 'provider_id', 'provider_reference', 'status', 'status_reason',
                'fee_ngwee', 'initiated_at', 'completed_at', 'settled_at', 'posted_at',
                'posted_transaction_type', 'posted_transaction_id', 'last_polled_at',
                'poll_attempts', 'payload',
            ]);

            $retry->forceFill([
                'status' => PaymentStatus::Draft,
                'attempt' => $intent->attempt + 1,
                'retry_of_payment_intent_id' => $intent->id,
                'requested_by_member_id' => $requestedBy->id ?? $intent->requested_by_member_id,
                'poll_attempts' => 0,
                'reference' => 'pending-'.bin2hex(random_bytes(8)),
            ])->save();

            $retry->forceFill(['reference' => LencoReference::for($retry)])->save();

            return $retry->refresh();
        });
    }

    /** @param callable(): PaymentResult $call */
    protected function send(PaymentIntent $intent, callable $call): PaymentIntent
    {
        if ($intent->status !== PaymentStatus::Draft) {
            throw new PaymentGatewayException(
                'This payment has already been sent to the provider; start a new attempt instead.'
            );
        }

        try {
            $result = $call();
        } catch (PaymentGatewayException $exception) {
            /*
             * A refusal is a fact: nothing moved, and the intent is closed as Failed so
             * the member is free to try again. A request that timed out is not — the
             * provider may have taken it and put a prompt on the handset, and only the
             * answer was lost. That one is left in flight for the poller to resolve
             * against the provider, because Failed would let a second prompt go out
             * against a live one and take the money twice.
             */
            $intent->forceFill([
                'status' => $exception->outcomeUnknown ? PaymentStatus::Pending : PaymentStatus::Failed,
                'status_reason' => $exception->reason(),
                'initiated_at' => $intent->initiated_at ?? Carbon::now(),
            ])->save();

            throw $exception;
        }

        $intent->forceFill(['initiated_at' => $intent->initiated_at ?? Carbon::now()])->save();

        $this->apply($intent, $result);

        return $intent->refresh();
    }
}
