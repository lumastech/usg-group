<?php

namespace App\Policies;

use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\PaymentIntent;
use App\Models\User;

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
     */
    public function retry(User $user, PaymentIntent $intent): bool
    {
        return $user->can(Permission::PaymentsRetry->value)
            && ! $intent->status->hasSucceeded();
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
