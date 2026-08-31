<?php

namespace App\Domain\Wallets;

use App\Enums\TransactionSource;
use App\Enums\WalletEntryType;
use App\Exceptions\DomainRuleException;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\PaymentIntent;
use App\Models\Wallet;
use App\Models\WalletEntry;
use App\Support\Kwacha;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Money in: the one payment path no domain rule may refuse.
 *
 * There is no rule under which the group will not take money into a member's own
 * wallet, so a top-up can only ever succeed or fail, and a failure leaves nothing
 * half-done — no settled payment parked at NeedsAttention waiting for somebody to
 * decide between a refund, a reclassification and a hold. Every rule that used to be
 * checked before asking for the money now sits on the wallet-to-wallet transfer.
 *
 * Cash at the trading table is the same step without a provider: the treasurer records
 * a Cash top-up, which is exactly the authority they already have when recording a
 * cash contribution today.
 */
class TopUpService
{
    public function __construct(
        protected WalletLedger $ledger,
        protected WalletRegistry $wallets,
    ) {}

    /**
     * Credits a wallet from a settled provider collection.
     *
     * Idempotent by the unique `payment_intent_id` on the entry: a webhook and a poll
     * arrive for the same payment constantly, and this is what makes both of them safe
     * rather than one of them lucky.
     */
    public function fromPayment(PaymentIntent $intent): WalletEntry
    {
        $existing = $this->entryFor($intent);

        if ($existing !== null) {
            return $existing;
        }

        $wallet = $this->walletFor($intent);

        try {
            return $this->ledger->credit(
                $wallet,
                $intent->amount_ngwee,
                WalletEntryType::TopUp,
                actor: $intent->requestedBy ?? $intent->member,
                source: TransactionSource::Gateway,
                occurredOn: $intent->effectiveDate(),
                intent: $intent,
                note: 'Top-up by '.$intent->channel->label(),
            );
        } catch (UniqueConstraintViolationException) {
            /* Somebody else credited it a millisecond ago. Theirs counts; ours does not. */
            return $this->entryFor($intent)
                ?? throw DomainRuleException::make('That top-up could not be credited.');
        }
    }

    /**
     * Banknotes counted by a treasurer at the table.
     *
     * Named `Cash` rather than left to look like a gateway payment, because
     * reconciliation has to know how much of the float is in the tin rather than at the
     * provider — that difference is the whole of invariant 1.
     */
    public function inCash(
        Member $member,
        Money $amount,
        Member $actor,
        ?Cycle $cycle = null,
        ?CarbonInterface $occurredOn = null,
        ?string $note = null,
    ): WalletEntry {
        $this->assertAboveMinimum(Kwacha::toNgwee($amount));

        return $this->ledger->credit(
            $this->wallets->forMember($member, $cycle),
            $amount,
            WalletEntryType::TopUp,
            actor: $actor,
            source: TransactionSource::Cash,
            occurredOn: $occurredOn,
            note: $note ?? 'Cash counted at the table',
        );
    }

    /** The entry a payment already produced, if it produced one. */
    public function entryFor(PaymentIntent $intent): ?WalletEntry
    {
        return WalletEntry::query()
            ->acrossCycles()
            ->where('payment_intent_id', $intent->id)
            ->first();
    }

    /**
     * The floor the provider will not go below.
     *
     * The only check a top-up gets, and it is not a domain rule — it is the provider
     * refusing to move an amount that costs more to move than it is worth.
     */
    public function assertAboveMinimum(int $ngwee): void
    {
        $minimum = (int) config('wallets.top_ups.min_ngwee', 100);

        if ($ngwee < $minimum) {
            throw DomainRuleException::make('A top-up must be at least '.Kwacha::format($minimum).'.');
        }
    }

    /** The wallet a settled payment belongs in. */
    protected function walletFor(PaymentIntent $intent): Wallet
    {
        $member = $intent->member;

        if ($member === null) {
            throw DomainRuleException::make('This top-up has no member to credit.');
        }

        return $this->wallets->forMember($member, $intent->cycle);
    }
}
