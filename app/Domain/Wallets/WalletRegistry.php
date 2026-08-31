<?php

namespace App\Domain\Wallets;

use App\Enums\TransactionSource;
use App\Enums\WalletEntryType;
use App\Enums\WalletKind;
use App\Enums\WalletStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\Scopes\CycleScope;
use App\Models\Wallet;
use App\Models\WalletEntry;
use App\Support\Kwacha;
use Brick\Money\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Opens, finds and closes wallets.
 *
 * One member wallet per member per cycle and exactly one group wallet per cycle. The
 * member half is guaranteed by a unique index; the group half cannot be, because
 * `member_id` is null there and MySQL treats NULLs as distinct — so it is an
 * application rule, enforced in one place rather than trusted to nobody trying.
 */
class WalletRegistry
{
    public function __construct(
        protected WalletLedger $ledger,
    ) {}

    /**
     * The member's wallet for a cycle, opening it if this is the first time.
     *
     * Idempotent under a race: two requests arriving together both try to insert and
     * the unique index picks a winner, so the loser re-reads rather than failing.
     */
    public function forMember(Member $member, ?Cycle $cycle = null): Wallet
    {
        $cycle ??= $member->cycle;

        if ($cycle === null) {
            throw DomainRuleException::make("{$member->full_name} does not belong to a cycle.");
        }

        return $this->firstOrOpen([
            'cycle_id' => $cycle->id,
            'member_id' => $member->id,
        ], WalletKind::Member);
    }

    /** The group's own wallet — the other side of every member's. */
    public function group(Cycle $cycle): Wallet
    {
        return $this->firstOrOpen([
            'cycle_id' => $cycle->id,
            'member_id' => null,
        ], WalletKind::Group);
    }

    /**
     * Opens a wallet for every member of a cycle, and the group's.
     *
     * Run when a cycle starts and after an import. Returns how many were opened.
     */
    public function openAll(Cycle $cycle): int
    {
        $opened = 0;

        if (! $this->exists($cycle, null)) {
            $this->group($cycle);
            $opened++;
        }

        Member::query()
            ->forCycle($cycle)
            ->each(function (Member $member) use ($cycle, &$opened): void {
                if (! $this->exists($cycle, $member->id)) {
                    $this->forMember($member, $cycle);
                    $opened++;
                }
            });

        return $opened;
    }

    /** Puts a committee hold on a wallet: nothing moves in either direction. */
    public function freeze(Wallet $wallet): Wallet
    {
        $wallet->forceFill(['status' => WalletStatus::Frozen])->save();

        return $wallet;
    }

    public function unfreeze(Wallet $wallet): Wallet
    {
        $wallet->forceFill(['status' => WalletStatus::Open])->save();

        return $wallet;
    }

    /**
     * Closes a wallet. Nothing new goes in; what is left may still be taken out.
     *
     * A closed wallet with a balance is not an error — it is a member who has left the
     * group and has not yet come for their money.
     */
    public function close(Wallet $wallet): Wallet
    {
        $wallet->forceFill([
            'status' => WalletStatus::Closed,
            'closed_at' => Carbon::now(),
        ])->save();

        return $wallet;
    }

    /**
     * Records what the group already holds, when wallets are switched on mid-cycle.
     *
     * The one place a wallet is credited with no movement of money behind it, and it is
     * an opening balance rather than a rewrite of history — the same idea as
     * `SavingsTransactionType::ImportOpening`. Without it the group wallet reads zero
     * while the group's account is full, invariant 3 is false on day one, and the first
     * loan disbursement is refused for money the group demonstrably has.
     *
     * Member wallets open at zero and stay there: nothing is owed to anybody yet.
     */
    public function recordOpeningFloat(Cycle $cycle, Money $amount, Member $actor, ?string $note = null): WalletEntry
    {
        $group = $this->group($cycle);

        if ($this->ledger->balanceNgwee($group) !== 0) {
            throw DomainRuleException::make(
                'The group wallet already holds '.Kwacha::format($this->ledger->balanceNgwee($group))
                    .'. An opening balance is only ever recorded once.'
            );
        }

        return $this->ledger->credit(
            $group,
            $amount,
            WalletEntryType::Adjustment,
            actor: $actor,
            source: TransactionSource::Import,
            note: $note ?? 'Opening balance: what the group held when wallets were switched on',
        );
    }

    /**
     * Moves a leftover balance into the next cycle's wallet.
     *
     * A paired entry, never a silent copy: the old wallet is debited and the new one
     * credited, so the balance stays derivable from entries in both cycles and the two
     * halves cancel in the reconciliation sum. Nothing is created or destroyed.
     */
    public function carryForward(Member $member, Cycle $from, Cycle $to, ?Member $actor = null): ?Wallet
    {
        if (! config('wallets.rollover.carry_forward', true)) {
            return null;
        }

        return DB::transaction(function () use ($member, $from, $to, $actor): ?Wallet {
            $old = $this->forMember($member, $from);
            $balance = $this->ledger->balanceNgwee($old);

            if ($balance <= 0) {
                return null;
            }

            $new = $this->forMember($member, $to);

            $note = 'Carried forward from '.$from->name.' to '.$to->name;

            $this->ledger->debit(
                $old,
                Kwacha::ofNgwee($balance),
                WalletEntryType::CarryForward,
                actor: $actor,
                source: TransactionSource::System,
                counterparty: $new,
                note: $note,
            );

            $this->ledger->credit(
                $new,
                Kwacha::ofNgwee($balance),
                WalletEntryType::CarryForward,
                actor: $actor,
                source: TransactionSource::System,
                counterparty: $old,
                note: $note,
            );

            return $new;
        });
    }

    /**
     * @param  array{cycle_id: int, member_id: int|null}  $keys
     */
    protected function firstOrOpen(array $keys, WalletKind $kind): Wallet
    {
        $existing = $this->find($keys);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return Wallet::create($keys + [
                'kind' => $kind,
                'status' => WalletStatus::Open,
                'opened_at' => Carbon::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            /* Somebody else opened it a millisecond ago. Theirs is as good as ours. */
            return $this->find($keys) ?? throw DomainRuleException::make('That wallet could not be opened.');
        }
    }

    protected function exists(Cycle $cycle, ?int $memberId): bool
    {
        return $this->find(['cycle_id' => $cycle->id, 'member_id' => $memberId]) !== null;
    }

    /**
     * @param  array{cycle_id: int, member_id: int|null}  $keys
     */
    protected function find(array $keys): ?Wallet
    {
        return Wallet::query()
            ->withoutGlobalScope(CycleScope::class)
            ->where('cycle_id', $keys['cycle_id'])
            ->when(
                $keys['member_id'] === null,
                fn ($query) => $query->whereNull('member_id'),
                fn ($query) => $query->where('member_id', $keys['member_id']),
            )
            ->first();
    }
}
