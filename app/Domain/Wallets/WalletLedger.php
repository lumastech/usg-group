<?php

namespace App\Domain\Wallets;

use App\Domain\Support\MoneyMutator;
use App\Enums\TransactionSource;
use App\Enums\WalletEntryType;
use App\Exceptions\DomainRuleException;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Exceptions\WalletUnavailableException;
use App\Models\Member;
use App\Models\PaymentIntent;
use App\Models\Scopes\CycleScope;
use App\Models\Wallet;
use App\Models\WalletEntry;
use App\Models\WalletTransfer;
use App\Support\Kwacha;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The single door into the wallet ledger.
 *
 * Every rule about wallet balances is enforced here and never at a call site: a wallet
 * may not go negative, a frozen wallet moves nothing, a closed one may be drained but
 * not filled. This is the same shape as `SocialFundLedger::post()`, deliberately — a
 * ledger whose invariants live in its callers has no invariants.
 *
 * The balance is read INSIDE the row lock, never before it. Two withdrawals arriving a
 * millisecond apart is not a rare case, and a balance read outside the lock is a number
 * that was true a moment ago.
 *
 * One thing this ledger deliberately does NOT ask: `LedgerFreeze`. A member whose
 * ledgers are frozen has been paid out, and they are precisely the person who needs to
 * withdraw what they were paid. The ledgers the wallet feeds ask that gate themselves,
 * which is where the protection belongs. See .ai/rules/wallets.md.
 */
class WalletLedger
{
    public function __construct(
        protected MoneyMutator $mutator,
    ) {}

    /**
     * Puts money into a wallet.
     *
     * @param  Money  $amount  stored positive whatever sign it arrives with
     */
    public function credit(
        Wallet $wallet,
        Money $amount,
        WalletEntryType $type,
        ?Member $actor = null,
        TransactionSource $source = TransactionSource::Manual,
        ?CarbonInterface $occurredOn = null,
        ?PaymentIntent $intent = null,
        ?WalletTransfer $transfer = null,
        ?Wallet $counterparty = null,
        ?Model $postedLedger = null,
        ?WalletEntry $reverses = null,
        ?Member $secondApprover = null,
        ?string $note = null,
    ): WalletEntry {
        return $this->post(
            $wallet,
            Kwacha::ofNgwee(abs(Kwacha::toNgwee($amount))),
            $type,
            $actor,
            $source,
            $occurredOn,
            $intent,
            $transfer,
            $counterparty,
            $postedLedger,
            $reverses,
            $secondApprover,
            $note,
        );
    }

    /**
     * Takes money out of a wallet.
     *
     * @param  Money  $amount  stored negative whatever sign it arrives with, so a caller
     *                         cannot accidentally credit a wallet by asking it to pay
     */
    public function debit(
        Wallet $wallet,
        Money $amount,
        WalletEntryType $type,
        ?Member $actor = null,
        TransactionSource $source = TransactionSource::Manual,
        ?CarbonInterface $occurredOn = null,
        ?PaymentIntent $intent = null,
        ?WalletTransfer $transfer = null,
        ?Wallet $counterparty = null,
        ?Model $postedLedger = null,
        ?WalletEntry $reverses = null,
        ?Member $secondApprover = null,
        ?string $note = null,
    ): WalletEntry {
        return $this->post(
            $wallet,
            Kwacha::ofNgwee(-abs(Kwacha::toNgwee($amount))),
            $type,
            $actor,
            $source,
            $occurredOn,
            $intent,
            $transfer,
            $counterparty,
            $postedLedger,
            $reverses,
            $secondApprover,
            $note,
        );
    }

    /**
     * Writes one entry.
     *
     * @param  Money  $amount  signed: positive into the wallet, negative out of it
     */
    public function post(
        Wallet $wallet,
        Money $amount,
        WalletEntryType $type,
        ?Member $actor = null,
        TransactionSource $source = TransactionSource::Manual,
        ?CarbonInterface $occurredOn = null,
        ?PaymentIntent $intent = null,
        ?WalletTransfer $transfer = null,
        ?Wallet $counterparty = null,
        ?Model $postedLedger = null,
        ?WalletEntry $reverses = null,
        ?Member $secondApprover = null,
        ?string $note = null,
    ): WalletEntry {
        $ngwee = Kwacha::toNgwee($amount);

        if ($ngwee === 0) {
            throw DomainRuleException::make('A wallet entry must move a non-zero amount.');
        }

        $write = function () use (
            $wallet, $ngwee, $type, $actor, $source, $occurredOn, $intent,
            $transfer, $counterparty, $postedLedger, $reverses, $secondApprover, $note,
        ): WalletEntry {
            $locked = $this->lock($wallet);

            $this->assertMayMove($locked, $ngwee);
            $this->assertCovered($locked, $ngwee);

            return WalletEntry::create([
                'cycle_id' => $locked->cycle_id,
                'wallet_id' => $locked->id,
                'amount_ngwee' => Kwacha::ofNgwee($ngwee),
                'type' => $type,
                'wallet_transfer_id' => $transfer?->id,
                'payment_intent_id' => $intent?->id,
                'counterparty_wallet_id' => $counterparty?->id,
                'posted_ledger_type' => $postedLedger === null ? null : $postedLedger->getMorphClass(),
                'posted_ledger_id' => $postedLedger?->getKey(),
                'reverses_wallet_entry_id' => $reverses?->id,
                'source' => $source,
                'occurred_on' => $occurredOn ?? Carbon::today(),
                'note' => $note,
                'recorded_by_member_id' => $actor?->id,
                'second_approver_member_id' => $secondApprover?->id,
            ]);
        };

        $reason = $type->label().' of '.Kwacha::format(abs($ngwee)).' on '.$wallet->label();

        $context = [
            'cycle_id' => $wallet->cycle_id,
            'wallet_id' => $wallet->id,
            'member_id' => $wallet->member_id,
            'type' => $type->value,
        ];

        return $actor === null
            ? $this->mutator->system($reason, $write, $context)
            : $this->mutator->mutate($actor, $reason, $write, $context);
    }

