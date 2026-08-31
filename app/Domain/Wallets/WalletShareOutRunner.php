<?php

namespace App\Domain\Wallets;

use App\Domain\Payments\PaymentGateway;
use App\Exceptions\DomainRuleException;
use App\Exceptions\PaymentGatewayException;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\Payout;
use App\Models\Wallet;
use App\Support\Kwacha;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Throwable;

/**
 * Share-out, in the two halves the wallet layer splits it into.
 *
 * Paying the room becomes an internal movement: every settled payout is credited to the
 * member's own wallet, exactly to the ngwee, with no provider involved and nothing that
 * can half-succeed. Half a room paid with no record of who is next — the worst outcome
 * available on share-out day — stops being possible, because the group wallet either
 * covers the whole schedule or the first transfer is refused.
 *
 * Taking the money home is then each member's own decision and their own withdrawal.
 * The batch below is the treasurer running that for everybody who has said where their
 * money goes; anybody who has not is paid in cash at the table, as they always were.
 *
 * `PayoutExecutor` is untouched. It remains the single irreversible act — two
 * signatures, the freeze, the record — and this runs after it, on payouts that already
 * stand.
 */
class WalletShareOutRunner
{
    public function __construct(
        protected WalletDisbursements $disbursements,
        protected WithdrawalService $withdrawals,
        protected WalletRegistry $wallets,
        protected WalletLedger $ledger,
        protected PaymentGateway $gateway,
    ) {}

    /**
     * What the run would do, and whether the group can cover it.
     *
     * @return array{
     *     payable_count: int,
     *     payable_ngwee: int,
     *     group_balance_ngwee: int,
     *     covered: bool,
     *     shortfall_ngwee: int,
     * }
     */
    public function preview(Cycle $cycle): array
    {
        $unpaid = $this->unpaid($cycle);
        $owed = (int) $unpaid->sum(fn (Payout $payout): int => Kwacha::toNgwee($payout->amount_ngwee));
        $held = $this->ledger->balanceNgwee($this->wallets->group($cycle));

        return [
            'payable_count' => $unpaid->count(),
            'payable_ngwee' => $owed,
            'group_balance_ngwee' => $held,
            'covered' => $held >= $owed,
            'shortfall_ngwee' => max(0, $owed - $held),
        ];
    }

    /**
     * Credits every settled payout into the member's wallet.
     *
     * The whole schedule is checked against the group wallet before the first movement,
     * for the same reason the provider balance was checked before: a run that pays two
     * thirds of the room and stops is the outcome the committee cannot recover from in
     * the room.
     *
     * @return array{paid: array<int, array<string, mixed>>, failed: array<int, array<string, mixed>>, paid_count: int, failed_count: int, paid_ngwee: int}
     */
    public function credit(Cycle $cycle, Member $actor, Member $secondApprover): array
    {
        $preview = $this->preview($cycle);

        if ($preview['payable_count'] === 0) {
            throw DomainRuleException::make('There is nothing left to pay: every payout has been settled.');
        }

        if (! $preview['covered']) {
            throw DomainRuleException::make(sprintf(
                'The group wallet holds %s, which does not cover the %s still owed. Short by %s.',
                Kwacha::format($preview['group_balance_ngwee']),
                Kwacha::format($preview['payable_ngwee']),
                Kwacha::format($preview['shortfall_ngwee']),
            ));
        }

        $paid = [];
        $failed = [];

        foreach ($this->unpaid($cycle) as $payout) {
            try {
                $transfer = $this->disbursements->payPayout($payout, $actor, $secondApprover, isShareOut: true);
            } catch (Throwable $exception) {
                $failed[] = $this->row($payout, $exception->getMessage());

                continue;
            }

            $paid[] = $this->row($payout, null) + ['wallet_transfer_id' => $transfer->id];
        }

        return [
            'paid' => $paid,
            'failed' => $failed,
            'paid_count' => count($paid),
            'failed_count' => count($failed),
            'paid_ngwee' => array_sum(array_column($paid, 'amount_ngwee')),
        ];
    }

