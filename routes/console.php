<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$runMonitoringCommand = static function (string $command): void {
    $exitCode = Artisan::call($command);

    if ($exitCode !== 0) {
        throw new RuntimeException("Scheduled monitor command {$command} failed with exit code {$exitCode}.");
    }
};

Schedule::call(static function () use ($runMonitoringCommand): void {
    $runMonitoringCommand('monitor:mark-offline');
})->name('monitor:mark-offline')->everyMinute()->withoutOverlapping();

Schedule::call(static function () use ($runMonitoringCommand): void {
    $runMonitoringCommand('monitor:prune-metrics');
})->name('monitor:prune-metrics')->dailyAt('02:15')->withoutOverlapping();
