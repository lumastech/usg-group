<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| The month's rhythm
|--------------------------------------------------------------------------
|
| Declarations close at the end of the 3rd, so the sheet is laid out on the
| morning of the 4th whether or not a treasurer logs in that day. Opening a
| session is idempotent, so the daily run through the trading days simply keeps
| it in step with any declaration captured late.
|
*/

Schedule::command('unity:open-trading-sessions')
    ->dailyAt('06:00')
    ->timezone('Africa/Lusaka');

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
|
| One daily pass at 08:00, the hour the constitution opens the declaration
| window. Every rule — the window opening, the reminder, trading day, the
| repayment warning, the lockdown and the final-deadline countdown — reads the
| same weekend-adjusted cycle_months rows, so they cannot drift apart. The run
| claims each batch before sending, so re-running it sends nothing twice.
|
*/

Schedule::command('unity:notify')
    ->dailyAt('08:00')
    ->timezone('Africa/Lusaka')
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Backups
|--------------------------------------------------------------------------
|
| A nightly dump, kept for the configured retention. See docs/STORAGE.md for
| where it lands and how to restore from it.
|
*/

Schedule::command('unity:backup-database')
    ->dailyAt('01:30')
    ->timezone('Africa/Lusaka')
    ->withoutOverlapping();
