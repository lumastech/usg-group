<?php

namespace App\Domain\Wallets;

use App\Domain\Approvals\TwoPersonRule;
use App\Domain\Payments\PaymentGateway;
use App\Domain\Payments\PaymentIntentService;
use App\Domain\Payments\PayoutDestinationService;
use App\Domain\Payments\TransferRequest;
use App\Enums\CycleStatus;
use App\Enums\FeeBearer;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\TransactionSource;
use App\Enums\WalletEntryType;
use App\Exceptions\DomainRuleException;
use App\Exceptions\PaymentGatewayException;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\PaymentIntent;
use App\Models\PayoutDestination;
use App\Models\Wallet;
use App\Models\WalletEntry;
use App\Support\Kwacha;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Money out: the second and last thing the provider is asked to do.
 *
 * The wallet is debited on INITIATION, not on confirmation. A member cannot spend the
 * same balance twice while a transfer is in flight, and the immutable-ledger convention
 * says the undo is a reversing entry rather than a deletion. Everything follows from
 * that one choice:
 *
 * - a definite failure credits a Reversal and the member is whole again, free to retry;
 * - an unknown outcome — a timeout — is escalated to a person and NEVER auto-reversed,
 *   because money may have left the group's account and nobody can tell from here.
 *
 * All four destination controls apply unchanged: the provider is asked whose account it
 * is, the resolved name is scored, a destination changed inside the cooling-off window
 * needs a second signature, and the member is notified on the contacts they had before
 * the change. Redirecting a payout is still the highest-value attack in the system and
 * nothing here relaxes it.
 */
class WithdrawalService
{
    public function __construct(
        protected WalletLedger $ledger,
        protected WalletRegistry $wallets,
        protected PaymentIntentService $intents,
        protected PayoutDestinationService $destinations,
        protected PaymentGateway $gateway,
        protected TwoPersonRule $twoPersonRule,
    ) {}

    /**
     * Sends a member's own money to where they said to send it.
     *
     * @param  Money  $amount  what the member receives; the provider's fee is debited
     *                         beside it, because the group settled that the member
     *                         bears it (config wallets.withdrawals.fee_bearer)
     */
    public function request(
        Member $member,
        Money $amount,
        Member $actor,
        ?PayoutDestination $destination = null,
        ?Member $secondApprover = null,
        ?Cycle $cycle = null,
    ): PaymentIntent {
        $wallet = $this->wallets->forMember($member, $cycle ?? $member->cycle);

        $this->assertWithdrawable($wallet, $amount, $this->feeEstimate());

        $destination ??= $member->defaultPayoutDestination()->first();

        if ($destination === null) {
            throw DomainRuleException::make(
                "{$member->full_name} has not said where to send their money, so this has to be paid in cash."
            );
        }

        $this->destinations->assertPayable($destination);
        $this->requireSignature($destination, $member, $actor, $secondApprover);

        $fee = $this->feeEstimate();

        $intent = $this->intents->create(
            cycle: $wallet->cycle,
            purpose: PaymentPurpose::WalletWithdrawal,
            amountNgwee: Kwacha::toNgwee($amount),
            channel: $destination->type->channel(),
            member: $member,
            destination: $destination,
            requestedBy: $actor,
            attributes: [
                'approved_by_member_id' => $actor->id,
                'second_approver_member_id' => $secondApprover?->id,
                'fee_bearer' => FeeBearer::from((string) config('wallets.withdrawals.fee_bearer', 'customer')),
            ],
        );

        /*
         * Debited before the request goes out. The alternative — debit on confirmation —
         * lets a member start four withdrawals against one balance and have all four
         * succeed, and no reconciliation would catch it until the money was gone.
         */
        DB::transaction(function () use ($wallet, $amount, $fee, $actor, $intent): void {
            $this->ledger->debit(
                $wallet,
                $amount,
                WalletEntryType::Withdrawal,
                actor: $actor,
                source: TransactionSource::Gateway,
                intent: $intent,
                note: 'Withdrawal to '.($intent->destination?->label() ?? 'a destination on file'),
            );

            if ($fee > 0) {
                $this->ledger->debit(
                    $wallet,
                    Kwacha::ofNgwee($fee),
                    WalletEntryType::Fee,
                    actor: $actor,
                    source: TransactionSource::Gateway,
                    intent: $intent,
                    note: 'Estimated transfer fee, squared up when the transfer confirms',
                );
            }
        });

        try {
            return $this->intents->sendTransfer(
                $intent,
                TransferRequest::from($intent->refresh(), $destination, 'Unity Savings wallet withdrawal'),
            );
        } catch (PaymentGatewayException $exception) {
            /*
             * A refusal the provider actually sent is a fact: nothing moved, so the
             * member is made whole and may try again. A timeout is not — the money may
             * have gone and only the answer was lost — so the debit stands and a person
             * is asked to go and look.
             */
            if (! $exception->outcomeUnknown) {
                $this->refund($intent->refresh(), 'The provider refused the transfer: '.$exception->reason());
            }

            throw $exception;
        }
    }

