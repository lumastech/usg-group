<?php

namespace App\Domain\SocialFund;

use App\Domain\Approvals\TwoPersonRule;
use App\Domain\Support\MoneyMutator;
use App\Enums\ApportionmentItemStatus;
use App\Enums\MemberStatus;
use App\Enums\SocialFundTransactionType;
use App\Exceptions\DomainRuleException;
use App\Exceptions\InsufficientSocialFundException;
use App\Models\Cycle;
use App\Models\DiasporaApportionment;
use App\Models\DiasporaApportionmentItem;
use App\Models\Member;
use App\Models\SocialFundTransaction;
use App\Support\Kwacha;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Splits a sum equally across the members living abroad.
 *
 * The split is deliberately floor division: whatever will not divide into whole ngwee
 * stays in the fund rather than being handed to whoever happens to sort first. Nobody
 * receives a ngwee more than anybody else, and the fund is never a ngwee short.
 *
 * Confirming a split does not move money. Each share is a pending item until the
 * treasurer ticks the transfer off, and only then is the outflow posted.
 */
class DiasporaApportionmentService
{
    public function __construct(
        protected SocialFundLedger $ledger,
        protected MoneyMutator $mutator,
        protected TwoPersonRule $twoPersonRule,
    ) {}

    /**
     * The equal split, without writing anything — this is what the calculator previews.
     *
     * @return array{
     *     total_ngwee: int, share_ngwee: int, remainder_ngwee: int, apportioned_ngwee: int,
     *     recipients: array<int, array{member_id: int, member_number: int, full_name: string, amount_ngwee: int}>
     * }
     */
    public function preview(Cycle $cycle, Money $total): array
    {
        $recipients = $this->recipients($cycle);
        $totalNgwee = Kwacha::toNgwee($total);
        $count = $recipients->count();

        $share = $count === 0 ? 0 : intdiv($totalNgwee, $count);
        $apportioned = $share * $count;

        return [
            'total_ngwee' => $totalNgwee,
            'share_ngwee' => $share,
            'remainder_ngwee' => $totalNgwee - $apportioned,
            'apportioned_ngwee' => $apportioned,
            'recipients' => $recipients->map(fn (Member $member): array => [
                'member_id' => $member->id,
                'member_number' => $member->member_number,
                'full_name' => $member->full_name,
                'amount_ngwee' => $share,
            ])->values()->all(),
        ];
    }

    /**
     * Confirms the split into a batch of pending shares.
     *
     * The whole apportioned sum is checked against the balance here, so a split that
     * the fund could only half honour is refused up front rather than stalling
     * halfway down the checklist.
     */
    public function create(
        Cycle $cycle,
        Money $total,
        Member $actor,
        Member $secondApprover,
        ?CarbonInterface $declaredOn = null,
        ?string $note = null,
    ): DiasporaApportionment {
        $preview = $this->preview($cycle, $total);
        $recipients = $this->recipients($cycle);

        if ($recipients->isEmpty()) {
            throw DomainRuleException::make('No active member is recorded as living in the diaspora.');
        }

        if ($preview['share_ngwee'] <= 0) {
            throw DomainRuleException::make(
                'That total does not divide into a share for each of the '
                .$recipients->count().' diaspora members.'
            );
        }

        $this->twoPersonRule->assertDistinctCommittee($actor, $secondApprover);

        foreach ([$actor, $secondApprover] as $approver) {
            if ($recipients->contains(fn (Member $member): bool => $member->is($approver))) {
                throw DomainRuleException::make(
                    "{$approver->full_name} receives a share of this apportionment and cannot approve it."
                );
            }
        }

        $balance = Kwacha::toNgwee($this->ledger->balance($cycle));

        if ($balance - $preview['apportioned_ngwee'] < 0) {
            throw new InsufficientSocialFundException(
                'The social fund holds '.Kwacha::format($balance).', which does not cover '
                .Kwacha::format($preview['apportioned_ngwee']).'.'
            );
        }

        $date = $declaredOn ?? Carbon::today();

        return $this->mutator->mutate(
            $actor,
            'Apportioned '.Kwacha::format($preview['apportioned_ngwee']).' across '
                .$recipients->count().' diaspora members',
            function () use ($cycle, $preview, $recipients, $date, $actor, $secondApprover, $note): DiasporaApportionment {
                $apportionment = DiasporaApportionment::create([
                    'cycle_id' => $cycle->id,
                    'total_ngwee' => $preview['total_ngwee'],
                    'apportioned_ngwee' => $preview['apportioned_ngwee'],
                    'share_ngwee' => $preview['share_ngwee'],
                    'remainder_ngwee' => $preview['remainder_ngwee'],
                    'declared_on' => $date,
                    'recorded_by_member_id' => $actor->id,
                    'second_approver_member_id' => $secondApprover->id,
                    'note' => $note,
                ]);

                foreach ($recipients as $member) {
                    $apportionment->items()->create([
                        'member_id' => $member->id,
                        'amount_ngwee' => $preview['share_ngwee'],
                        'status' => ApportionmentItemStatus::Pending,
                    ]);
                }

                return $apportionment;
            },
            ['cycle_id' => $cycle->id, 'apportioned_ngwee' => $preview['apportioned_ngwee']],
        );
    }

    /**
     * Ticks a transfer off, which is what debits the fund.
     *
     * The two signatures the batch already carries are the ones recorded against the
     * entry — a treasurer working down the checklist is confirming a transfer, not
     * authorising a fresh payment.
     */
    public function confirmTransfer(
        DiasporaApportionmentItem $item,
        Member $actor,
        ?CarbonInterface $paidOn = null,
        ?string $reference = null,
    ): SocialFundTransaction {
        if ($item->status !== ApportionmentItemStatus::Pending) {
            throw DomainRuleException::make(
                'This share is already '.$item->status->label().'.'
            );
        }

        $apportionment = $item->apportionment;
        $date = $paidOn ?? Carbon::today();

        $transaction = $this->ledger->pay(
            $apportionment->cycle,
            SocialFundTransactionType::DiasporaApportionment,
            $item->amount_ngwee,
            $date,
            $apportionment->recordedBy ?? $actor,
            $apportionment->secondApprover ?? $actor,
            $item->member,
            $item,
            'Diaspora apportionment of '.Kwacha::format($apportionment->total_ngwee)
                .' declared '.$apportionment->declared_on->format('j M Y'),
        );

        $item->forceFill([
            'status' => ApportionmentItemStatus::Paid,
            'paid_on' => $date,
            'confirmed_by_member_id' => $actor->id,
            'reference' => $reference,
        ])->save();

        return $transaction;
    }

    /**
     * Active members recorded as living abroad.
     *
     * @return Collection<int, Member>
     */
    public function recipients(Cycle $cycle): Collection
    {
        return $cycle->members()
            ->where('status', MemberStatus::Active)
            ->where('is_diaspora', true)
            ->get();
    }
}
