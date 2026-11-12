<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class ConsoleSchedulingTest extends TestCase
{
    public function test_due_monitoring_schedules_call_their_commands_in_process(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12 02:15:00', config('app.timezone')));

        $dueEvents = collect(Schedule::events())
            ->filter(fn (CallbackEvent $event): bool => $event->isDue($this->app))
            ->keyBy('description');

        $markOfflineEvent = $dueEvents->get('monitor:mark-offline');
        $pruneMetricsEvent = $dueEvents->get('monitor:prune-metrics');

        $this->assertInstanceOf(CallbackEvent::class, $markOfflineEvent);
        $this->assertInstanceOf(CallbackEvent::class, $pruneMetricsEvent);

        Artisan::shouldReceive('call')
            ->once()
            ->with('monitor:mark-offline')
            ->andReturn(0);

        Artisan::shouldReceive('call')
            ->once()
            ->with('monitor:prune-metrics')
            ->andReturn(0);

        $markOfflineEvent->run($this->app);
        $pruneMetricsEvent->run($this->app);
    }
}
