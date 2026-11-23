<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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
        // Production (Vercel) এ সব লিংক https দিয়ে তৈরি হবে।
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }

        // General API limit: 60 requests/minute per user (or per IP for guests).
        RateLimiter::for('krishihat-api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Login/register: 5 attempts/minute per email + IP (blocks password guessing).
        RateLimiter::for('krishihat-auth', function (Request $request) {
            return Limit::perMinute(5)->by(strtolower((string) $request->input('email')) . '|' . $request->ip());
        });

        // Website login form: same protection.
        RateLimiter::for('krishihat-web-login', function (Request $request) {
            return Limit::perMinute(10)->by(strtolower((string) $request->input('email')) . '|' . $request->ip());
        });
    }
}