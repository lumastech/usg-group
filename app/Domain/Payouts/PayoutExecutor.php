<?php

namespace App\Domain\Payouts;

use App\Domain\Approvals\TwoPersonRule;
use App\Domain\SocialFund\SocialFundLedger;
use App\Domain\Support\MoneyMutator;
use App\Enums\PayoutCase;
use App\Enums\SocialFundTransactionType;
use App\Exceptions\DomainRuleException;
use App\Models\Member;
use App\Models\MemberDebt;
use App\Models\NextOfKin;
use App\Models\NextOfKinRepaymentArrangement;
use App\Models\Payout;
use App\Support\Kwacha;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The only way a closure is ever settled.
 *
 * Everything that makes a settlement irreversible happens here, in one transaction:
 * the gate on the cycle's status, the two signatures, the freeze on the member's
 * ledgers, and the record itself — a payout, or, where the member owes more than they
 * saved, a debt or a repayment arrangement. A negative amount is never paid.
 *
 * The breakdown is recomputed here rather than accepted from the request. What the
 * committee approved on screen was a view of the ledgers; what is stored must be the
 * ledgers' own answer at the moment of execution.
 */
class PayoutExecutor
{
    public function __construct(
        protected PayoutCalculator $calculator,
        protected TwoPersonRule $twoPersonRule,
        protected LedgerFreeze $freeze,
        protected SocialFundLedger $fund,
        protected MoneyMutator $mutator,
    ) {}

    /**
     * Settles a member's closure.
     *
     * @param  array{
     *     early_settlement_note?: string|null,
     *     note?: string|null,
     *     agreed_terms?: string|null,
     *     next_of_kin_id?: int|null,
     *     agreed_on?: string|null,
     * }  $context
     * @return Payout|MemberDebt|NextOfKinRepaymentArrangement
     */
    public function execute(Member $member, Member $actor, Member $secondApprover, array $context = []): Model
    {
        $case = PayoutCase::forStatus($member->status);
        $breakdown = $this->calculator->using($member, $case);

        $this->assertNotAlreadySettled($member);
        $this->assertMayBeSettledNow($member, $case, $context);
        $this->twoPersonRule->assertDistinctCommittee($actor, $secondApprover, $member);

        if ($breakdown->isNegative()) {
            $this->assertTermsGiven($case, $context);
        }

        $reason = $breakdown->isNegative()
            ? "Recorded a shortfall of {$this->format($breakdown->shortfallNgwee())} against {$member->full_name} ({$case->label()})"
            : "Paid out {$this->format($breakdown->payableNgwee())} to {$member->full_name} ({$case->label()})";

        return $this->mutator->mutate(
            $actor,
            $reason,
            function () use ($member, $actor, $secondApprover, $case, $breakdown, $context): Model {
                $record = $breakdown->isNegative()
                    ? $this->recordShortfall($member, $actor, $secondApprover, $case, $breakdown, $context)
                    : $this->recordPayout($member, $actor, $secondApprover, $case, $breakdown, $context);

                if ($record instanceof Payout) {
                    $this->postRoundOff($member, $actor, $secondApprover, $breakdown, $record);
                }

                $this->freeze->freeze($member);

                return $record;
            },
            [
                'member_id' => $member->id,
                'case' => $case->value,
                'net_value_ngwee' => $breakdown->netValueNgwee,
                'net_payable_ngwee' => $breakdown->netPayableNgwee,
                'second_approver_member_id' => $secondApprover->id,
            ],
        );
    }

    /**
     * A closure a member is in credit on: the money is handed over and recorded.
     *
     * @param  array<string, mixed>  $context
     */
    protected function recordPayout(
        Member $member,
        Member $actor,
        Member $secondApprover,
        PayoutCase $case,
        PayoutBreakdown $breakdown,
        array $context,
    ): Payout {
        return Payout::create([
            'cycle_id' => $member->cycle_id,
            'member_id' => $member->id,
            'case' => $case,
            'breakdown' => $breakdown->toArray(),
            'net_value_ngwee' => $breakdown->netValueNgwee,
            'round_off_ngwee' => $breakdown->roundOffNgwee,
            'amount_ngwee' => $breakdown->payableNgwee(),
            'executed_at' => Carbon::now(),
            'executed_by_member_id' => $actor->id,
            'second_approver_member_id' => $secondApprover->id,
            'early_settlement_note' => $context['early_settlement_note'] ?? null,
            'note' => $context['note'] ?? null,
        ]);
    }