    /**
     * Sends what is sitting in the wallets out to where members said to send it.
     *
     * The provider balance is checked against the WHOLE batch first, as it always was.
     * A member with no destination on file is listed for the table to pay in cash
     * rather than being skipped quietly.
     *
     * @return array{sent: array<int, array<string, mixed>>, failed: array<int, array<string, mixed>>, by_hand: array<int, array<string, mixed>>, sent_count: int, failed_count: int, sent_ngwee: int}
     */
    public function withdrawAll(Cycle $cycle, Member $actor, ?Member $secondApprover = null): array
    {
        $holders = $this->holders($cycle);

        $this->assertProviderCovers(
            (int) $holders->sum(fn (Wallet $wallet): int => $this->ledger->balanceNgwee($wallet))
        );

        $sent = [];
        $failed = [];
        $byHand = [];

        foreach ($holders as $wallet) {
            $member = $wallet->member;
            $available = $this->withdrawals->availableNgwee($wallet);

            if ($member === null) {
                continue;
            }

            if ($member->defaultPayoutDestination()->first() === null) {
                $byHand[] = $this->walletRow($wallet, 'No destination on file — pay this one by hand.');

                continue;
            }

            if ($available < (int) config('wallets.withdrawals.min_ngwee', 5_000)) {
                $byHand[] = $this->walletRow($wallet, 'Too small to send once the fee is allowed for.');

                continue;
            }

            try {
                $intent = $this->withdrawals->request(
                    $member,
                    Kwacha::ofNgwee($available),
                    $actor,
                    secondApprover: $secondApprover,
                    cycle: $cycle,
                );
            } catch (Throwable $exception) {
                $failed[] = $this->walletRow($wallet, $exception->getMessage());

                continue;
            }

            $sent[] = $this->walletRow($wallet, null) + [
                'payment_intent_id' => $intent->id,
                'reference' => $intent->reference,
                'status' => $intent->status->value,
            ];
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'by_hand' => $byHand,
            'sent_count' => count($sent),
            'failed_count' => count($failed),
            'sent_ngwee' => array_sum(array_column($sent, 'amount_ngwee')),
        ];
    }

    /**
     * Payouts the group has signed for but not yet handed over.
     *
     * @return EloquentCollection<int, Payout>
     */
    public function unpaid(Cycle $cycle): EloquentCollection
    {
        return Payout::query()
            ->forCycle($cycle)
            ->whereNull('paid_at')
            ->with('member')
            ->get()
            ->sortBy(fn (Payout $payout): int => $payout->member->member_number ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * Member wallets with money in them.
     *
     * @return EloquentCollection<int, Wallet>
     */
    public function holders(Cycle $cycle): EloquentCollection
    {
        return Wallet::query()
            ->forCycle($cycle)
            ->memberOwned()
            ->with('member.defaultPayoutDestination')
            ->get()
            ->filter(fn (Wallet $wallet): bool => $this->ledger->balanceNgwee($wallet) > 0)
            ->sortBy(fn (Wallet $wallet): int => $wallet->member->member_number ?? PHP_INT_MAX)
            ->values();
    }

    /** Half a room paid with no record of who is next is the worst outcome available. */
    protected function assertProviderCovers(int $ngwee): void
    {
        try {
            $available = $this->gateway->balanceNgwee();
        } catch (PaymentGatewayException $exception) {
            throw DomainRuleException::make(
                'The provider could not be asked what the account holds, so the batch was not started: '
                    .$exception->reason()
            );
        }

        $headroom = (int) config('payments.transfers.balance_headroom_ngwee', 0);

        if ($available - $headroom < $ngwee) {
            throw DomainRuleException::make(sprintf(
                'The group\'s account holds %s, which is not enough to send %s.',
                Kwacha::format($available),
                Kwacha::format($ngwee),
            ));
        }
    }

    /** @return array<string, mixed> */
    protected function row(Payout $payout, ?string $reason): array
    {
        return array_filter([
            'payout_id' => $payout->id,
            'member_id' => $payout->member_id,
            'member_number' => $payout->member?->member_number,
            'full_name' => $payout->member?->full_name,
            'amount_ngwee' => Kwacha::toNgwee($payout->amount_ngwee),
            'reason' => $reason,
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    protected function walletRow(Wallet $wallet, ?string $reason): array
    {
        return array_filter([
            'wallet_id' => $wallet->id,
            'member_id' => $wallet->member_id,
            'member_number' => $wallet->member?->member_number,
            'full_name' => $wallet->member?->full_name,
            'amount_ngwee' => $this->withdrawals->availableNgwee($wallet),
            'reason' => $reason,
        ], fn (mixed $value): bool => $value !== null);
    }
}
