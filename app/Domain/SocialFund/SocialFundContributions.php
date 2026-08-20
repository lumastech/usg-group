<?php

namespace App\Domain\SocialFund;

use App\Enums\MemberStatus;
use App\Enums\SocialFundTransactionType;
use App\Exceptions\InvalidSocialFundContributionException;
use App\Exceptions\MemberNotActiveException;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\SocialFundTransaction;
use App\Support\Kwacha;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The K250 every member pays into the Social Fund, once for the whole cycle.
 *
 * There is no part payment and no second payment: the constitution sets one figure and
 * one occasion, so anything else is refused rather than recorded and reconciled later.
 */
class SocialFundContributions
{
    public function __construct(protected SocialFundLedger $ledger) {}

    public function record(
        Member $member,
        Money $amount,
        Member $actor,
        ?CarbonInterface $occurredOn = null,
        ?string $note = null,
    ): SocialFundTransaction {
        $cycle = $member->cycle;

        $this->assertPayable($member, $cycle, $amount);

        return $this->ledger->receive(
            $cycle,
            SocialFundTransactionType::Contribution,
            $amount,
            $occurredOn ?? Carbon::today(),
            $member,
            $actor,
            note: $note,
        );
    }

    /** Throws unless this member may pay this exact amount right now. */
    public function assertPayable(Member $member, Cycle $cycle, Money $amount): void
    {
        if ($member->status !== MemberStatus::Active) {
            throw new MemberNotActiveException(
                "{$member->full_name} is {$member->status->label()} and cannot pay into the social fund."
            );
        }

        $expected = Kwacha::toNgwee($cycle->social_fund_contribution_ngwee);

        if (Kwacha::toNgwee($amount) !== $expected) {
            throw new InvalidSocialFundContributionException(
                'The social fund contribution is '.Kwacha::format($expected)
                .' exactly, paid in full — '.Kwacha::format($amount).' cannot be accepted.'
            );
        }

        if ($this->hasPaid($member)) {
            throw new InvalidSocialFundContributionException(
                "{$member->full_name} has already paid the social fund contribution for this cycle."
            );
        }
    }

    public function hasPaid(Member $member): bool
    {
        return SocialFundTransaction::query()
            ->acrossCycles()
            ->where('cycle_id', $member->cycle_id)
            ->where('member_id', $member->id)
            ->where('type', SocialFundTransactionType::Contribution->value)
            ->exists();
    }

    /**
     * Active members who still owe the contribution, in member-number order.
     *
     * @return Collection<int, Member>
     */
    public function outstanding(Cycle $cycle): Collection
    {
        $paid = SocialFundTransaction::query()
            ->forCycle($cycle)
            ->where('type', SocialFundTransactionType::Contribution->value)
            ->whereNotNull('member_id')
            ->pluck('member_id')
            ->all();

        return $cycle->members()
            ->where('status', MemberStatus::Active)
            ->when($paid !== [], fn ($query) => $query->whereNotIn('id', $paid))
            ->get();
    }
}
