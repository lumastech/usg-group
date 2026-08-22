<?php

namespace App\Enums;

/**
 * Where one payment has got to.
 *
 * This is the provider's view of the money plus one state of our own. `Posted` is the
 * only state that means the group's ledgers have the money; everything before it means
 * cash may have moved while the book does not yet say so, which is exactly the window
 * the poller and the reconciliation report exist to close.
 */
enum PaymentStatus: string
{
    /** Created on our side; nothing has been sent to the provider yet. */
    case Draft = 'draft';

    /** Accepted by the provider, no outcome yet. */
    case Pending = 'pending';

    /** Waiting on a human: a handset prompt, an OTP, or a 3-D Secure page. */
    case AwaitingAuthorization = 'awaiting-authorization';

    /** The provider says the money moved. */
    case Successful = 'successful';

    /** Collections only: the money has reached the group's account. */
    case Settled = 'settled';

    /** Our ledgers have it. Terminal, and the only state that means "done". */
    case Posted = 'posted';

    case Failed = 'failed';

    /** The member walked away, or a collection ran out of time unanswered. */
    case Abandoned = 'abandoned';

    /**
     * The money moved but the ledger refused it.
     *
     * A K750 contribution, a September payment over the cap, a member frozen by a
     * payout an hour earlier. Never retried automatically — a committee member has to
     * look at it, because the answer is a refund, a reclassification or a hold.
     */
    case NeedsAttention = 'needs-attention';

    /** Whether nothing more will happen without somebody intervening. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Posted, self::Failed, self::Abandoned => true,
            default => false,
        };
    }

    /** Whether the provider has told us the money moved. */
    public function hasSucceeded(): bool
    {
        return match ($this) {
            self::Successful, self::Settled, self::Posted => true,
            default => false,
        };
    }

    /** Whether we are still waiting on the provider or on the member. */
    public function isInFlight(): bool
    {
        return match ($this) {
            self::Draft, self::Pending, self::AwaitingAuthorization => true,
            default => false,
        };
    }

    /** Whether this payment should still be polled for an outcome. */
    public function isPollable(): bool
    {
        return $this === self::Pending || $this === self::AwaitingAuthorization;
    }

    /**
     * The states this one may legally move to.
     *
     * A webhook and a poll race each other constantly, so the state machine has to be
     * forgiving of arriving twice and unforgiving of going backwards: once a payment is
     * `Posted` a late `Successful` webhook must change nothing.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Pending, self::AwaitingAuthorization, self::Successful, self::Failed, self::Abandoned],
            self::Pending => [self::AwaitingAuthorization, self::Successful, self::Failed, self::Abandoned],
            self::AwaitingAuthorization => [self::Successful, self::Failed, self::Abandoned],
            self::Successful => [self::Settled, self::Posted, self::NeedsAttention, self::Failed],
            self::Settled => [self::Posted, self::NeedsAttention],
            self::NeedsAttention => [self::Posted, self::Abandoned],
            self::Posted, self::Failed, self::Abandoned => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** Plain language for the member portal — "pay-offline" means nothing to a member. */
    public function memberLabel(): string
    {
        return match ($this) {
            self::Draft => 'Not started',
            self::Pending => 'Sent — waiting for your network',
            self::AwaitingAuthorization => 'Approve the prompt on your phone',
            self::Successful, self::Settled => 'Paid — being recorded',
            self::Posted => 'Recorded',
            self::Failed => 'Did not go through',
            self::Abandoned => 'Cancelled',
            self::NeedsAttention => 'Paid — the committee is checking it',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Pending => 'Pending',
            self::AwaitingAuthorization => 'Awaiting authorisation',
            self::Successful => 'Successful',
            self::Settled => 'Settled',
            self::Posted => 'Posted',
            self::Failed => 'Failed',
            self::Abandoned => 'Abandoned',
            self::NeedsAttention => 'Needs attention',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }
}
