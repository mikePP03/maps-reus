<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        RateLimiter::for('reus-provider', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('reus-stop-arrivals', fn (Request $request) => Limit::perMinute(15)->by($request->ip()));
        RateLimiter::for('reus-places', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('reus-walk', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }
}
