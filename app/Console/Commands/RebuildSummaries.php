<?php

namespace App\Console\Commands;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Savings\MemberBalanceCalculator;
use App\Models\Cycle;
use App\Models\CycleMonth;
use Illuminate\Console\Command;

/**
 * Recomputes the cached member/month snapshots from the ledgers.
 *
 * Nothing in member_month_balances is authoritative — every column is derived from
 * the savings transactions, interest allocations and the loan side — so this command
 * is the repair tool for the cache: safe to run at any time, and running it twice
 * leaves the same numbers behind.
 */
class RebuildSummaries extends Command
{
    protected $signature = 'unity:rebuild-summaries
        {--cycle= : Cycle id to rebuild, defaulting to the current cycle}
        {--month= : Rebuild only this month sequence (1-12)}';

    protected $description = 'Rebuild the cached member monthly summaries from the ledgers';

    public function handle(CurrentCycle $currentCycle, MemberBalanceCalculator $balances): int
    {
        $cycle = $this->option('cycle') !== null
            ? Cycle::find($this->option('cycle'))
            : $currentCycle->get();

        if (! $cycle instanceof Cycle) {
            $this->components->error('No cycle to rebuild. Pass --cycle or activate one.');

            return self::FAILURE;
        }

        // Pin it: months, members and ledger entries are all cycle-scoped, so rebuilding
        // a cycle other than the active one only reads the right rows once it is current.
        $currentCycle->set($cycle);

        $months = $cycle->months()
            ->when($this->option('month') !== null, fn ($query) => $query->where('sequence', (int) $this->option('month')))
            ->get();

        if ($months->isEmpty()) {
            $this->components->error("No months planned for {$cycle->name}. Run the cycle planner first.");

            return self::FAILURE;
        }

        $memberCount = $cycle->members()->count();
        $rebuilt = 0;

        $this->components->info("Rebuilding {$months->count()} month(s) for {$cycle->name} across {$memberCount} member(s).");

        $this->withProgressBar($months, function (CycleMonth $month) use ($balances, &$rebuilt): void {
            $rebuilt += $balances->rebuildMonth($month)->count();
        });

        $this->newLine(2);
        $this->components->info("Rebuilt {$rebuilt} member month summaries.");

        return self::SUCCESS;
    }
}
