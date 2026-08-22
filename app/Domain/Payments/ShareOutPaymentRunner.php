<?php

namespace App\Domain\Payments;

use App\Exceptions\DomainRuleException;
use App\Exceptions\PaymentGatewayException;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\Payout;
use App\Support\Kwacha;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Throwable;

/**
 * Pays the share-out schedule out through the gateway, one member at a time.
 *
 * The settling already happened: ShareOutBatchRunner signed and froze every position,
 * and each Payout is a decision the group has taken. This is only the money leaving,
 * and it is kept separate on purpose — a network failure must not be able to reach
 * back into a transaction that froze somebody's ledgers.
 *
 * Transfers go one at a time with their own references rather than as one bulk call, so
 * a single bad account number cannot hold up twenty-nine other members' money. A member
 * with no destination on file is not a failure; they are simply paid by hand, and the
 * report says so.
 */
class ShareOutPaymentRunner
{
    public function __construct(
        protected TransferInitiator $transfers,
        protected PayoutDestinationService $destinations,
    ) {}

    /**
     * What the run would attempt, and whether the group can afford it today.
     *
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     payable_count: int,
     *     payable_ngwee: int,
     *     by_hand_count: int,
     *     by_hand_ngwee: int,
     *     balance_ngwee: int|null,
     *     shortfall_ngwee: int,
     *     can_run: bool,
     * }
     */
    public function preview(Cycle $cycle): array
    {
        $rows = $this->unpaid($cycle)->map(function (Payout $payout): array {
            $member = $payout->member;
            $destination = $member === null ? null : $this->transfers->destinationFor($member);

            return [
                'payout_id' => $payout->id,
                'member_id' => $payout->member_id,
                'member_number' => $member?->member_number,
                'full_name' => $member?->full_name,
                'amount_ngwee' => Kwacha::toNgwee($payout->amount_ngwee),
                'destination' => $destination?->label(),
                'destination_id' => $destination?->id,
                'account_name' => $destination?->resolved_account_name,
                'name_match_score' => $destination?->name_match_score,
                'needs_confirmation' => $destination !== null && $this->destinations->needsSecondSignature($destination),
                'by_hand' => $destination === null,
            ];
        })->values()->all();

        $payable = array_values(array_filter($rows, fn (array $row): bool => ! $row['by_hand']));
        $byHand = array_values(array_filter($rows, fn (array $row): bool => $row['by_hand']));

        $payableNgwee = array_sum(array_column($payable, 'amount_ngwee'));
        $balance = $this->balance();
        $headroom = (int) config('payments.transfers.balance_headroom_ngwee', 0);
        $shortfall = $balance === null ? 0 : max(0, $payableNgwee - ($balance - $headroom));

        return [
            'rows' => $rows,
            'payable_count' => count($payable),
            'payable_ngwee' => $payableNgwee,
            'by_hand_count' => count($byHand),
            'by_hand_ngwee' => array_sum(array_column($byHand, 'amount_ngwee')),
            'balance_ngwee' => $balance,
            'shortfall_ngwee' => $shortfall,
            'can_run' => $payable !== [] && $shortfall === 0,
        ];
    }

    /**
     * Sends every unpaid payout that has somewhere to go.
     *
     * The whole batch is checked against the group's balance before the first transfer
     * leaves: finding out halfway down a list of thirty that the account is empty leaves
     * the group in the worst position of all, half paid with no record of who is next.
     *
     * @return array{
     *     sent: array<int, array<string, mixed>>,
     *     failed: array<int, array<string, mixed>>,
     *     by_hand: array<int, array<string, mixed>>,
     *     sent_count: int,
     *     failed_count: int,
     *     sent_ngwee: int,
     * }
     */
    public function run(Cycle $cycle, Member $actor, Member $secondApprover): array
    {
        $preview = $this->preview($cycle);

        if ($preview['payable_count'] === 0) {
            throw DomainRuleException::make('There is nothing left to send: every payout is paid or paid by hand.');
        }

        $this->transfers->assertFundsAvailable($preview['payable_ngwee']);

        $sent = [];
        $failed = [];
        $byHand = [];

        foreach ($this->unpaid($cycle) as $payout) {
            $member = $payout->member;

            if ($member === null || $this->transfers->destinationFor($member) === null) {
                $byHand[] = $this->row($payout, 'No destination on file — pay this one by hand.');

                continue;
            }

            try {
                $intent = $this->transfers->payPayout($payout, $actor, $secondApprover);
            } catch (DomainRuleException|PaymentGatewayException $exception) {
                $failed[] = $this->row($payout, $exception->getMessage());

                continue;
            } catch (Throwable $exception) {
                $failed[] = $this->row($payout, $exception->getMessage());

                continue;
            }

            $sent[] = $this->row($payout, null) + [
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
            ->acrossCycles()
            ->where('cycle_id', $cycle->id)
            ->whereNull('paid_at')
            ->with('member.defaultPayoutDestination')
            ->get()
            ->sortBy(fn (Payout $payout): int => $payout->member->member_number ?? PHP_INT_MAX)
            ->values();
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

    /** The balance is shown even when the provider is down; the run itself will refuse. */
    protected function balance(): ?int
    {
        try {
            return app(PaymentGateway::class)->balanceNgwee();
        } catch (Throwable) {
            return null;
        }
    }
}
