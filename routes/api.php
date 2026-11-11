<?php

use App\Http\Controllers\AgentEnrollmentController;
use App\Http\Controllers\AgentHeartbeatController;
use App\Http\Controllers\AgentMetricsController;
use Illuminate\Support\Facades\Route;

Route::post('/v1/agent/enroll', [AgentEnrollmentController::class, 'store'])
    ->middleware('throttle:agent-enrollment')
    ->name('api.v1.agent.enroll');

Route::middleware(['agent.auth', 'throttle:agent-ingest'])
    ->prefix('/v1/agent')
    ->name('api.v1.agent.')
    ->group(function (): void {
        Route::post('/heartbeat', [AgentHeartbeatController::class, 'store'])->name('heartbeat');
        Route::post('/metrics', [AgentMetricsController::class, 'store'])->name('metrics');
    });
