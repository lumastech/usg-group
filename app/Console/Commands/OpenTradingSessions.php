<?php

namespace App\Console\Commands;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Declarations\DeclarationWindow;
use App\Domain\Trading\TradingSessionService;
use App\Models\Cycle;
use App\Models\CycleMonth;
use Illuminate\Console\Command;

/**
 * Opens the trading session for any month whose declaration window has closed.
 *
 * The console opens a session lazily when a treasurer first visits it, which covers
 * the normal day. This exists so the sheet is laid out whether or not anybody logs in
 * on the 4th — and so declarations are locked at the moment the window shuts rather
 * than at the moment somebody happens to look.
 *
 * Idempotent: a month that already has a session is re-synced, never re-opened.
 */
class OpenTradingSessions extends Command
{
    protected $signature = 'unity:open-trading-sessions
        {--cycle= : Cycle id to run, defaulting to the current cycle}';

    protected $description = 'Open the trading session for every month whose declaration window has closed';

    public function handle(
        CurrentCycle $currentCycle,
        TradingSessionService $sessions,
        DeclarationWindow $window,
    ): int {
        $cycle = $this->option('cycle') !== null
            ? Cycle::find($this->option('cycle'))
            : $currentCycle->get();

        if (! $cycle instanceof Cycle) {
            $this->components->error('No cycle to run. Pass --cycle or activate one.');

            return self::FAILURE;
        }

        $currentCycle->set($cycle);

        $opened = 0;

        foreach ($cycle->months as $month) {
            if (! $this->isDue($month, $window)) {
                continue;
            }

            $session = $sessions->openFor($month);
            $opened++;

            $this->components->info(
                "{$month->label()}: session #{$session->id} is ".$session->status->label()
                    ." with {$session->entries()->count()} entr(ies)."
            );
        }

        if ($opened === 0) {
            $this->components->info('No month has a window that has closed and is still within its trading days.');
        }

        return self::SUCCESS;
    }

    /**
     * A month is due once its window has shut and while its trading days are running.
     *
     * Months further back are left alone: re-syncing a long-past sheet would pull in
     * loans approved since, which belong to a later trading day.
     */
    protected function isDue(CycleMonth $month, DeclarationWindow $window): bool
    {
        return $window->isLate($month) && $window->isTrading($month);
    }
}
