<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (app()->environment('demo')) {
    Schedule::command('demo:process')->everyMinute()->withoutOverlapping(2)->onOneServer();

    return;
}

Schedule::command('horizon:snapshot')->everyFiveMinutes();
// Redispatch persisted due work so queue outages do not discard financial processing intent.
Schedule::command('payments:recover')->everyMinute()->withoutOverlapping(2)->onOneServer();
Schedule::command('withdrawals:recover')->everyMinute()->withoutOverlapping(2)->onOneServer();
Schedule::command('savings:process')->dailyAt('00:05')->withoutOverlapping(2)->onOneServer();
Schedule::command('savings:recover')->everyMinute()->withoutOverlapping(2)->onOneServer();
