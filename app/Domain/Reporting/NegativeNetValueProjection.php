<?php

namespace App\Domain\Reporting;

use App\Domain\Payouts\PayoutCalculator;
use App\Models\Cycle;
use App\Models\Member;

/**
 * The workbook's "Min Repayments-Negative NV" sheet.
 *
 * A member whose loan has outrun their savings has a negative Net Value: at share-out
 * they would be handed nothing and would still owe the group the difference. That
 * difference does not stand still — 5% a month is charged on the loan behind it — so
 * the committee needs to know what the member must pay each month to be level again,
 * not merely what they owe today.
 *
 * The projection amortises the shortfall over three months at the cycle's own monthly
 * rate. Interest is charged on the opening balance first and the repayment lands after
 * it, which is the order the trading day itself follows; anything else would flatter
 * the figure the group quotes to a member who is already behind.
 */
class NegativeNetValueProjection
{
    /** How many months the group gives a member to come back above water. */
    public const HORIZON_MONTHS = 3;

    public function __construct(protected PayoutCalculator $calculator) {}

    /**
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     totals: array<string, mixed>,
     *     horizon_months: int,
     *     monthly_rate_bps: int,
     * }
     */
    public function for(Cycle $cycle, int $horizonMonths = self::HORIZON_MONTHS): array
    {
        $rows = $cycle->members()->get()
            ->map(fn (Member $member): array => $this->row($cycle, $member, $horizonMonths))
            ->filter(fn (array $row): bool => $row['shortfall_ngwee'] > 0)
            ->sortByDesc('shortfall_ngwee')
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'totals' => [
                'members' => count($rows),
                'shortfall_ngwee' => array_sum(array_column($rows, 'shortfall_ngwee')),
                'minimum_monthly_ngwee' => array_sum(array_column($rows, 'minimum_monthly_ngwee')),
                'total_repayable_ngwee' => array_sum(array_column($rows, 'total_repayable_ngwee')),
            ],
            'horizon_months' => $horizonMonths,
            'monthly_rate_bps' => $cycle->monthly_interest_bps,
        ];
    }

    /** Just the count, for the dashboard's risk tile. */
    public function count(Cycle $cycle): int
    {
        return count($this->for($cycle)['rows']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(Cycle $cycle, Member $member, int $horizonMonths): array
    {
        $breakdown = $this->calculator->for($member);
        $shortfall = $breakdown->shortfallNgwee();
        $schedule = $shortfall > 0
            ? $this->schedule($shortfall, $cycle->monthly_interest_bps, $horizonMonths)
            : [];

        return [
            'member_id' => $member->id,
            'member_number' => $member->member_number,
            'full_name' => $member->full_name,
            'status' => $member->status,
            'status_label' => $member->status->label(),
            'net_value_ngwee' => $breakdown->netValueNgwee,
            'shortfall_ngwee' => $shortfall,
            'schedule' => $schedule,
            'minimum_monthly_ngwee' => $schedule === [] ? 0 : $schedule[0]['repayment_ngwee'],
            'total_repayable_ngwee' => array_sum(array_column($schedule, 'repayment_ngwee')),
            'interest_ngwee' => array_sum(array_column($schedule, 'interest_ngwee')),
            'href' => "/app/members/{$member->id}",
        ];
    }

    /**
     * Amortises a shortfall over the horizon, month by month.
     *
     * The level payment is rounded up to the ngwee so the balance genuinely reaches
     * zero — rounding it down would leave a few ngwee outstanding and a fourth month
     * on a three-month plan. The final month then pays whatever is actually left, which
     * is why the last row is usually a little smaller than the others.
     *
     * @return array<int, array{month: int, opening_ngwee: int, interest_ngwee: int, repayment_ngwee: int, closing_ngwee: int}>
     */
    public function schedule(int $shortfallNgwee, int $monthlyRateBps, int $horizonMonths): array
    {
        $level = $this->levelPaymentNgwee($shortfallNgwee, $monthlyRateBps, $horizonMonths);

        $balance = $shortfallNgwee;
        $schedule = [];

        for ($month = 1; $month <= $horizonMonths; $month++) {
            $interest = $this->interestOn($balance, $monthlyRateBps);
            $due = $balance + $interest;
            $repayment = min($level, $due);

            $schedule[] = [
                'month' => $month,
                'opening_ngwee' => $balance,
                'interest_ngwee' => $interest,
                'repayment_ngwee' => $repayment,
                'closing_ngwee' => $due - $repayment,
            ];

            $balance = $due - $repayment;
        }

        return $schedule;
    }

    /**
     * The standard amortisation payment: B·rⁿ(r−1)/(rⁿ−1), rounded up to the ngwee.
     *
     * At a zero rate it degrades to an even split, which is what the group would do by
     * hand — the formula itself divides by zero there.
     */
    protected function levelPaymentNgwee(int $balanceNgwee, int $monthlyRateBps, int $horizonMonths): int
    {
        if ($horizonMonths < 1) {
            return $balanceNgwee;
        }

        if ($monthlyRateBps === 0) {
            return (int) ceil($balanceNgwee / $horizonMonths);
        }

        $rate = $monthlyRateBps / 10_000;
        $growth = (1 + $rate) ** $horizonMonths;

        return (int) ceil($balanceNgwee * $growth * $rate / ($growth - 1));
    }

    /** The month's charge, rounded the way InterestEngine rounds it on a real loan. */
    protected function interestOn(int $balanceNgwee, int $monthlyRateBps): int
    {
        return (int) round($balanceNgwee * $monthlyRateBps / 10_000);
    }
}