    /**
     * Banknotes handed across the table instead of a transfer.
     *
     * Two signatures whatever the amount, which is stricter than the fund's threshold
     * rule and deliberately so: a provider transfer leaves a record at the provider,
     * and a banknote leaves only this entry. Minuted by the committee — see
     * docs/WALLET-PLAN.md §7.
     */
    public function payCash(
        Member $member,
        Money $amount,
        Member $actor,
        ?Member $secondApprover = null,
        ?Cycle $cycle = null,
        ?CarbonInterface $occurredOn = null,
    ): WalletEntry {
        $wallet = $this->wallets->forMember($member, $cycle ?? $member->cycle);

        /* No provider, so no fee to hold back and no floor to protect it from. */
        $this->assertWithdrawable($wallet, $amount, fee: 0, minimum: 0);

        if (config('wallets.withdrawals.cash_requires_second_signature', true)) {
            if ($secondApprover === null) {
                throw DomainRuleException::make(
                    'Paying a wallet out in cash needs a second committee member to confirm it.'
                );
            }

            $this->twoPersonRule->assertDistinctCommittee($actor, $secondApprover, $member);
        }

        return $this->ledger->debit(
            $wallet,
            $amount,
            WalletEntryType::Withdrawal,
            actor: $actor,
            source: TransactionSource::Cash,
            occurredOn: $occurredOn,
            secondApprover: $secondApprover,
            note: 'Paid in cash at the table',
        );
    }

    /**
     * Squares the estimated fee up against what the provider actually charged.
     *
     * Called when the transfer confirms. The difference is an Adjustment either way and
     * it appears on the statement — a member who was reserved K10 and charged K8.50 is
     * credited the K1.50 rather than quietly losing it.
     */
    public function settleFee(PaymentIntent $intent): ?WalletEntry
    {
        $charged = $intent->fee_ngwee === null ? null : Kwacha::toNgwee($intent->fee_ngwee);

        if ($charged === null) {
            return null;
        }

        $reserved = $this->reservedFee($intent);
        $difference = $reserved - $charged;

        if ($difference === 0) {
            return null;
        }

        $wallet = $this->walletFor($intent);
        $note = 'Transfer fee squared up: reserved '.Kwacha::format($reserved)
            .', charged '.Kwacha::format($charged);

        return $difference > 0
            ? $this->ledger->credit(
                $wallet,
                Kwacha::ofNgwee($difference),
                WalletEntryType::Adjustment,
                source: TransactionSource::Gateway,
                occurredOn: $intent->effectiveDate(),
                note: $note,
            )
            : $this->ledger->debit(
                $wallet,
                Kwacha::ofNgwee(abs($difference)),
                WalletEntryType::Adjustment,
                source: TransactionSource::Gateway,
                occurredOn: $intent->effectiveDate(),
                note: $note,
            );
    }

    /**
     * Puts the money back after a transfer the provider definitely refused.
     *
     * Reversing entries, never deletions: the attempt and its undo both stay on the
     * statement, so a member who sees a debit and then a credit can read what happened.
     */
    public function refund(PaymentIntent $intent, string $reason): void
    {
        DB::transaction(function () use ($intent, $reason): void {
            foreach ($this->entriesFor($intent) as $entry) {
                if ($this->ledger->reversalOf($entry) === null) {
                    $this->ledger->reverse($entry, $intent->requestedBy, $reason);
                }
            }
        });
    }

