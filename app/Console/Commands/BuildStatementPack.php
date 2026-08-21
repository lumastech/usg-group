<?php

namespace App\Console\Commands;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Reporting\MonthlyStatementPack;
use App\Models\Cycle;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Builds one month's full reporting pack.
 *
 * The same service the Reports hub button calls, so the scheduled build and the
 * treasurer's own click leave identical files in identical places — which is what lets
 * the monthly mail-out read a manifest rather than re-render anything.
 */
class BuildStatementPack extends Command
{
    protected $signature = 'unity:statement-pack
        {--cycle= : Cycle id, defaulting to the current cycle}
        {--month= : Cycle month sequence, defaulting to the month containing today}
        {--disk=local : Filesystem disk to write the pack to}';

    protected $description = "Render a month's savings, loans, fund and declaration sheets plus every member statement";

    public function handle(CurrentCycle $currentCycle, MonthlyStatementPack $pack): int
    {
        $cycle = $this->option('cycle') !== null
            ? Cycle::find($this->option('cycle'))
            : $currentCycle->get();

        if (! $cycle instanceof Cycle) {
            $this->components->error('No cycle to report on. Pass --cycle or activate one.');

            return self::FAILURE;
        }

        $currentCycle->set($cycle);

        $month = $this->option('month') !== null
            ? $cycle->monthAt((int) $this->option('month'))
            : $cycle->monthFor(Carbon::today());

        if ($month === null) {
            $this->components->error('That cycle has no such month.');

            return self::FAILURE;
        }

        $manifest = $pack->build($cycle, $month, (string) $this->option('disk'));

        $this->components->twoColumnDetail('Month', $manifest['month_label']);
        $this->components->twoColumnDetail('Files', (string) count($manifest['files']));
        $this->components->twoColumnDetail('Written to', $manifest['disk'].':'.$manifest['directory']);
        $this->components->info('Statement pack built.');

        return self::SUCCESS;
    }
}
