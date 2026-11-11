<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ServerController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : redirect()->route('login'));

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login.store');
});

Route::middleware(['auth', 'admin'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::resource('servers', ServerController::class)->only(['index', 'create', 'store', 'show']);
    Route::get('/servers/{server}/enrollment', [ServerController::class, 'enrollment'])->name('servers.enrollment');
    Route::post('/servers/{server}/enrollment/reissue', [ServerController::class, 'reissueEnrollment'])
        ->middleware('throttle:5,1')
        ->name('servers.enrollment.reissue');
    Route::get('/servers/{server}/metrics', [ServerController::class, 'metrics'])->name('servers.metrics');
    Route::post('/servers/{server}/disable', [ServerController::class, 'disable'])->name('servers.disable');
});
