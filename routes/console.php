<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('telegram:send-birthdays')
    ->hourly()
    ->withoutOverlapping();
Schedule::command('trees:backup')
    ->dailyAt('02:30')
    ->withoutOverlapping();
Schedule::command('platform:monitor')
    ->dailyAt('08:00')
    ->withoutOverlapping();
