<?php

namespace App\Console\Commands;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Payments\Reconciler;
use App\Models\Cycle;
use App\Support\Kwacha;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The daily comparison of the provider's record against the group's.
 *
 * Writes one row a day. A clean run is a row with nothing in `unmatched`, which is
 * itself worth having — "we checked and it agreed" is the answer the group needs at
 * share-out, not the absence of an alarm.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'unity:reconcile-payments
        {--days=1 : How many days back to compare}
        {--cycle= : Cycle id to run against, defaulting to the current cycle}';

    protected $description = 'Compare the payment provider\'s record of money moved against this system\'s';

    public function handle(CurrentCycle $currentCycle, Reconciler $reconciler): int
    {
        $cycle = $this->option('cycle') !== null
            ? Cycle::find($this->option('cycle'))
            : $currentCycle->get();

        if (! $cycle instanceof Cycle) {
            $this->components->error('No cycle to reconcile against. Pass --cycle or activate one.');

            return self::FAILURE;
        }

        $to = Carbon::today();
        $from = $to->copy()->subDays(max(0, (int) $this->option('days')));

        $result = $reconciler->run($cycle, $from, $to);

        $this->components->info(sprintf(
            '%s to %s: %s in, %s out, %d item(s) needing a look.',
            $from->toDateString(),
            $to->toDateString(),
            Kwacha::format($result->collections_ngwee),
            Kwacha::format($result->transfers_ngwee),
            $result->unmatched_count,
        ));

        foreach ($result->unmatched ?? [] as $item) {
            $this->components->warn(($item['reference'] ?? '—').': '.($item['reason'] ?? ''));
        }

        return self::SUCCESS;
    }
}
