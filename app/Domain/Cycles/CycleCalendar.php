<?php

namespace App\Domain\Cycles;

use App\Enums\CycleMonthStatus;
use App\Enums\CycleStatus;
use App\Exceptions\DomainRuleException;
use App\Models\CycleMonth;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Moving a month's declaration and trading dates.
 *
 * Every window in the portal is read from the `cycle_months` row — DeclarationWindow,
 * the trading console, the notification scheduler — so the calendar is the one place a
 * date is decided and this is the one place a date may be changed. Nothing here is a
 * bypass: widening a window opens the real code path, with the real validation and the
 * real policies still doing their jobs.
 *
 * Two rules shape everything below. A month's dates stay inside that calendar month,
 * because the shell resolves "this month" by the calendar and a window that outlived
 * its month would leave the banner and the form disagreeing. And a month that has been
 * traded and closed is history: its dates are the record of a session that happened.
 */
class CycleCalendar
{
    public function __construct(protected CycleMonthPlanner $planner) {}

    /**
     * Re-dates one month.
     *
     * The declaration window may be reopened this way as long as the month has not been
     * concluded — that is what makes a missed window recoverable for the whole group,
     * where the treasurer's on-behalf capture recovers it one member at a time.
     */
    public function reschedule(
        CycleMonth $month,
        CarbonInterface $declarationsOpenAt,
        CarbonInterface $declarationsCloseAt,
        CarbonInterface $tradingStartsOn,
        CarbonInterface $tradingConcludesOn,
        ?CarbonInterface $disbursementOn = null,
    ): CycleMonth {
        $disbursementOn ??= $tradingConcludesOn;

        $this->assertReschedulable($month);

        $dates = [
            'declarations_open_at' => $declarationsOpenAt->copy(),
            'declarations_close_at' => $declarationsCloseAt->copy(),
            'trading_starts_on' => $tradingStartsOn->copy()->startOfDay(),
            'trading_concludes_on' => $tradingConcludesOn->copy()->startOfDay(),
            'disbursement_on' => $disbursementOn->copy()->startOfDay(),
        ];

        $this->assertWithinTheMonth($month, $dates);
        $this->assertInOrder($dates);

        return DB::transaction(function () use ($month, $dates): CycleMonth {
            $month->fill($dates)->save();

            return $month->refresh();
        });
    }

    /**
     * Puts a month back on the constitution's own dates: declarations from 08:00 on the
     * 1st to the end of the 3rd, trading from the 4th to the weekend-adjusted 7th.
     */
    public function resetToConstitution(CycleMonth $month): CycleMonth
    {
        $dates = $this->planner->datesFor($month->month, $month->cycle->weekend_trading_policy);

        return $this->reschedule(
            $month,
            $dates['declarations_open_at'],
            $dates['declarations_close_at'],
            $dates['trading_starts_on'],
            $dates['trading_concludes_on'],
            $dates['disbursement_on'],
        );
    }

    /** Whether this month's dates may still be moved at all. */
    public function isReschedulable(CycleMonth $month): bool
    {
        return $month->status !== CycleMonthStatus::Closed
            && in_array($month->cycle->status, [CycleStatus::Draft, CycleStatus::Active], true);
    }

    /**
     * A concluded month posted its savings, repayments, interest and penalties against
     * these dates. Re-dating it would leave the ledgers describing a day that never was.
     */
    protected function assertReschedulable(CycleMonth $month): void
    {
        if ($month->status === CycleMonthStatus::Closed) {
            throw DomainRuleException::make(
                "{$month->label()} has been traded and closed. Its dates are the record of a session "
                    .'that happened, so they can no longer be moved.'
            );
        }

        if (! in_array($month->cycle->status, [CycleStatus::Draft, CycleStatus::Active], true)) {
            throw DomainRuleException::make(
                'The cycle is '.strtolower($month->cycle->status->label())
                    .', so its calendar is closed to changes.'
            );
        }
    }

    /**
     * @param  array<string, CarbonInterface>  $dates
     */
    protected function assertWithinTheMonth(CycleMonth $month, array $dates): void
    {
        $labels = [
            'declarations_open_at' => 'Declarations open',
            'declarations_close_at' => 'Declarations close',
            'trading_starts_on' => 'Trading opens',
            'trading_concludes_on' => 'Trading concludes',
            'disbursement_on' => 'Disbursement',
        ];

        foreach ($dates as $field => $date) {
            if (! $date->isSameMonth($month->month)) {
                throw DomainRuleException::make(
                    "{$labels[$field]} must fall inside {$month->label()}. A window that runs past its own "
                        .'month would leave the portal showing one month while another is still taking '
                        .'declarations.'
                );
            }
        }
    }

    /**
     * @param  array<string, CarbonInterface>  $dates
     */
    protected function assertInOrder(array $dates): void
    {
        if ($dates['declarations_open_at']->greaterThanOrEqualTo($dates['declarations_close_at'])) {
            throw DomainRuleException::make('Declarations have to close after they open.');
        }

        if ($dates['declarations_close_at']->greaterThan($dates['trading_starts_on'])) {
            throw DomainRuleException::make(
                'Declarations have to close before the trading day opens: the session is built from the '
                    .'figures on the sheet, and they cannot move underneath it.'
            );
        }

        if ($dates['trading_starts_on']->greaterThan($dates['trading_concludes_on'])) {
            throw DomainRuleException::make('Trading cannot conclude before it opens.');
        }

        if (! $dates['disbursement_on']->betweenIncluded($dates['trading_starts_on'], $dates['trading_concludes_on'])) {
            throw DomainRuleException::make('Disbursement has to fall on one of the trading days.');
        }
    }
}
