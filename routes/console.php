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
