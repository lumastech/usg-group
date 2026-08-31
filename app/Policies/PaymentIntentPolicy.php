<?php

namespace App\Policies;

use App\Enums\MemberRole;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\PaymentIntent;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Who may watch the money move, and who may push it.
 *
 * Reading is deliberately wider than acting: the chair holds the treasury to account
 * and can see every payment, but initiating, retrying and reconciling belong to the
 * treasury. A member sees their own payments and nobody else's.
 */
class PaymentIntentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PaymentsView->value);
    }

    public function view(User $user, PaymentIntent $intent): bool
    {
        return $this->viewAny($user) || $intent->member?->user_id === $user->id;
    }

    /** Asking a member for money, or sending some out. */
    public function initiate(User $user): bool
    {
        return $user->can(Permission::PaymentsInitiate->value);
    }

    /**
     * A fresh attempt at a payment that did not go.
     *
     * Only from a state where nothing moved. A payment the provider says succeeded is
     * never retried — that would ask the member for the money twice.
     *
     * And only while the first attempt is still recent, because a retry is a live
     * prompt on somebody's handset: an hour later it no longer looks like the payment
     * they were in the middle of, and a member who approves a prompt they cannot place
     * is the whole shape of the fraud this system is built against. Past the window the
     * answer is a new payment, started by the person who owes it.
     */
    public function retry(User $user, PaymentIntent $intent): bool
    {
        return $user->can(Permission::PaymentsRetry->value)
            && ! $intent->status->hasSucceeded()
            && $this->withinRetryWindow($user, $intent);
    }

    /**
     * Whether the attempt is recent enough to be repeated.
     *
     * Measured from when the request went out, which is the same clock the give-up
     * window uses, so a prompt cannot be dead and retryable at once. An administrator
     * is exempt: resolving what nobody could resolve on the day is the job.
     */
    protected function withinRetryWindow(User $user, PaymentIntent $intent): bool
    {
        if ($user->hasRole(MemberRole::Admin->value)) {
            return true;
        }

        $started = $intent->initiated_at ?? $intent->created_at;

        return $started !== null && $started->greaterThan(
            Carbon::now()->subMinutes((int) config('payments.retry_within_minutes', 15))
        );
    }

    /** Asking the provider again what became of a payment. Harmless, so it is wide. */
    public function refresh(User $user, PaymentIntent $intent): bool
    {
        return $this->viewAny($user) && ! $intent->status->isTerminal();
    }

    /** Deciding what happens to money the ledgers refused. */
    public function resolve(User $user, PaymentIntent $intent): bool
    {
        return $user->can(Permission::PaymentsRetry->value)
            && $intent->status === PaymentStatus::NeedsAttention;
    }

    public function reconcile(User $user): bool
    {
        return $user->can(Permission::PaymentsReconcile->value);
    }
}
