<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('resolver:refresh')->hourly();
Schedule::command('resolver:sync-lists')->everyMinute();
Schedule::command('resolver:probe-idle-check')->everyMinute();
Schedule::command('resolver:auto-ping')->everyMinute();
