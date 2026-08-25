<?php

namespace App\Console\Commands;

use App\Domain\Cycles\CurrentCycle;
use App\Enums\CycleMonthStatus;
use App\Models\Cycle;
use App\Models\CycleMonth;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Moves the calendar, not the rules, so a whole month can be walked through today.
 *
 * The constitution's windows are dates on `cycles` and `cycle_months`, and every guard
 * in the application reads them through DeclarationWindow and the cycle's own helpers.
 * That means a dry run needs no flag inside the domain and no exception for testers:
 * widen the dates and the real code paths open by themselves, with the real validation,
 * the real policies and the real ledgers still doing their jobs.
 *
 * Everything it overwrites is snapshotted first, so `--close` puts the cycle back
 * exactly as the constitution had it. Never leave a system open after a dry run — the
 * registration window in particular is a rule the group voted on.
 */
class OpenForTesting extends Command
{
    /** Where the pre-change values are parked until `--close` puts them back. */
    public const SNAPSHOT = 'unity-testing-window.json';

    protected $signature = 'unity:open-for-testing
        {--close : Restore the constitution\'s dates from the snapshot}
        {--phase=declarations : Which window to open — declarations or trading}
        {--days=7 : How long the opened window should stay open}
        {--month= : Cycle month sequence to open, defaulting to the month containing today}
        {--cycle= : Cycle id to act on, defaulting to the current cycle}';

    protected $description = 'Temporarily open registration and the month windows so the cycle can be tested end to end';

    public function handle(CurrentCycle $currentCycle): int
    {
        $cycle = $this->option('cycle') !== null
            ? Cycle::find((int) $this->option('cycle'))
            : $currentCycle->get();

        if (! $cycle instanceof Cycle) {
            $this->components->error('No cycle to act on. Pass --cycle or activate one.');

            return self::FAILURE;
        }

        return $this->option('close') ? $this->close($cycle) : $this->open($cycle);
    }

    /**
     * Widens the registration and month windows to cover today.
     */
    protected function open(Cycle $cycle): int
    {
        $phase = (string) $this->option('phase');

        if (! in_array($phase, ['declarations', 'trading'], true)) {
            $this->components->error("Unknown phase [{$phase}]. Use declarations or trading.");

            return self::FAILURE;
        }

        $month = $this->month($cycle);

        if (! $month instanceof CycleMonth) {
            $this->components->error('Today falls outside the cycle. Pass --month with a sequence number.');

            return self::FAILURE;
        }

        if (! $this->snapshotExists()) {
            $this->writeSnapshot($cycle, $month);
        } else {
            $this->components->warn('A snapshot is already parked — the original dates are still the ones held from the first run.');
        }

        $dates = $phase === 'declarations'
            ? $this->declarationDates()
            : $this->tradingDates();

        DB::transaction(function () use ($cycle, $month, $dates, $phase): void {
            $cycle->forceFill([
                'registration_closes_after_month' => $cycle->months()->max('sequence') ?: 12,
            ])->save();

            $month->forceFill($dates + [
                'status' => $phase === 'declarations'
                    ? CycleMonthStatus::DeclarationsOpen
                    : CycleMonthStatus::Trading,
            ])->save();
        });

        $this->components->twoColumnDetail('Cycle', $cycle->name);
        $this->components->twoColumnDetail('Month', $month->label()." (sequence {$month->sequence})");
        $this->components->twoColumnDetail('Registration', "open through month {$cycle->registration_closes_after_month}");
        $this->components->twoColumnDetail('Declarations', $month->declarations_open_at->toDateTimeString().' — '.$month->declarations_close_at->toDateTimeString());
        $this->components->twoColumnDetail('Trading', $month->trading_starts_on->toDateString().' — '.$month->trading_concludes_on->toDateString());
        $this->components->twoColumnDetail('Phase', $phase);

        $this->components->info('Open for testing. Run `php artisan unity:open-for-testing --close` to put the constitution back.');

        return self::SUCCESS;
    }

    /**
     * Puts back exactly what was there, and refuses to guess when nothing was saved.
     *
     * Rebuilding the dates from CycleMonthPlanner would be close enough to look right
     * and would also reset every month's status to pending, quietly undoing months the
     * group has already traded.
     */
    protected function close(Cycle $cycle): int
    {
        if (! $this->snapshotExists()) {
            $this->components->error('No snapshot to restore from — nothing was opened by this command.');

            return self::FAILURE;
        }

        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode((string) Storage::disk('local')->get(self::SNAPSHOT), true);

        $month = CycleMonth::query()
            ->withoutGlobalScopes()
            ->find($snapshot['month']['id']);

        if (! $month instanceof CycleMonth) {
            $this->components->error('The month held in the snapshot no longer exists.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($cycle, $month, $snapshot): void {
            $cycle->forceFill([
                'registration_closes_after_month' => $snapshot['cycle']['registration_closes_after_month'],
            ])->save();

            $month->forceFill([
                'declarations_open_at' => $snapshot['month']['declarations_open_at'],
                'declarations_close_at' => $snapshot['month']['declarations_close_at'],
                'trading_starts_on' => $snapshot['month']['trading_starts_on'],
                'trading_concludes_on' => $snapshot['month']['trading_concludes_on'],
                'disbursement_on' => $snapshot['month']['disbursement_on'],
                'status' => $snapshot['month']['status'],
            ])->save();
        });

        Storage::disk('local')->delete(self::SNAPSHOT);

        $this->components->twoColumnDetail('Cycle', $cycle->name);
        $this->components->twoColumnDetail('Month', $month->label());
        $this->components->twoColumnDetail('Registration', "closes after month {$cycle->registration_closes_after_month}");
        $this->components->info('The constitution\'s dates are back. Unset UNITY_OPEN_REGISTRATION as well if it was set.');

        return self::SUCCESS;
    }

    /**
     * Today, plus however long the window should stay open.
     *
     * Trading is pushed out past the declaration close so the month still reads in the
     * constitution's order — declare first, trade after — rather than both at once.
     *
     * @return array<string, mixed>
     */
    protected function declarationDates(): array
    {
        $closes = Carbon::today()->addDays($this->days())->endOfDay();

        return [
            'declarations_open_at' => Carbon::today()->setTime(8, 0),
            'declarations_close_at' => $closes,
            'trading_starts_on' => $closes->copy()->addDay()->startOfDay(),
            'trading_concludes_on' => $closes->copy()->addDays(4)->startOfDay(),
            'disbursement_on' => $closes->copy()->addDays(4)->startOfDay(),
        ];
    }

    /**
     * Trading open now, with the declaration window shut behind it.
     *
     * @return array<string, mixed>
     */
    protected function tradingDates(): array
    {
        return [
            'declarations_open_at' => Carbon::today()->subDays(3)->setTime(8, 0),
            'declarations_close_at' => Carbon::yesterday()->endOfDay(),
            'trading_starts_on' => Carbon::today(),
            'trading_concludes_on' => Carbon::today()->addDays($this->days()),
            'disbursement_on' => Carbon::today()->addDays($this->days()),
        ];
    }

    protected function days(): int
    {
        return max(1, (int) $this->option('days'));
    }

    protected function month(Cycle $cycle): ?CycleMonth
    {
        if ($this->option('month') !== null) {
            return $cycle->months()->where('sequence', (int) $this->option('month'))->first();
        }

        return $cycle->monthFor(Carbon::now());
    }

    protected function snapshotExists(): bool
    {
        return Storage::disk('local')->exists(self::SNAPSHOT);
    }

    protected function writeSnapshot(Cycle $cycle, CycleMonth $month): void
    {
        Storage::disk('local')->put(self::SNAPSHOT, json_encode([
            'taken_at' => Carbon::now()->toIso8601String(),
            'cycle' => [
                'id' => $cycle->id,
                'registration_closes_after_month' => $cycle->registration_closes_after_month,
            ],
            'month' => [
                'id' => $month->id,
                'sequence' => $month->sequence,
                'declarations_open_at' => $month->declarations_open_at->toDateTimeString(),
                'declarations_close_at' => $month->declarations_close_at->toDateTimeString(),
                'trading_starts_on' => $month->trading_starts_on->toDateString(),
                'trading_concludes_on' => $month->trading_concludes_on->toDateString(),
                'disbursement_on' => $month->disbursement_on->toDateString(),
                'status' => $month->status->value,
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
