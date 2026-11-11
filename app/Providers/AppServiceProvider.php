<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(
                Str::lower($request->string('email')->toString()).'|'.$request->ip(),
            );
        });

        RateLimiter::for('agent-enrollment', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('agent-ingest', fn (Request $request): Limit => Limit::perMinute(120)->by(
            $request->bearerToken() ? hash('sha256', $request->bearerToken()) : $request->ip(),
        ));
    }
}
