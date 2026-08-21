<?php

namespace App\Console\Commands;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Notifications\CycleNotificationScheduler;
use App\Models\Cycle;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The day's notifications, resolved from the cycle calendar.
 *
 * Scheduled once a day at 08:00 — the hour the constitution opens the declaration
 * window — rather than as six separate schedule entries, because every rule keys off
 * the same cycle_months rows and drift between them is the failure nobody would
 * notice until a member complained. Re-running it is safe: each batch is claimed in
 * notification_dispatches before it goes out.
 */
class SendCycleNotifications extends Command
{
    protected $signature = 'unity:notify
        {--cycle= : Cycle id, defaulting to the current cycle}
        {--date= : The date to resolve notifications for, defaulting to today}
        {--pretend : List what would be sent without sending it}';

    protected $description = 'Send the notifications the cycle calendar owes for a given day';

    public function handle(CurrentCycle $currentCycle, CycleNotificationScheduler $scheduler): int
    {
        $cycle = $this->option('cycle') !== null
            ? Cycle::find($this->option('cycle'))
            : $currentCycle->get();

        if (! $cycle instanceof Cycle) {
            $this->components->warn('No active cycle — nothing to notify about.');

            return self::SUCCESS;
        }

        $currentCycle->set($cycle);

        $date = $this->option('date') !== null
            ? Carbon::parse((string) $this->option('date'))
            : Carbon::today();

        if ($this->option('pretend')) {
            return $this->pretend($cycle, $date);
        }

        $sent = $scheduler->run($cycle, $date);

        if ($sent === []) {
            $this->components->info('Nothing due on '.$date->toDateString().'.');

            return self::SUCCESS;
        }

        foreach ($sent as $rule => $recipients) {
            $this->components->twoColumnDetail($rule, $recipients.' notified');
        }

        $this->components->info('Sent '.array_sum($sent).' notifications for '.$date->toDateString().'.');

        return self::SUCCESS;
    }

    /**
     * Show which rules match the date without sending anything.
     *
     * Uses Laravel's notification fake so the run is a genuine dry run — the rules
     * are really evaluated, the recipients are really resolved, nothing leaves the
     * building. The dispatch guard is rolled back so a pretend run does not silence
     * the real one later in the day.
     */
    protected function pretend(Cycle $cycle, Carbon $date): int
    {
        Notification::fake();

        DB::beginTransaction();

        try {
            $sent = app(CycleNotificationScheduler::class)->run($cycle, $date);
        } finally {
            DB::rollBack();
        }

        $this->components->info('Pretending for '.$date->toDateString().':');

        if ($sent === []) {
            $this->components->twoColumnDetail('nothing due', '0');
        }

        foreach ($sent as $rule => $recipients) {
            $this->components->twoColumnDetail($rule, $recipients.' would be notified');
        }

        return self::SUCCESS;
    }
}