    /**
     * Puts the money back for every withdrawal the provider definitely refused.
     *
     * Swept rather than hooked onto the status change, because the news can arrive by
     * webhook, by poll or by the browser coming back, and a member owed their own money
     * must not depend on which. Idempotent: an entry already reversed is skipped, so
     * running it twice is running it once.
     *
     * `NeedsAttention` is deliberately not in the list. That is the timeout case —
     * money may have left the group's account — and it is a person's to resolve.
     *
     * A stale `Draft` is in the list. The wallet is debited before the provider is
     * called, so a process that died in between leaves a member's money debited against
     * a request that was never sent. Nothing can have moved — `send()` only ever leaves
     * a Draft behind by not running — so after the give-up window it is abandoned and
     * put back, rather than stranding the member's own money forever.
     *
     * @return int how many withdrawals were put back
     */
    public function reverseFailed(): int
    {
        $reversed = 0;

        $staleAfter = Carbon::now()
            ->subMinutes((int) config('payments.collections.poll.give_up_after_minutes', 60));

        PaymentIntent::query()
            ->acrossCycles()
            ->where('purpose', PaymentPurpose::WalletWithdrawal->value)
            ->where(function ($query) use ($staleAfter): void {
                $query->whereIn('status', [PaymentStatus::Failed->value, PaymentStatus::Abandoned->value])
                    ->orWhere(fn ($stale) => $stale
                        ->where('status', PaymentStatus::Draft->value)
                        ->where('created_at', '<', $staleAfter));
            })
            ->orderBy('id')
            ->chunkById(100, function ($intents) use (&$reversed): void {
                foreach ($intents as $intent) {
                    $outstanding = $this->entriesFor($intent)
                        ->filter(fn (WalletEntry $entry): bool => $this->ledger->reversalOf($entry) === null);

                    if ($outstanding->isEmpty()) {
                        continue;
                    }

                    if ($intent->status === PaymentStatus::Draft) {
                        $this->intents->abandonDraft($intent, 'Never reached the provider.');
                    }

                    $this->refund($intent, 'The transfer did not go through: '
                        .($intent->status_reason ?? 'it never reached the provider'));

                    $reversed++;
                }
            });

        return $reversed;
    }

    /**
     * Every wallet entry one withdrawal produced.
     *
     * @return Collection<int, WalletEntry>
     */
    public function entriesFor(PaymentIntent $intent): Collection
    {
        return WalletEntry::query()
            ->acrossCycles()
            ->where('payment_intent_id', $intent->id)
            ->whereIn('type', [WalletEntryType::Withdrawal->value, WalletEntryType::Fee->value])
            ->get();
    }

    /** What the member may take out right now, after the fee is allowed for. */
    public function availableNgwee(Wallet $wallet): int
    {
        return max(0, $this->ledger->balanceNgwee($wallet) - $this->feeEstimate());
    }

    /**
     * Every reason a withdrawal may not go ahead.
     *
     * Deliberately does NOT ask `LedgerFreeze`. A member whose ledgers are frozen has
     * been paid out and is precisely the person who needs to withdraw what they were
     * paid; the ledgers the wallet feeds ask that gate themselves.
     */
    protected function assertWithdrawable(Wallet $wallet, Money $amount, int $fee, ?int $minimum = null): void
    {
        $ngwee = Kwacha::toNgwee($amount);
        $minimum ??= (int) config('wallets.withdrawals.min_ngwee', 5_000);

        if ($ngwee < $minimum) {
            throw DomainRuleException::make(
                'A withdrawal must be at least '.Kwacha::format($minimum)
                    .', so the fee is never a large part of what is sent.'
            );
        }

        if (config('wallets.withdrawals.allowed_from') === 'share_out'
            && $wallet->cycle->status !== CycleStatus::ShareOut) {
            throw DomainRuleException::make(
                'Wallet withdrawals open at share-out.'
            );
        }

        $this->ledger->assertCovers($wallet, Kwacha::ofNgwee($ngwee + $fee));
    }

    /** Two signatures where the destination itself is what looks new or wrong. */
    protected function requireSignature(
        PayoutDestination $destination,
        Member $member,
        Member $actor,
        ?Member $secondApprover,
    ): void {
        if (! $this->destinations->needsSecondSignature($destination)) {
            return;
        }

        if ($secondApprover === null) {
            throw DomainRuleException::make(
                $destination->hasUnconfirmedNameMismatch()
                    ? 'The account is in the name of '.($destination->resolved_account_name ?? 'somebody else')
                        .', so a second committee member has to confirm this before the money goes.'
                    : 'This destination was changed in the last '
                        .config('payments.transfers.destination_cooling_off_hours')
                        .' hours, so a second committee member has to confirm this before the money goes.'
            );
        }

        $this->twoPersonRule->assertDistinctCommittee($actor, $secondApprover, $member);
    }

    protected function feeEstimate(): int
    {
        return FeeBearer::from((string) config('wallets.withdrawals.fee_bearer', 'customer')) === FeeBearer::Customer
            ? (int) config('wallets.withdrawals.fee_estimate_ngwee', 0)
            : 0;
    }

    /** What was actually held back for the fee on this withdrawal. */
    protected function reservedFee(PaymentIntent $intent): int
    {
        return abs((int) WalletEntry::query()
            ->acrossCycles()
            ->where('payment_intent_id', $intent->id)
            ->where('type', WalletEntryType::Fee->value)
            ->sum('amount_ngwee'));
    }

    protected function walletFor(PaymentIntent $intent): Wallet
    {
        $member = $intent->member;

        if ($member === null) {
            throw DomainRuleException::make('This withdrawal has no member to settle against.');
        }

        return $this->wallets->forMember($member, $intent->cycle);
    }
}
