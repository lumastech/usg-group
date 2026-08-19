<?php

namespace App\Domain\Savings;

use App\Domain\Support\MoneyMutator;
use App\Enums\SavingsTransactionType;
use App\Enums\TransactionSource;
use App\Exceptions\InvalidSavingsAmountException;
use App\Models\CycleMonth;
use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Support\Kwacha;
use Brick\Money\Money;
use Carbon\CarbonInterface;

/**
 * Records savings and enforces the constitution's savings rules.
 *
 * Monthly savings are mandatory, start at K500 and move in K500 steps. From September
 * to the end of the cycle a member may save no more than K500 in a month.
 */
class SavingsLedger
{
    public function __construct(protected MoneyMutator $mutator) {}

    public function record(
        Member $member,
        CycleMonth $month,
        Money $amount,
        Member $actor,
        SavingsTransactionType $type = SavingsTransactionType::Contribution,
        ?Money $declared = null,
        TransactionSource $source = TransactionSource::Manual,
        ?CarbonInterface $occurredOn = null,
    ): SavingsTransaction {
        if ($type->followsIncrementRules()) {
            $this->assertValidContribution($member, $month, $amount);
        }

        return $this->mutator->mutate(
            $actor,
            "Recorded {$type->value} of ".Kwacha::format($amount)." for {$member->full_name} ({$month->label()})",
            fn (): SavingsTransaction => SavingsTransaction::create([
                'member_id' => $member->id,
                'cycle_month_id' => $month->id,
                'type' => $type,
                'amount_ngwee' => $amount,
                'declared_amount_ngwee' => $declared,
                'recorded_by_member_id' => $actor->id,
                'source' => $source,
                'occurred_on' => $occurredOn ?? $month->month,
            ]),
            ['member_id' => $member->id, 'cycle_month_id' => $month->id],
        );
    }

    /**
     * Throws unless the amount satisfies the minimum, the K500 increment and, in the
     * lockdown months, the monthly cap.
     */
    public function assertValidContribution(Member $member, CycleMonth $month, Money $amount): void
    {
        $cycle = $month->cycle;
        $ngwee = Kwacha::toNgwee($amount);
        $minimum = Kwacha::toNgwee($cycle->min_savings_ngwee);
        $increment = Kwacha::toNgwee($cycle->savings_increment_ngwee);

        if ($ngwee < $minimum) {
            throw new InvalidSavingsAmountException(
                'Monthly savings must be at least '.Kwacha::format($cycle->min_savings_ngwee).'.'
            );
        }

        if ($ngwee % $increment !== 0) {
            throw new InvalidSavingsAmountException(
                'Savings must be in increments of '.Kwacha::format($cycle->savings_increment_ngwee).'.'
            );
        }

        $cap = $cycle->savingsCapForMonth($month->sequence);

        if ($cap !== null && $ngwee > Kwacha::toNgwee($cap)) {
            throw new InvalidSavingsAmountException(
                'From September to the end of the cycle savings are capped at '.Kwacha::format($cap).' a month.'
            );
        }
    }

    /** Total contributed by a member across the whole cycle up to and including a month. */
    public function cumulativeSavings(Member $member, CycleMonth $upTo): Money
    {
        $total = SavingsTransaction::query()
            ->where('member_id', $member->id)
            ->whereIn('cycle_month_id', $this->monthIdsUpTo($upTo))
            ->whereIn('type', [
                SavingsTransactionType::Contribution->value,
                SavingsTransactionType::Adjustment->value,
                SavingsTransactionType::ImportOpening->value,
            ])
            ->sum('amount_ngwee');

        return Kwacha::ofNgwee((int) $total);
    }

    /**
     * Month ids from the start of the cycle up to and including the given month.
     *
     * @return array<int, int>
     */
    public function monthIdsUpTo(CycleMonth $upTo): array
    {
        return CycleMonth::query()
            ->where('cycle_id', $upTo->cycle_id)
            ->where('sequence', '<=', $upTo->sequence)
            ->pluck('id')
            ->all();
    }
}
