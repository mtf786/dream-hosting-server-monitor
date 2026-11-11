<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('monitor:mark-offline')->everyMinute()->withoutOverlapping();
Schedule::command('monitor:prune-metrics')->dailyAt('02:15')->withoutOverlapping();
