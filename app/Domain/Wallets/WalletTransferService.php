<?php

namespace App\Domain\Wallets;

use App\Domain\Approvals\TwoPersonRule;
use App\Enums\TransactionSource;
use App\Enums\WalletEntryType;
use App\Enums\WalletTransferPurpose;
use App\Exceptions\DomainRuleException;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\Wallet;
use App\Models\WalletTransfer;
use App\Support\Kwacha;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One movement of money between two wallets, and the record of what it was for.
 *
 * This is the internal payment path the whole wallet design exists to produce: one
 * road, exercised identically whether the money arrived by card, by mobile money or as
 * a banknote on the table. The rail is now only how the wallet got funded.
 *
 * A refusal here costs nothing. Nothing has left anybody's hands, the member is still
 * holding their money, and there is no settled payment for a person to decide about —
 * which is the entire difference between this and collecting per purpose.
 *
 * Every leg runs in one database transaction: the two entries and the ledger row that
 * says what the money was for. A domain rule that refuses — a K750 contribution in a
 * K500-increment month, a September payment over the cap — throws inside it and the
 * transfer never happened.
 */
class WalletTransferService
{
    public function __construct(
        protected WalletLedger $ledger,
        protected WalletRegistry $wallets,
        protected TwoPersonRule $twoPersonRule,
    ) {}

    /**
     * Moves money from one wallet to another and records what it was for.
     *
     * @param  Closure(WalletTransfer): ?Model|null  $record  the domain service that
     *                                                        posts what the money was
     *                                                        for; its refusal is the
     *                                                        transfer's refusal
     */
    public function transfer(
        Wallet $from,
        Wallet $to,
        Money $amount,
        WalletTransferPurpose $purpose,
        Member $actor,
        ?Model $payable = null,
        ?Member $secondApprover = null,
        ?CarbonInterface $occurredAt = null,
        ?string $note = null,
        ?Closure $record = null,
    ): WalletTransfer {
        $ngwee = Kwacha::toNgwee($amount);

        if ($ngwee <= 0) {
            throw DomainRuleException::make('A transfer must move more than nothing.');
        }

        if ($from->is($to)) {
            throw DomainRuleException::make('A wallet cannot pay itself.');
        }

        $this->requireSignatures($purpose, $actor, $to->member ?? $from->member, $secondApprover);

        $occurredAt ??= Carbon::now();

        return DB::transaction(function () use (
            $from, $to, $amount, $ngwee, $purpose, $actor, $payable, $secondApprover, $occurredAt, $note, $record,
        ): WalletTransfer {
            /*
             * Both rows locked before anything is read, and always in the same order.
             * Two transfers running in opposite directions between the same pair would
             * otherwise take each other's rows and deadlock.
             */
            foreach ($this->lockOrder($from, $to) as $wallet) {
                $this->ledger->lock($wallet);
            }

            /* The refusal the member should see, before the ledgers are touched. */
            $this->ledger->assertCovers($from, $amount);

            $transfer = WalletTransfer::create([
                'cycle_id' => $from->cycle_id,
                'from_wallet_id' => $from->id,
                'to_wallet_id' => $to->id,
                'amount_ngwee' => Kwacha::ofNgwee($ngwee),
                'purpose' => $purpose,
                'payable_type' => $payable === null ? null : $payable->getMorphClass(),
                'payable_id' => $payable?->getKey(),
                'approved_by_member_id' => $actor->id,
                'second_approver_member_id' => $secondApprover?->id,
                'created_by_member_id' => $actor->id,
                'occurred_at' => $occurredAt,
                'note' => $note,
            ]);

            /*
             * What the money was FOR is recorded first, so the entries can point at the
             * row it produced — wallet entries are immutable and cannot be stamped
             * afterwards. A refusal here rolls the whole thing back.
             */
            $posted = $record === null ? null : $record($transfer);

            $memberLeg = $purpose->isInbound() ? $from : $to;

            $this->ledger->debit(
                $from,
                $amount,
                WalletEntryType::Payment,
                actor: $actor,
                source: TransactionSource::System,
                occurredOn: $occurredAt,
                transfer: $transfer,
                counterparty: $to,
                postedLedger: $memberLeg->is($from) ? $posted : null,
                secondApprover: $secondApprover,
                note: $note,
            );

            $this->ledger->credit(
                $to,
                $amount,
                WalletEntryType::Receipt,
                actor: $actor,
                source: TransactionSource::System,
                occurredOn: $occurredAt,
                transfer: $transfer,
                counterparty: $from,
                postedLedger: $memberLeg->is($to) ? $posted : null,
                secondApprover: $secondApprover,
                note: $note,
            );

            return $transfer->refresh();
        });
    }

    /** The member's wallet paying the group's. */
    public function fromMember(Member $member, ?Cycle $cycle = null): Wallet
    {
        return $this->wallets->forMember($member, $cycle);
    }

    /** The group's wallet, the other side of every member's. */
    public function groupWallet(Cycle $cycle): Wallet
    {
        return $this->wallets->group($cycle);
    }

    /**
     * Two signatures where the purpose demands them.
     *
     * Unchanged from what the provider path asked for. Moving money inside the books
     * rather than across a network does not move who has to agree to it.
     */
    protected function requireSignatures(
        WalletTransferPurpose $purpose,
        Member $actor,
        ?Member $subject,
        ?Member $secondApprover,
    ): void {
        if (! $purpose->requiresSecondApprover()) {
            return;
        }

        if ($secondApprover === null) {
            throw DomainRuleException::make(
                'Paying a '.strtolower($purpose->label()).' needs a second committee member to confirm it.'
            );
        }

        $this->twoPersonRule->assertDistinctCommittee($actor, $secondApprover, $subject);
    }

    /**
     * Both wallets, lowest id first.
     *
     * @return array<int, Wallet>
     */
    protected function lockOrder(Wallet $from, Wallet $to): array
    {
        return $from->id <= $to->id ? [$from, $to] : [$to, $from];
    }
}