    /**
     * A closure that came out under water. Nothing is paid; the shortfall is recorded.
     *
     * A death produces an arrangement with the next of kin, because there is somebody
     * to agree terms with. Anyone else leaves a debt in their own name.
     *
     * @param  array<string, mixed>  $context
     */
    protected function recordShortfall(
        Member $member,
        Member $actor,
        Member $secondApprover,
        PayoutCase $case,
        PayoutBreakdown $breakdown,
        array $context,
    ): Model {
        if ($case !== PayoutCase::Deceased) {
            return MemberDebt::create([
                'cycle_id' => $member->cycle_id,
                'member_id' => $member->id,
                'case' => $case,
                'amount_owed_ngwee' => $breakdown->shortfallNgwee(),
                'breakdown' => $breakdown->toArray(),
                'recorded_by_member_id' => $actor->id,
                'second_approver_member_id' => $secondApprover->id,
                'note' => $context['note'] ?? null,
            ]);
        }

        return NextOfKinRepaymentArrangement::create([
            'cycle_id' => $member->cycle_id,
            'member_id' => $member->id,
            'next_of_kin_id' => $this->nextOfKinId($member, $context),
            'amount_owed_ngwee' => $breakdown->shortfallNgwee(),
            'agreed_terms' => (string) $context['agreed_terms'],
            'breakdown' => $breakdown->toArray(),
            'agreed_on' => filled($context['agreed_on'] ?? null)
                ? Carbon::parse((string) $context['agreed_on'])
                : Carbon::today(),
            'recorded_by_member_id' => $actor->id,
            'second_approver_member_id' => $secondApprover->id,
            'note' => $context['note'] ?? null,
        ]);
    }

    /**
     * Sends the round-off difference to the Social Fund.
     *
     * With NoRounding bound this never runs — the adjustment is always zero. When the
     * group does adopt a convention, the ngwee shaved off each payout land in the fund
     * rather than evaporating, and a top-up is drawn from it under the same two
     * signatures that stand behind every other outflow.
     */
    protected function postRoundOff(
        Member $member,
        Member $actor,
        Member $secondApprover,
        PayoutBreakdown $breakdown,
        Payout $payout,
    ): void {
        if ($breakdown->roundOffNgwee === 0) {
            return;
        }

        $amount = Kwacha::ofNgwee(abs($breakdown->roundOffNgwee));
        $note = 'Round-off on the payout to '.$member->full_name;

        if ($breakdown->roundOffNgwee < 0) {
            $this->fund->receive(
                $member->cycle,
                SocialFundTransactionType::Adjustment,
                $amount,
                Carbon::today(),
                $member,
                $actor,
                $payout,
                $note,
            );

            return;
        }

        $this->fund->pay(
            $member->cycle,
            SocialFundTransactionType::Adjustment,
            $amount,
            Carbon::today(),
            $actor,
            $secondApprover,
            $member,
            $payout,
            $note,
        );
    }

    /**
     * Whether the cycle has reached the point where this closure may be settled.
     *
     * Nobody is settled before share-out, with one exception the constitution makes on
     * compassion rather than on accounting: a death may be settled early so a family is
     * not left waiting for November. That override is deliberate, so it costs a written
     * reason, and the reason is stored on the payout.
     *
     * @param  array<string, mixed>  $context
     */
    protected function assertMayBeSettledNow(Member $member, PayoutCase $case, array $context): void
    {
        if ($member->cycle->status->isSharingOut()) {
            return;
        }

        if (! $case->allowsEarlySettlement()) {
            throw DomainRuleException::make(
                "The cycle is {$member->cycle->status->label()}. Closures are settled at share-out, so "
                ."{$member->full_name} cannot be paid out yet."
            );
        }

        if (blank($context['early_settlement_note'] ?? null)) {
            throw DomainRuleException::make(
                'Settling a death before share-out is a committee override and needs a written reason.'
            );
        }
    }

    /** @param  array<string, mixed>  $context */
    protected function assertTermsGiven(PayoutCase $case, array $context): void
    {
        if ($case === PayoutCase::Deceased && blank($context['agreed_terms'] ?? null)) {
            throw DomainRuleException::make(
                'This estate owes the group more than it saved, so there is nothing to pay out. '
                .'Record the terms the next of kin has agreed to instead.'
            );
        }
    }

    protected function assertNotAlreadySettled(Member $member): void
    {
        if ($member->ledgersFrozen()) {
            throw DomainRuleException::make(
                "{$member->full_name} has already been settled and their ledgers are closed."
            );
        }
    }

    /** @param  array<string, mixed>  $context */
    protected function nextOfKinId(Member $member, array $context): ?int
    {
        $given = $context['next_of_kin_id'] ?? null;

        if ($given !== null) {
            $nominated = NextOfKin::query()
                ->where('member_id', $member->id)
                ->whereKey($given)
                ->first();

            if ($nominated === null) {
                throw DomainRuleException::make(
                    "That next of kin is not one of {$member->full_name}'s nominees."
                );
            }

            return $nominated->id;
        }

        return $member->nextOfKin()->value('id');
    }

    protected function format(int $ngwee): string
    {
        return Kwacha::format($ngwee);
    }
}
