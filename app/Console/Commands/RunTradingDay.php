<?php

namespace App\Console\Commands;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Loans\InterestEngine;
use App\Domain\Loans\PenaltyService;
use App\Domain\Savings\MemberBalanceCalculator;
use App\Models\Cycle;
use App\Models\CycleMonth;
use Illuminate\Console\Command;

/**
 * The trading-day job: closes last month's installments and charges this month's interest.
 *
 * Order matters. The month that has just ended is closed first, so any missed or partly
 * paid installment carries its 10% penalty into the balance before the new month's
 * interest is worked out on it. Both steps are idempotent, so a job that runs twice —
 * or is run by hand after a power cut — changes nothing the second time.
 */
class RunTradingDay extends Command
{
    protected $signature = 'unity:run-trading-day
        {--cycle= : Cycle id to run, defaulting to the current cycle}
        {--month= : Month sequence to charge interest for, defaulting to the current month}';

    protected $description = 'Close the previous month and post this month\'s loan interest';

    public function handle(
        CurrentCycle $currentCycle,
        InterestEngine $interest,
        PenaltyService $penalties,
        MemberBalanceCalculator $balances,
    ): int {
        $cycle = $this->option('cycle') !== null
            ? Cycle::find($this->option('cycle'))
            : $currentCycle->get();

        if (! $cycle instanceof Cycle) {
            $this->components->error('No cycle to run. Pass --cycle or activate one.');

            return self::FAILURE;
        }

        $currentCycle->set($cycle);

        $month = $this->option('month') !== null
            ? $cycle->monthAt((int) $this->option('month'))
            : $cycle->monthFor(now());

        if (! $month instanceof CycleMonth) {
            $this->components->error('That month is not part of this cycle.');

            return self::FAILURE;
        }

        $previous = $cycle->monthAt($month->sequence - 1);

        if ($previous !== null) {
            $closed = $penalties->closeMonth($previous);
            $this->components->info("Closed {$previous->label()}: {$closed->count()} missed-installment penalt(ies) charged.");
        }

        $charges = $interest->postForMonth($month);
        $this->components->info("Charged interest on {$charges->count()} loan(s) for {$month->label()}.");

        $balances->rebuildMonth($month);
        $this->components->info("Rebuilt the member snapshots for {$month->label()}.");

        return self::SUCCESS;
    }
}
