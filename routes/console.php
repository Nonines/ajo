<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('payments:recover')->everyMinute()->withoutOverlapping(2)->onOneServer();
Schedule::command('savings:process')->dailyAt('00:05')->timezone('Africa/Lagos')->withoutOverlapping(2)->onOneServer();
Schedule::command('savings:recover')->everyMinute()->withoutOverlapping(2)->onOneServer();
