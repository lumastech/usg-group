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

/*
|--------------------------------------------------------------------------
| Payments
|--------------------------------------------------------------------------
|
| Webhooks are the fast path and not the reliable one — the provider's own
| documentation says to re-query — so every payment still in flight is asked
| about on a short cycle. The same pass takes up money the ledgers could not
| accept when it arrived: savings paid outside a trading window wait at Settled
| and go onto the sheet the first run after a session opens.
|
*/

Schedule::command('unity:poll-payments')
    ->everyFiveMinutes()
    ->timezone('Africa/Lusaka')
    ->withoutOverlapping();

/*
| The daily comparison of the provider's record against ours. Runs after the
| night's collections have settled, so a payment made late on the 7th is judged
| against a settled balance rather than an in-flight one.
*/

Schedule::command('unity:reconcile-payments')
    ->dailyAt('02:30')
    ->timezone('Africa/Lusaka')
    ->withoutOverlapping();

/*
| The wallet float, checked every morning against money the group actually holds.
|
| Runs after the payment reconciliation so both figures come from the same settled
| picture. A mismatch exits non-zero: this is the only check that catches a wallet
| credited with nothing behind it, and it has to be alarmed on rather than filed.
*/

Schedule::command('unity:reconcile-wallets')
    ->dailyAt('02:45')
    ->timezone('Africa/Lusaka')
    ->withoutOverlapping();