    /**
     * Undoes one entry with its mirror image.
     *
     * The correction is a new entry pointing at the one it reverses, never an edit:
     * both the mistake and the fix stay on the statement the member reads. A debit is
     * reversed by a credit and the other way round, so the pair always nets to zero.
     */
    public function reverse(WalletEntry $entry, ?Member $actor = null, ?string $note = null): WalletEntry
    {
        if ($this->reversalOf($entry) !== null) {
            throw DomainRuleException::make('That wallet entry has already been reversed.');
        }

        if ($entry->type === WalletEntryType::Reversal) {
            throw DomainRuleException::make('A reversal cannot itself be reversed.');
        }

        return $this->post(
            $entry->wallet,
            Kwacha::ofNgwee(-(int) $entry->getRawOriginal('amount_ngwee')),
            WalletEntryType::Reversal,
            actor: $actor,
            source: $entry->source,
            occurredOn: Carbon::today(),
            transfer: $entry->transfer,
            counterparty: $entry->counterparty,
            reverses: $entry,
            note: $note ?? 'Reverses entry #'.$entry->id,
        );
    }

    /** The reversal already written against an entry, if there is one. */
    public function reversalOf(WalletEntry $entry): ?WalletEntry
    {
        return WalletEntry::query()
            ->acrossCycles()
            ->where('reverses_wallet_entry_id', $entry->id)
            ->first();
    }

    /** What a wallet holds. */
    public function balance(Wallet $wallet): Money
    {
        return Kwacha::ofNgwee($this->balanceNgwee($wallet));
    }

    /**
     * The same as a raw integer.
     *
     * Read across cycles on purpose: a member who does not rejoin still withdraws from
     * the closed cycle's wallet, and a pinned current cycle must not make that balance
     * read as zero.
     */
    public function balanceNgwee(Wallet $wallet): int
    {
        return (int) $this->entries($wallet)->sum('amount_ngwee');
    }

    /** What a wallet held at the end of a given day. */
    public function balanceOn(Wallet $wallet, CarbonInterface $date): Money
    {
        return Kwacha::ofNgwee((int) $this->entries($wallet)
            ->whereDate('occurred_on', '<=', $date)
            ->sum('amount_ngwee'));
    }

    /**
     * A wallet's movements, newest first — what `/my/payments` shows the member.
     *
     * @return Builder<WalletEntry>
     */
    public function statement(Wallet $wallet): Builder
    {
        return $this->entries($wallet)
            ->with(['counterparty.member', 'transfer', 'paymentIntent'])
            ->latest('occurred_on')
            ->latest('id');
    }

    /**
     * Every entry on one wallet, regardless of which cycle is pinned.
     *
     * @return Builder<WalletEntry>
     */
    public function entries(Wallet $wallet): Builder
    {
        return WalletEntry::query()->acrossCycles()->where('wallet_id', $wallet->id);
    }

    /**
     * Throws unless the wallet could cover a debit of this size right now.
     *
     * A courtesy check for callers that want the refusal before they start building
     * something. It is NOT the guarantee — `post()` re-checks inside the row lock,
     * because a balance read outside the lock is a number that was true a moment ago.
     */
    public function assertCovers(Wallet $wallet, Money $amount): void
    {
        $this->assertCovered($wallet, -abs(Kwacha::toNgwee($amount)));
    }

    /**
     * Re-reads the wallet under a row lock.
     *
     * Everything that follows — the status check, the balance, the write — happens
     * while this row is held, so a second caller queues behind it rather than reading a
     * balance the first is about to spend.
     */
    public function lock(Wallet $wallet): Wallet
    {
        $locked = Wallet::query()
            ->withoutGlobalScope(CycleScope::class)
            ->whereKey($wallet->id)
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            throw DomainRuleException::make('That wallet no longer exists.');
        }

        return $locked;
    }

    /** A frozen wallet moves nothing; a closed one may be drained but not filled. */
    protected function assertMayMove(Wallet $wallet, int $ngwee): void
    {
        if ($ngwee > 0 && ! $wallet->status->acceptsCredits()) {
            throw new WalletUnavailableException(
                'Money cannot be put into '.$wallet->label().': it is '
                    .strtolower($wallet->status->label()).'.'
            );
        }

        if ($ngwee < 0 && ! $wallet->status->acceptsDebits()) {
            throw new WalletUnavailableException(
                'Money cannot be taken out of '.$wallet->label().': it is '
                    .strtolower($wallet->status->label()).'.'
            );
        }
    }

    /**
     * No wallet may go negative — not a member's, and not the group's.
     *
     * A member overdrawn would be the group lending without a loan. The group wallet
     * overdrawn would be the group paying out money it does not hold, which is the
     * failure this whole design exists to make impossible.
     */
    protected function assertCovered(Wallet $wallet, int $ngwee): void
    {
        if ($ngwee >= 0) {
            return;
        }

        $balance = $this->balanceNgwee($wallet);

        if ($balance + $ngwee < 0) {
            throw new InsufficientWalletBalanceException(
                ucfirst($wallet->label()).' holds '.Kwacha::format($balance)
                    .', which does not cover '.Kwacha::format(abs($ngwee)).'.'
            );
        }
    }
}
