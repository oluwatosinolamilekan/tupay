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
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
        ]);

        RateLimiter::for('finance', fn (Request $request) => [
            Limit::perMinute(30)->by(optional($request->user())->id ?: $request->ip()),
        ]);

        RateLimiter::for('webhooks', fn (Request $request) => [
            Limit::perMinute(120)->by($request->ip()),
        ]);
    }
}
