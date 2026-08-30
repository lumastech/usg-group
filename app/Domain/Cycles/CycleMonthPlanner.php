<?php

namespace App\Domain\Cycles;

use App\Enums\CycleMonthStatus;
use App\Enums\InterestAllocationMethod;
use App\Enums\WeekendTradingPolicy;
use App\Models\Cycle;
use App\Models\CycleMonth;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the month-by-month calendar for a cycle.
 *
 * Declarations run from 08:00 on the 1st to the end of the 3rd. Trading runs from the
 * 4th and concludes on the disbursement date, which is the 7th unless the 7th falls on
 * a weekend, in which case the cycle's weekend policy moves it.
 */
class CycleMonthPlanner
{
    public const DECLARATIONS_OPEN_DAY = 1;

    public const DECLARATIONS_OPEN_HOUR = 8;

    public const DECLARATIONS_CLOSE_DAY = 3;

    public const TRADING_START_DAY = 4;

    public const DISBURSEMENT_DAY = 7;

    /**
     * Resolves the disbursement date for a month, honouring the weekend policy.
     *
     * The 7th is used as-is on a weekday. On a Saturday or Sunday the cycle policy
     * decides whether trading concludes on the Friday before or the Monday after.
     */
    public function disbursementDateFor(CarbonInterface $month, WeekendTradingPolicy $policy): CarbonInterface
    {
        $seventh = $month->copy()->startOfMonth()->addDays(self::DISBURSEMENT_DAY - 1);

        if (! $seventh->isWeekend()) {
            return $seventh;
        }

        return $policy === WeekendTradingPolicy::PreviousFriday
            ? $seventh->copy()->previous(Carbon::FRIDAY)
            : $seventh->copy()->next(Carbon::MONDAY);
    }

    /**
     * Creates every month of the cycle. Existing months are left untouched, so this is
     * safe to re-run after a cycle's dates or policy change.
     *
     * @return Collection<int, CycleMonth>
     */
    public function plan(Cycle $cycle): Collection
    {
        $months = collect();
        $month = $cycle->starts_on->copy()->startOfMonth();
        $sequence = 1;

        while ($month->lessThanOrEqualTo($cycle->ends_on)) {
            $months->push($this->planMonth($cycle, $month, $sequence));

            $month = $month->copy()->addMonth();
            $sequence++;
        }

        return $months;
    }

    /**
     * The constitution's dates for one calendar month.
     *
     * The only place the 1st/3rd/4th/7th shape is turned into dates. The settings
     * screen restores a month from here, so "back to the constitution" and a freshly
     * planned cycle can never mean two different things.
     *
     * @return array{declarations_open_at: CarbonInterface, declarations_close_at: CarbonInterface, trading_starts_on: CarbonInterface, trading_concludes_on: CarbonInterface, disbursement_on: CarbonInterface}
     */
    public function datesFor(CarbonInterface $month, WeekendTradingPolicy $policy): array
    {
        $disbursement = $this->disbursementDateFor($month, $policy);

        return [
            'declarations_open_at' => $month->copy()->startOfMonth()
                ->addDays(self::DECLARATIONS_OPEN_DAY - 1)
                ->setTime(self::DECLARATIONS_OPEN_HOUR, 0),
            'declarations_close_at' => $month->copy()->startOfMonth()
                ->addDays(self::DECLARATIONS_CLOSE_DAY - 1)
                ->endOfDay(),
            'trading_starts_on' => $month->copy()->startOfMonth()
                ->addDays(self::TRADING_START_DAY - 1),
            'trading_concludes_on' => $disbursement,
            'disbursement_on' => $disbursement,
        ];
    }

    protected function planMonth(Cycle $cycle, CarbonInterface $month, int $sequence): CycleMonth
    {
        return CycleMonth::updateOrCreate(
            ['cycle_id' => $cycle->id, 'sequence' => $sequence],
            $this->datesFor($month, $cycle->weekend_trading_policy) + [
                'month' => $month->copy()->startOfMonth(),
                'interest_allocation_method' => $sequence === 1
                    ? InterestAllocationMethod::OwnSavingsFlat
                    : InterestAllocationMethod::PooledProRata,
                'status' => CycleMonthStatus::Pending,
            ],
        );
    }
}
